<?php
/**
 * Mempool Processing Script
 * 
 * Processes raw transaction queue into mempool for blockchain processing.
 * Reads from storage/raw_mempool and adds validated transactions to database mempool.
 * 
 * Usage: php scripts/process_mempool.php [--max=50] [--dry] [--verbose] [--clean]
 * 
 * Options:
 *   --max=N     Maximum number of files to process (default: 50)
 *   --dry       Dry run mode - process but don't modify database
 *   --verbose   Enable verbose output
 *   --clean     Remove processed files after successful database insertion
 * 
 * Environment:
 *   This script reads database configuration from config/config.php
 *   and supports .env file overrides via EnvironmentLoader
 */

use Blockchain\Core\Transaction\MempoolManager;
use Blockchain\Core\Transaction\Transaction;
use Blockchain\Core\Cryptography\Hash;

require_once __DIR__ . '/../vendor/autoload.php';

// Determine project base directory
$baseDir = dirname(__DIR__);

// Load environment variables
require_once $baseDir . '/core/Environment/EnvironmentLoader.php';
\Blockchain\Core\Environment\EnvironmentLoader::load($baseDir);

// Load config
$configFile = $baseDir . '/config/config.php';
$config = [];
if (file_exists($configFile)) {
    $config = require $configFile;
}

$opts = [
    'max' => 50,
    'dry' => false,
    'verbose' => false,
    'clean' => false,
];
foreach ($argv as $arg) {
    if (preg_match('/^--max=(\d+)$/', $arg, $m)) $opts['max'] = (int)$m[1];
    elseif ($arg === '--dry') $opts['dry'] = true;
    elseif ($arg === '--verbose') $opts['verbose'] = true;
    elseif ($arg === '--clean') $opts['clean'] = true;
}

function out($msg, $force = false) {
    global $opts; if ($opts['verbose'] || $force) echo $msg . "\n"; 
}

function logMessage($msg, $level = 'INFO') {
    echo "[" . date('Y-m-d H:i:s') . "] [$level] $msg\n";
}

try {
    // Build database config with priority: config.php -> .env -> defaults
    $dbConfig = $config['database'] ?? [];
    
    // If empty, fallback to environment variables
    if (empty($dbConfig) || !isset($dbConfig['host'])) {
        $dbConfig = [
            'host' => \Blockchain\Core\Environment\EnvironmentLoader::get('DB_HOST', 'localhost'),
            'port' => (int)\Blockchain\Core\Environment\EnvironmentLoader::get('DB_PORT', 3306),
            'database' => \Blockchain\Core\Environment\EnvironmentLoader::get('DB_DATABASE', 'blockchain'),
            'username' => \Blockchain\Core\Environment\EnvironmentLoader::get('DB_USERNAME', 'root'),
            'password' => \Blockchain\Core\Environment\EnvironmentLoader::get('DB_PASSWORD', ''),
            'charset' => 'utf8mb4'
        ];
    }
    
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', 
        $dbConfig['host'], 
        $dbConfig['database']
    );
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $mempool = new MempoolManager($pdo, ['min_fee' => 0.001]);

    // Get raw transaction queue directory
    $queueDir = $baseDir . '/storage/raw_mempool';
    $processedDir = $queueDir . '/processed';
    
    if (!is_dir($queueDir)) {
        logMessage("Queue directory does not exist: $queueDir", 'ERROR');
        exit(1);
    }
    
    // Ensure processed directory exists
    if (!is_dir($processedDir)) {
        @mkdir($processedDir, 0755, true);
    }

    // Get queue files
    $files = glob($queueDir . '/*.json');
    if (empty($files)) {
        out('No raw transactions in queue', true);
        exit(0);
    }

    // Sort by creation time (oldest first)
    usort($files, function($a, $b) {
        return filemtime($a) - filemtime($b);
    });

    $processed = 0;
    $errors = 0;
    $skipped = 0;

    logMessage("Found " . count($files) . " raw transactions in queue", 'INFO');

    foreach ($files as $file) {
        if ($processed >= $opts['max']) {
            out("Reached maximum processing limit ({$opts['max']})", true);
            break;
        }

        $basename = basename($file);
        out("Processing: $basename");

        try {
            $content = file_get_contents($file);
            if ($content === false) {
                logMessage("Failed to read file: $basename", 'ERROR');
                $errors++;
                continue;
            }

            $data = json_decode($content, true);
            if (!$data) {
                logMessage("Invalid JSON in file: $basename", 'ERROR');
                $errors++;
                continue;
            }

            $txHash = $data['hash'] ?? '';
            $rawHex = $data['raw'] ?? '';
            $parsed = $data['parsed'] ?? [];

            if (!$txHash || !$rawHex) {
                logMessage("Missing required fields in file: $basename", 'ERROR');
                $errors++;
                continue;
            }

            // Check if transaction already exists in mempool
            $h = strtolower(trim((string)$txHash));
            $h0 = str_starts_with($h,'0x') ? $h : ('0x'.$h);
            $h1 = str_starts_with($h,'0x') ? substr($h,2) : $h;
            $existing = $pdo->prepare("SELECT COUNT(*) as count FROM mempool WHERE tx_hash = ? OR tx_hash = ?");
            $existing->execute([$h0, $h1]);
            if ($existing->fetchColumn() > 0) {
                out("Transaction already in mempool: $txHash");
                $skipped++;
                // Move to processed directory
                if ($opts['clean'] && !$opts['dry']) {
                    rename($file, $processedDir . '/' . $basename);
                }
                continue;
            }

            // Extract transaction details from parsed data
            $fromAddress = normalizeAddress($parsed['from'] ?? '0x0000000000000000000000000000000000000000');
            $toAddress = normalizeAddress($parsed['to'] ?? '0x0000000000000000000000000000000000000000');
            
            // Convert value from hex to decimal amount
            $valueHex = $parsed['value'] ?? '0x0';
            $amount = hexToDecimal($valueHex, 18); // 18 decimals for ETH compatibility
            
            $gasLimit = hexToDecimal($parsed['gas'] ?? '0x5208');
            $gasPrice = hexToDecimal($parsed['gasPrice'] ?? $parsed['maxFeePerGas'] ?? '0x0', 18);
            $nonce = hexToDecimal($parsed['nonce'] ?? '0x0');
            
            // Calculate fee (gasLimit * gasPrice)
            $fee = ($gasLimit * $gasPrice) / (10**18); // Convert back to token units

            out("  From: $fromAddress");
            out("  To: $toAddress");
            out("  Amount: $amount");
            out("  Fee: $fee");
            out("  Nonce: $nonce");

            if (!$opts['dry']) {
                // Create transaction object
                $transaction = new Transaction(
                    $fromAddress,
                    $toAddress,
                    (float)$amount,
                    (float)$fee,
                    (int)$nonce,
                    $parsed['input'] ?? null,
                    (int)$gasLimit,
                    (float)$gasPrice
                );

                // Add to mempool with original hash
                $success = $mempool->addTransaction($transaction, $txHash);
                
                if ($success) {
                    logMessage("Added to mempool: $txHash", 'INFO');
                    $processed++;
                    
                    // Move to processed directory if clean option is enabled
                    if ($opts['clean']) {
                        rename($file, $processedDir . '/' . $basename);
                    }
                } else {
                    logMessage("Failed to add to mempool: $txHash", 'ERROR');
                    $errors++;
                }
            } else {
                out("[DRY] Would add to mempool: $txHash");
                $processed++;
            }

        } catch (\Exception $e) {
            logMessage("Error processing $basename: " . $e->getMessage(), 'ERROR');
            $errors++;
        }
    }

    logMessage("Processing complete: $processed processed, $skipped skipped, $errors errors", 'INFO');
    exit($errors > 0 ? 1 : 0);

} catch (\Throwable $e) {
    logMessage('Fatal error: ' . $e->getMessage(), 'ERROR');
    exit(1);
}

/**
 * Normalize Ethereum address to lowercase 0x format
 */
function normalizeAddress(?string $addr): string {
    if (!$addr) return '0x0000000000000000000000000000000000000000';
    $addr = strtolower(trim($addr));
    if (!str_starts_with($addr, '0x')) return '0x0000000000000000000000000000000000000000';
    if (strlen($addr) !== 42) return '0x0000000000000000000000000000000000000000';
    return $addr;
}

/**
 * Convert hex value to decimal with specified decimals
 */
function hexToDecimal(string $hex, int $decimals = 0): float {
    if (!str_starts_with($hex, '0x')) return 0.0;
    $intValue = hexdec(substr($hex, 2));
    return $decimals > 0 ? $intValue / (10 ** $decimals) : (float)$intValue;
}

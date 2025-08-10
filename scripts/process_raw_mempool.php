<?php
// Enhanced worker to process queued raw Ethereum-style transactions and inject them into internal mempool
// English comments only as per project standard.

require_once __DIR__ . '/../vendor/autoload.php';

// Polyfills for older PHP versions (CLI on production might be < 8.0)
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return $needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

use Blockchain\Core\Transaction\Transaction;
use Blockchain\Core\Transaction\MempoolManager;
use Blockchain\Core\Transaction\FeePolicy;
use Blockchain\Core\Crypto\EthereumTx;

// Enhanced logging
function enhancedLog($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message\n";
    echo $logMessage;
    
    // Also write to log file
    $logFile = __DIR__ . '/../logs/mempool_processor.log';
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

enhancedLog("Starting enhanced mempool processor...");

// Basic bootstrap (reuse existing config loader if available)
$baseDir = dirname(__DIR__);
$configFile = $baseDir . '/config/config.php';
$config = file_exists($configFile) ? include $configFile : [];

// Create PDO (respect env vars) without using null coalescing (for older PHP CLI)
$cfgDb = isset($config['db']) && is_array($config['db']) ? $config['db'] : [];
$host = getenv('DB_HOST'); if ($host === false || $host === '') { $host = isset($cfgDb['host']) ? $cfgDb['host'] : '127.0.0.1'; }
$name = getenv('DB_NAME'); if ($name === false || $name === '') { $name = isset($cfgDb['database']) ? $cfgDb['database'] : 'blockchain'; }
$user = getenv('DB_USER'); if ($user === false || $user === '') { $user = isset($cfgDb['username']) ? $cfgDb['username'] : 'root'; }
$pass = getenv('DB_PASSWORD'); if ($pass === false) { $pass = isset($cfgDb['password']) ? $cfgDb['password'] : ''; }
$dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4';

try {
    $pdo = new PDO($dsn, $user, $pass, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ));
    enhancedLog("Database connection established successfully");
} catch (Exception $e) {
    enhancedLog("DB connection failed: " . $e->getMessage(), 'ERROR');
    exit(1);
}

// Initialize components
try {
    $mempool = new MempoolManager($pdo, []);
    enhancedLog("Mempool manager initialized");
} catch (Exception $e) {
    enhancedLog("Failed to initialize mempool manager: " . $e->getMessage(), 'ERROR');
    exit(1);
}

$rawDir = $baseDir . '/storage/raw_mempool';
$processedDir = $rawDir . '/processed';
if (!is_dir($processedDir)) @mkdir($processedDir, 0755, true);

if (!is_dir($rawDir)) {
    enhancedLog("No raw_mempool directory found, creating...");
    @mkdir($rawDir, 0755, true);
}

$files = glob($rawDir . '/*.json');
$now = time();
$rate = 0.0;

try { 
    $rate = FeePolicy::getRate($pdo); 
    enhancedLog("Current fee rate: $rate");
} catch (Exception $e) {
    enhancedLog("Could not get fee rate: " . $e->getMessage(), 'WARNING');
}

enhancedLog("Found " . count($files) . " raw transaction files to process");

$processedCount = 0;
$errorCount = 0;

foreach ($files as $file) {
    if (str_contains($file, '/processed/')) continue;
    
    enhancedLog("Processing file: " . basename($file));
    
    $json = json_decode(@file_get_contents($file), true);
    if (!is_array($json)) {
        enhancedLog("Invalid JSON in file: " . basename($file), 'ERROR');
        rename($file, $processedDir . '/invalid_' . basename($file));
        $errorCount++;
        continue;
    }
    
    $hash = isset($json['hash']) ? $json['hash'] : '';
    $rawHex = isset($json['raw']) ? $json['raw'] : '';
    $parsed = isset($json['parsed']) && is_array($json['parsed']) ? $json['parsed'] : array();
    $age = $now - (isset($json['received_at']) ? $json['received_at'] : $now);

    enhancedLog("Processing transaction hash: $hash (age: ${age}s)");

    // Basic field extraction
    $from = isset($parsed['from']) ? $parsed['from'] : null;
    $to = isset($parsed['to']) ? $parsed['to'] : null;
    $valueHex = isset($parsed['value']) ? $parsed['value'] : '0x0';
    $amount = 0.0;
    
    if (preg_match('/^0x[0-9a-f]+$/', $valueHex)) {
        $amount = (float)hexdec($valueHex);
        // Convert from wei to base units if needed
        $amount = $amount / 1000000000000000000; // 18 decimals
    }

    enhancedLog("Transaction details: from=$from, to=$to, amount=$amount");

    // If 'to' looks not valid length (should be 0x + 40 hex), skip
    if (!is_string($to) || strlen($to) !== 42) {
        enhancedLog("Invalid 'to' address length, skipping: " . basename($file), 'WARNING');
        rename($file, $processedDir . '/invalid_to_' . basename($file));
        $errorCount++;
        continue;
    }

    // Attempt Ethereum address recovery
    if (!$from) {
        try {
            $recovered = EthereumTx::recoverAddress($rawHex);
            if ($recovered) {
                $from = strtolower($recovered);
                enhancedLog("Recovered sender address: $from");
            }
        } catch (Exception $e) {
            enhancedLog("Address recovery failed: " . $e->getMessage(), 'WARNING');
        }
    }
    
    if (!$from || strlen($from) !== 42) {
        enhancedLog("Could not determine sender address, using placeholder", 'WARNING');
        $from = '0x' . str_repeat('0', 40); // placeholder zero address
    }

    // Compute effective gas price for EIP-1559 or legacy
    $gasPriceInt = EthereumTx::effectiveGasPrice(
        isset($parsed['maxPriorityFeePerGas']) ? $parsed['maxPriorityFeePerGas'] : null,
        isset($parsed['maxFeePerGas']) ? $parsed['maxFeePerGas'] : null,
        isset($parsed['gasPrice']) ? $parsed['gasPrice'] : null
    );

    // Create internal transaction object
    try {
        $transaction = new Transaction(
            $from,
            $to,
            $amount,
            $rate, // Use current fee rate
            '', // No data for simple transfers
            time() // Use current timestamp as nonce
        );
        
        enhancedLog("Created internal transaction object");
        
        // Add to mempool
        $success = $mempool->addTransaction($transaction);
        
        if ($success) {
            enhancedLog("Successfully added transaction to mempool: $hash");
            $processedCount++;
            
            // Move to processed directory
            rename($file, $processedDir . '/' . basename($file));
            
            // Log success to database for tracking
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO mempool (hash, from_address, to_address, amount, fee, status, created_at)
                    VALUES (?, ?, ?, ?, ?, 'pending', NOW())
                    ON DUPLICATE KEY UPDATE 
                    status = 'pending', updated_at = NOW()
                ");
                $stmt->execute([$hash, $from, $to, $amount, $rate]);
                enhancedLog("Transaction logged to database");
            } catch (Exception $e) {
                enhancedLog("Failed to log transaction to database: " . $e->getMessage(), 'WARNING');
            }
            
        } else {
            enhancedLog("Failed to add transaction to mempool: $hash", 'ERROR');
            $errorCount++;
            
            // Move to processed directory with error suffix
            rename($file, $processedDir . '/failed_' . basename($file));
        }
        
    } catch (Exception $e) {
        enhancedLog("Error creating transaction object: " . $e->getMessage(), 'ERROR');
        $errorCount++;
        rename($file, $processedDir . '/error_' . basename($file));
    }
}

enhancedLog("Processing complete. Processed: $processedCount, Errors: $errorCount");

// Try to trigger block creation if we have transactions
if ($processedCount > 0) {
    enhancedLog("Attempting to trigger block creation...");
    try {
        // Check if we have a validator available
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM validators WHERE status = 'active'");
        $stmt->execute();
        $activeValidators = $stmt->fetchColumn();
        
        if ($activeValidators > 0) {
            enhancedLog("Found $activeValidators active validators, block creation should proceed");
        } else {
            enhancedLog("No active validators found, blocks cannot be created", 'WARNING');
        }
        
        // Check mempool size
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM mempool WHERE status = 'pending'");
        $stmt->execute();
        $pendingCount = $stmt->fetchColumn();
        enhancedLog("Current pending transactions in mempool: $pendingCount");
        
    } catch (Exception $e) {
        enhancedLog("Error checking validator status: " . $e->getMessage(), 'WARNING');
    }
}

enhancedLog("Mempool processor finished successfully");
exit(0);

<?php
/**
 * Process Mempool Web Endpoint
 * URL: https://yournode.com/process_mempool.php?cmd=process&token=YOUR_TOKEN
 */

use Blockchain\Core\Transaction\Transaction;
use Blockchain\Core\Transaction\MempoolManager;
use Blockchain\Core\Transaction\FeePolicy;
use Blockchain\Core\Crypto\EthereumTx;

// Determine if running via web or CLI
$isWeb = isset($_SERVER['REQUEST_METHOD']);

if ($isWeb) {
    // Set JSON response headers
    header('Content-Type: application/json');
    
    // CORS headers if needed
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }
    
    // Handle OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }
}

try {
    // Load environment and configuration
    require_once __DIR__ . '/vendor/autoload.php';
    
    // Determine project base directory
    $baseDir = __DIR__;
    
    // Load environment variables
    require_once $baseDir . '/core/Environment/EnvironmentLoader.php';
    \Blockchain\Core\Environment\EnvironmentLoader::load($baseDir);
    
    // Load config
    $configFile = $baseDir . '/config/config.php';
    $config = [];
    if (file_exists($configFile)) {
        $config = require $configFile;
    }
    
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
            'charset' => 'utf8mb4',
        ];
    }
    
    // Connect to database
    $host = $dbConfig['host'] ?? 'localhost';
    $dbname = $dbConfig['database'] ?? $dbConfig['name'] ?? 'blockchain';
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $dbname);
    $pdo = new PDO($dsn, $dbConfig['username'] ?? 'root', $dbConfig['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Authentication check for web requests
    if ($isWeb) {
        $token = $_GET['token'] ?? null;
        if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/Bearer\\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
                $token = trim($m[1]);
            }
        }
        
        if (!$token) {
            http_response_code(401);
            echo json_encode(['error' => 'Missing authentication token']);
            exit(1);
        }
        
        // Verify token against database
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE api_key = ? AND role = 'admin'");
        $stmt->execute([$token]);
        if ($stmt->fetchColumn() == 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid authentication token']);
            exit(1);
        }
    }

    // Parse command and options
    $command = 'process'; // default
    $maxFiles = 100;
    $verbose = false;
    $dryRun = false;
    
    if ($isWeb) {
        $command = $_GET['cmd'] ?? $_GET['command'] ?? 'process';
        $maxFiles = (int)($_GET['max'] ?? $_GET['max_files'] ?? 100);
        $verbose = isset($_GET['verbose']) || isset($_GET['v']);
        $dryRun = isset($_GET['dry']) || isset($_GET['dry_run']);
    } else {
        // CLI mode
        foreach ($argv as $arg) {
            if (preg_match('/^--max=(\d+)$/', $arg, $m)) $maxFiles = (int)$m[1];
            elseif ($arg === '--dry') $dryRun = true;
            elseif ($arg === '--verbose' || $arg === '-v') $verbose = true;
            elseif ($arg === 'status') $command = 'status';
            elseif ($arg === 'help') $command = 'help';
        }
    }

    function log_message($msg, $force = false) {
        global $verbose, $isWeb;
        if ($verbose || $force) {
            if ($isWeb) {
                error_log($msg);
            } else {
                echo $msg . "\n";
            }
        }
    }

    // Helper function to process raw transaction from file
    function processRawTransactionFile($filePath, $pdo, $verbose = false) {
        if (!is_file($filePath)) {
            return ['success' => false, 'error' => 'File not found'];
        }
        
        $content = @file_get_contents($filePath);
        if ($content === false) {
            return ['success' => false, 'error' => 'Cannot read file'];
        }
        
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Invalid JSON format'];
        }
        
        $hash = $data['hash'] ?? '';
        $rawHex = $data['raw'] ?? '';
        $parsed = $data['parsed'] ?? [];
        
        if (!$hash || !$rawHex) {
            return ['success' => false, 'error' => 'Missing hash or raw data'];
        }
        
        // Convert parsed Ethereum transaction to blockchain Transaction
        $from = $parsed['from'] ?? '';
        $to = $parsed['to'] ?? '';
        $valueHex = $parsed['value'] ?? '0x0';
        $nonceHex = $parsed['nonce'] ?? '0x0';
        $gasHex = $parsed['gas'] ?? '0x5208';
        $gasPriceHex = $parsed['gasPrice'] ?? $parsed['maxFeePerGas'] ?? '0x0';

        // If 'from' is missing, attempt recovery using EthereumTx::recoverAddress
        if ($from === '' && is_string($rawHex)) {
            try {
                $recovered = \Blockchain\Core\Crypto\EthereumTx::recoverAddress($rawHex);
                if ($recovered) {
                    $from = $recovered;
                    $parsed['from'] = $from; // update parsed for potential logging
                    if ($verbose) log_message("Recovered from address: $from", true);
                }
            } catch (\Throwable $e) {
                if ($verbose) log_message('From recovery failed: ' . $e->getMessage(), true);
            }
        }

        // Normalize addresses to lowercase 0x format
        $norm = function($addr) {
            if (!is_string($addr)) return '';
            $a = strtolower($addr);
            return (preg_match('/^0x[a-f0-9]{40}$/', $a)) ? $a : '';
        };
        $from = $norm($from);
        $to = $norm($to);

        // Reject zero-address transactions (likely junk / malformed) except for special system/genesis markers
        $zeroAddr = '0x0000000000000000000000000000000000000000';
        if ($from === $zeroAddr || $to === $zeroAddr) {
            return ['success' => false, 'error' => 'Zero address detected (filtered)'];
        }
        if ($from === '' || $to === '') {
            return ['success' => false, 'error' => 'Address normalization failed'];
        }

        // Convert hex value (wei) to decimal token amount (18 decimals) with big number safety
        $amount = 0.0;
        if (is_string($valueHex) && str_starts_with(strtolower($valueHex), '0x')) {
            $hexNum = ltrim(substr($valueHex, 2), '0');
            if ($hexNum === '') {
                $amount = 0.0;
            } else {
                // Convert hex to decimal string without bcmath/gmp
                $hexMap = ['0'=>0,'1'=>1,'2'=>2,'3'=>3,'4'=>4,'5'=>5,'6'=>6,'7'=>7,'8'=>8,'9'=>9,'a'=>10,'b'=>11,'c'=>12,'d'=>13,'e'=>14,'f'=>15];
                $decStr = '0';
                for ($i=0,$len=strlen($hexNum); $i<$len; $i++) {
                    $digit = $hexMap[strtolower($hexNum[$i])] ?? 0;
                    // decStr = decStr * 16 + digit
                    $carry = 0;
                    $out = '';
                    for ($j=strlen($decStr)-1; $j>=0; $j--) {
                        $prod = ((int)$decStr[$j]) * 16 + $carry;
                        $carry = intdiv($prod,10);
                        $out = ($prod % 10) . $out;
                    }
                    while ($carry>0) { $out = ($carry % 10) . $out; $carry = intdiv($carry,10); }
                    // add digit
                    $carry = $digit; $k = strlen($out)-1;
                    while ($carry>0 && $k>=0) {
                        $sum = ((int)$out[$k]) + $carry;
                        $out[$k] = (string)($sum % 10);
                        $carry = intdiv($sum,10); $k--;
                    }
                    while ($carry>0) { $out = ($carry % 10) . $out; $carry = intdiv($carry,10); }
                    // trim leading zeros
                    $decStr = ltrim($out,'0');
                    if ($decStr==='') $decStr='0';
                }
                // Now decStr is integer wei. Convert to float tokens cautiously.
                if (strlen($decStr) <= 18) {
                    $amount = (float)('0.' . str_pad($decStr,18,'0',STR_PAD_LEFT));
                } else {
                    $intPart = substr($decStr, 0, -18);
                    $fracPart = substr($decStr, -18);
                    // Limit fractional to 8 digits for float precision
                    $shortFrac = rtrim(substr($fracPart, 0, 8),'0');
                    $amount = (float)($intPart . ( $shortFrac !== '' ? '.' . $shortFrac : ''));
                }
            }
        }
        
        $nonceInt = is_string($nonceHex) && str_starts_with(strtolower($nonceHex), '0x') 
            ? (int)hexdec(substr($nonceHex, 2)) : 0;
            
        $gasLimit = is_string($gasHex) && str_starts_with(strtolower($gasHex), '0x') 
            ? (int)hexdec(substr($gasHex, 2)) : 21000;
            
        $gasPriceInt = 0;
        if (is_string($gasPriceHex) && str_starts_with(strtolower($gasPriceHex), '0x')) {
            $gasPriceInt = (int)hexdec(substr($gasPriceHex, 2));
        }
        
        // Calculate fee (simplified)
        $fee = 0.05; // Fixed fee for now
        
        // Derive human gas price (token units) from effective gas price integer
        $gasPriceToken = $gasPriceInt > 0 ? $gasPriceInt / 1e18 : 0.0;
        
        try {
            if ($from === '' || $to === '') {
                return ['success' => false, 'error' => 'Missing from or to address after recovery'];
            }
            // Format amount to 8 decimals as stored in DB to avoid float representation mismatch
            $amountFormatted = number_format($amount, 8, '.', '');

            // PREVENTION: Check confirmed transactions for identical economic content (from,to,amount,nonce)
            $dupStmt = $pdo->prepare("SELECT hash FROM transactions WHERE from_address = ? AND to_address = ? AND amount = ? AND nonce = ? AND status='confirmed' LIMIT 1");
            $dupStmt->execute([$from, $to, $amountFormatted, $nonceInt]);
            $confirmedDup = $dupStmt->fetchColumn();
            if ($confirmedDup) {
                if ($verbose) log_message("Skip duplicate confirmed tx content (hash=$confirmedDup)", true);
                return ['success' => false, 'error' => 'Duplicate already confirmed'];
            }

            // Also check mempool for same content to reduce spam (re-using current schema of mempool)
            $mpDupStmt = $pdo->prepare("SELECT tx_hash FROM mempool WHERE from_address=? AND to_address=? AND amount=? AND nonce=? LIMIT 1");
            $mpDupStmt->execute([$from, $to, $amountFormatted, $nonceInt]);
            if ($mpDupStmt->fetchColumn()) {
                if ($verbose) log_message("Skip duplicate mempool content", true);
                return ['success' => false, 'error' => 'Duplicate already in mempool'];
            }

            // If provided hash already exists (confirmed or mempool) skip
            if ($hash) {
                $hashDupT = $pdo->prepare("SELECT 1 FROM transactions WHERE hash = ? LIMIT 1");
                $hashDupT->execute([$hash]);
                if ($hashDupT->fetchColumn()) {
                    return ['success' => false, 'error' => 'Hash already confirmed'];
                }
                $hashDupM = $pdo->prepare("SELECT 1 FROM mempool WHERE tx_hash = ? LIMIT 1");
                $hashDupM->execute([$hash]);
                if ($hashDupM->fetchColumn()) {
                    return ['success' => false, 'error' => 'Hash already in mempool'];
                }
            }

            $tx = new Transaction($from, $to, (float)$amountFormatted, $fee, $nonceInt, null, $gasLimit, $gasPriceToken);
            // For external raw transactions, always use the keccak256 hash as primary hash
            if ($hash) { 
                $tx->forceHash($hash); 
            }
            $tx->setSignature('external_raw');

            // Extra guard: Validate nonce sequence if >0 (non-strict: allow gaps but log)
            if ($nonceInt > 0) {
                $nonceCheck = $pdo->prepare("SELECT MAX(nonce) AS max_nonce FROM transactions WHERE from_address=? AND status='confirmed'");
                $nonceCheck->execute([$from]);
                $maxNonce = (int)$nonceCheck->fetchColumn();
                if ($nonceInt <= $maxNonce) {
                    // Already used nonce -> likely replay / duplicate
                    return ['success' => false, 'error' => 'Nonce already used'];
                }
            }

            $mempool = new MempoolManager($pdo, ['min_fee' => 0.001]);
            $result = $mempool->addTransaction($tx);
            if ($result) {
                log_message("Processed: $hash -> {$tx->getHash()} from=$from to=$to amount=$amountFormatted fee=$fee", $verbose);
                return [
                    'success' => true,
                    'original_hash' => $hash,
                    'internal_hash' => $tx->getHash(),
                    'from' => $from,
                    'to' => $to,
                    'amount' => (float)$amountFormatted,
                    'fee' => $fee
                ];
            } else {
                return ['success' => false, 'error' => 'Failed to add to mempool'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Transaction creation failed: ' . $e->getMessage()];
        }
    }

    // Execute command
    switch ($command) {
        case 'process':
            $rawMempoolDir = __DIR__ . '/storage/raw_mempool';
            $processedDir = $rawMempoolDir . '/processed';
            
            if (!is_dir($rawMempoolDir)) {
                $result = [
                    'success' => false,
                    'message' => 'Raw mempool directory not found'
                ];
                break;
            }
            
            if (!is_dir($processedDir)) {
                @mkdir($processedDir, 0755, true);
            }
            
            $files = glob($rawMempoolDir . '/*.json');
            if (empty($files)) {
                $result = [
                    'success' => true,
                    'message' => 'No raw transactions to process',
                    'files_processed' => 0
                ];
                break;
            }
            
            // Limit number of files processed
            $files = array_slice($files, 0, $maxFiles);
            
            $processed = 0;
            $errors = 0;
            $results = [];
            
            foreach ($files as $file) {
                $filename = basename($file);
                log_message("Processing: $filename", $verbose);
                
                if ($dryRun) {
                    $results[] = [
                        'file' => $filename,
                        'status' => 'dry_run_skipped'
                    ];
                    continue;
                }
                
                $result = processRawTransactionFile($file, $pdo, $verbose);
                
                if ($result['success']) {
                    // Move file to processed directory
                    $newPath = $processedDir . '/' . $filename;
                    if (@rename($file, $newPath)) {
                        $processed++;
                        $results[] = array_merge(['file' => $filename, 'status' => 'processed'], $result);
                    } else {
                        $errors++;
                        $results[] = ['file' => $filename, 'status' => 'move_failed', 'error' => 'Could not move file'];
                    }
                } else {
                    $errors++;
                    $results[] = array_merge(['file' => $filename, 'status' => 'error'], $result);
                }
            }
            
            $result = [
                'success' => true,
                'message' => "Processed $processed files, $errors errors",
                'files_found' => count($files),
                'files_processed' => $processed,
                'errors' => $errors,
                'dry_run' => $dryRun,
                'details' => $verbose ? $results : []
            ];
            
            log_message("Mempool processing complete: $processed processed, $errors errors", true);
            break;

        case 'status':
            $rawMempoolDir = __DIR__ . '/storage/raw_mempool';
            $processedDir = $rawMempoolDir . '/processed';
            
            $rawFiles = is_dir($rawMempoolDir) ? count(glob($rawMempoolDir . '/*.json')) : 0;
            $processedFiles = is_dir($processedDir) ? count(glob($processedDir . '/*.json')) : 0;
            
            // Get mempool status
            $stmt = $pdo->query("SELECT COUNT(*) as pending_tx FROM mempool");
            $mempoolCount = $stmt->fetchColumn();
            
            $result = [
                'success' => true,
                'raw_files_pending' => $rawFiles,
                'processed_files' => $processedFiles,
                'mempool_transactions' => (int)$mempoolCount,
                'needs_processing' => $rawFiles > 0
            ];
            break;

        case 'help':
        default:
            $result = [
                'success' => true,
                'message' => 'Mempool processing endpoint help',
                'commands' => [
                    'process' => 'Process raw transaction files into mempool',
                    'status' => 'Get raw files and mempool status'
                ],
                'parameters' => [
                    'cmd' => 'Command to execute (process|status)',
                    'token' => 'Authentication token (required)',
                    'max' => 'Maximum files to process (default: 100)',
                    'verbose' => 'Enable verbose logging',
                    'dry' => 'Dry run mode (scan but don\'t process)'
                ],
                'examples' => [
                    'process_mempool.php?cmd=process&token=YOUR_TOKEN',
                    'process_mempool.php?cmd=status&token=YOUR_TOKEN',
                    'process_mempool.php?cmd=process&token=YOUR_TOKEN&max=50&verbose=1'
                ]
            ];
            break;
    }

    // Output result
    if ($isWeb) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } else {
        if ($result['success']) {
            echo $result['message'] . "\n";
            if (isset($result['files_processed'])) {
                echo "Files processed: " . $result['files_processed'] . "\n";
            }
        } else {
            echo "Error: " . $result['message'] . "\n";
            exit(1);
        }
    }

} catch (Throwable $e) {
    $error = [
        'success' => false,
        'message' => 'Mempool processing error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ];
    
    if ($isWeb) {
        http_response_code(500);
        echo json_encode($error);
    } else {
        echo "Error: " . $error['message'] . "\n";
        echo "File: " . $error['file'] . ":" . $error['line'] . "\n";
        exit(1);
    }
}

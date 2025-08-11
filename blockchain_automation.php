<?php
/**
 * Blockchain Automation Web Endpoint
 * URL: https://yournode.com/blockchain_automation.php?cmd=auto&token=YOUR_TOKEN
 * 
 * This endpoint combines mempool processing and block mining in one call
 */

use Blockchain\Core\Blockchain\Block;
use Blockchain\Core\Transaction\Transaction;
use Blockchain\Core\Transaction\MempoolManager;
use Blockchain\Core\Storage\BlockStorage;
use Blockchain\Core\Consensus\ValidatorManager;
use Blockchain\Core\Consensus\ProofOfStake;

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
    $command = 'auto'; // default
    $maxFiles = 100;
    $maxTransactions = 100;
    $verbose = false;
    $skipProcessing = false;
    $skipMining = false;
    
    if ($isWeb) {
        $command = $_GET['cmd'] ?? $_GET['command'] ?? 'auto';
        $maxFiles = (int)($_GET['max_files'] ?? 100);
        $maxTransactions = (int)($_GET['max_tx'] ?? 100);
        $verbose = isset($_GET['verbose']) || isset($_GET['v']);
        $skipProcessing = isset($_GET['skip_processing']);
        $skipMining = isset($_GET['skip_mining']);
    } else {
        // CLI mode
        foreach ($argv as $arg) {
            if (preg_match('/^--max-files=(\d+)$/', $arg, $m)) $maxFiles = (int)$m[1];
            elseif (preg_match('/^--max-tx=(\d+)$/', $arg, $m)) $maxTransactions = (int)$m[1];
            elseif ($arg === '--verbose' || $arg === '-v') $verbose = true;
            elseif ($arg === '--skip-processing') $skipProcessing = true;
            elseif ($arg === '--skip-mining') $skipMining = true;
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

    // Helper function to process raw transactions
    function processRawFiles($pdo, $maxFiles, $verbose) {
        $rawMempoolDir = __DIR__ . '/storage/raw_mempool';
        $processedDir = $rawMempoolDir . '/processed';
        
        if (!is_dir($rawMempoolDir)) {
            return ['processed' => 0, 'errors' => 0, 'message' => 'Raw mempool directory not found'];
        }
        
        if (!is_dir($processedDir)) {
            @mkdir($processedDir, 0755, true);
        }
        
        $files = glob($rawMempoolDir . '/*.json');
        if (empty($files)) {
            return ['processed' => 0, 'errors' => 0, 'message' => 'No raw files to process'];
        }
        
        $files = array_slice($files, 0, $maxFiles);
        $processed = 0;
        $errors = 0;
        
    foreach ($files as $file) {
            $filename = basename($file);
            log_message("Processing raw file: $filename", $verbose);
            
            try {
                $content = @file_get_contents($file);
                if ($content === false) {
                    $errors++;
                    continue;
                }
                
                $data = json_decode($content, true);
                if (!is_array($data)) {
                    $errors++;
                    continue;
                }
                
                $hash = $data['hash'] ?? '';
                $rawHex = $data['raw'] ?? '';
                $parsed = $data['parsed'] ?? [];

                $from = $parsed['from'] ?? '';
                $to = $parsed['to'] ?? '';
                $valueHex = $parsed['value'] ?? '0x0';
                $nonceHex = $parsed['nonce'] ?? '0x0';
                $gasHex = $parsed['gas'] ?? '0x5208';
                $gasPriceHex = $parsed['gasPrice'] ?? $parsed['maxFeePerGas'] ?? '0x0';

                // Recover from if missing
                if ($from === '' && is_string($rawHex) && str_starts_with($rawHex, '0x')) {
                    try {
                        if (class_exists('Blockchain\\Core\\Crypto\\EthereumTx')) {
                            $recovered = \Blockchain\Core\Crypto\EthereumTx::recoverAddress($rawHex);
                            if ($recovered) {
                                $from = $recovered;
                                $parsed['from'] = $from;
                                log_message("Recovered from: $from", $verbose);
                            }
                        }
                    } catch (\Throwable $e) {
                        log_message("From recovery failed: " . $e->getMessage(), $verbose);
                    }
                }

                // Normalize addresses
                $norm = function($addr) {
                    if (!is_string($addr)) return '';
                    $a = strtolower($addr);
                    return preg_match('/^0x[a-f0-9]{40}$/', $a) ? $a : '';
                };
                $from = $norm($from);
                $to = $norm($to);

                // Big-int safe hex (wei) -> token amount (18 decimals)
                $amount = 0.0;
                if (is_string($valueHex) && str_starts_with(strtolower($valueHex), '0x')) {
                    $hexNum = ltrim(substr($valueHex, 2), '0');
                    if ($hexNum !== '') {
                        $hexMap = ['0'=>0,'1'=>1,'2'=>2,'3'=>3,'4'=>4,'5'=>5,'6'=>6,'7'=>7,'8'=>8,'9'=>9,'a'=>10,'b'=>11,'c'=>12,'d'=>13,'e'=>14,'f'=>15];
                        $decStr = '0';
                        for ($i=0,$len=strlen($hexNum); $i<$len; $i++) {
                            $digit = $hexMap[$hexNum[$i]] ?? 0;
                            $carry = 0; $out='';
                            for ($j=strlen($decStr)-1; $j>=0; $j--) { $prod=((int)$decStr[$j])*16 + $carry; $carry=intdiv($prod,10); $out=($prod%10).$out; }
                            while ($carry>0) { $out=($carry%10).$out; $carry=intdiv($carry,10);} 
                            $carry = $digit; $k=strlen($out)-1;
                            while ($carry>0 && $k>=0) { $sum=((int)$out[$k])+$carry; $out[$k]= (string)($sum%10); $carry=intdiv($sum,10); $k--; }
                            while ($carry>0) { $out=($carry%10).$out; $carry=intdiv($carry,10);} 
                            $decStr = ltrim($out,'0'); if ($decStr==='') $decStr='0';
                        }
                        if (strlen($decStr) <= 18) {
                            $amount = (float)('0.' . str_pad($decStr,18,'0',STR_PAD_LEFT));
                        } else {
                            $intPart = substr($decStr, 0, -18);
                            $fracPart = substr($decStr, -18);
                            $shortFrac = rtrim(substr($fracPart, 0, 8),'0');
                            $amount = (float)($intPart . ($shortFrac !== '' ? '.' . $shortFrac : ''));
                        }
                    }
                }

                $nonceInt = (is_string($nonceHex) && str_starts_with(strtolower($nonceHex), '0x')) ? (int)hexdec(substr($nonceHex, 2)) : 0;
                $gasLimit = (is_string($gasHex) && str_starts_with(strtolower($gasHex), '0x')) ? (int)hexdec(substr($gasHex, 2)) : 21000;
                $gasPriceInt = (is_string($gasPriceHex) && str_starts_with(strtolower($gasPriceHex), '0x')) ? (int)hexdec(substr($gasPriceHex, 2)) : 0;
                $gasPriceToken = $gasPriceInt > 0 ? $gasPriceInt / 1e18 : 0.0;
                $fee = 0.05; // fixed fee

                if ($from === '' || $to === '') {
                    $errors++;
                    log_message("Skipping file $filename missing from/to after recovery", $verbose);
                    continue;
                }

                // Format amount to 8 decimals to match DB precision
                $amountFormatted = number_format($amount, 8, '.', '');

                // PREVENTION: Check confirmed transactions for identical economic content
                $dupStmt = $pdo->prepare("SELECT hash FROM transactions WHERE from_address = ? AND to_address = ? AND amount = ? AND nonce = ? AND status='confirmed' LIMIT 1");
                $dupStmt->execute([$from, $to, $amountFormatted, $nonceInt]);
                $confirmedDup = $dupStmt->fetchColumn();
                if ($confirmedDup) {
                    log_message("Skip duplicate confirmed tx content (hash=$confirmedDup) for file $filename", $verbose);
                    // Move to processed even if duplicate to avoid reprocessing
                    $newPath = $processedDir . '/' . $filename;
                    @rename($file, $newPath);
                    continue;
                }

                // Check mempool for same content
                $mpDupStmt = $pdo->prepare("SELECT tx_hash FROM mempool WHERE from_address=? AND to_address=? AND amount=? AND nonce=? LIMIT 1");
                $mpDupStmt->execute([$from, $to, $amountFormatted, $nonceInt]);
                if ($mpDupStmt->fetchColumn()) {
                    log_message("Skip duplicate mempool content for file $filename", $verbose);
                    $newPath = $processedDir . '/' . $filename;
                    @rename($file, $newPath);
                    continue;
                }

                // If provided hash already exists, skip
                if ($hash) {
                    $hashDupT = $pdo->prepare("SELECT 1 FROM transactions WHERE hash = ? LIMIT 1");
                    $hashDupT->execute([$hash]);
                    if ($hashDupT->fetchColumn()) {
                        log_message("Skip hash already confirmed: $hash for file $filename", $verbose);
                        $newPath = $processedDir . '/' . $filename;
                        @rename($file, $newPath);
                        continue;
                    }
                    $hashDupM = $pdo->prepare("SELECT 1 FROM mempool WHERE tx_hash = ? LIMIT 1");
                    $hashDupM->execute([$hash]);
                    if ($hashDupM->fetchColumn()) {
                        log_message("Skip hash already in mempool: $hash for file $filename", $verbose);
                        $newPath = $processedDir . '/' . $filename;
                        @rename($file, $newPath);
                        continue;
                    }
                }

                // Validate nonce sequence
                if ($nonceInt > 0) {
                    $nonceCheck = $pdo->prepare("SELECT MAX(nonce) AS max_nonce FROM transactions WHERE from_address=? AND status='confirmed'");
                    $nonceCheck->execute([$from]);
                    $maxNonce = (int)$nonceCheck->fetchColumn();
                    if ($nonceInt <= $maxNonce) {
                        log_message("Skip nonce already used: $nonceInt <= $maxNonce for file $filename", $verbose);
                        $errors++;
                        continue;
                    }
                }

                $tx = new Transaction($from, $to, (float)$amountFormatted, $fee, $nonceInt, null, $gasLimit, $gasPriceToken);
                // For external raw transactions, always use the keccak256 hash as primary hash
                if ($hash) { 
                    $tx->forceHash($hash); 
                }
                $tx->setSignature('external_raw');
                
                $mempool = new MempoolManager($pdo, ['min_fee' => 0.001]);
                $result = $mempool->addTransaction($tx);
                
                if ($result) {
                    $newPath = $processedDir . '/' . $filename;
                    if (@rename($file, $newPath)) {
                        $processed++;
                        log_message("Processed: $hash -> {$tx->getHash()} from=$from to=$to amount=$amountFormatted fee=$fee", $verbose);
                    } else {
                        $errors++;
                        log_message("File move failed for $filename", $verbose);
                    }
                } else {
                    $errors++;
                    log_message("Mempool add failed for $filename", $verbose);
                }
            } catch (Exception $e) {
                $errors++;
                log_message("Error processing $filename: " . $e->getMessage(), $verbose);
            }
        }
        
        return [
            'processed' => $processed,
            'errors' => $errors,
            'message' => "Raw processing: $processed processed, $errors errors"
        ];
    }

    // Helper function to mine blocks
    function mineBlocks($pdo, $config, $maxTransactions, $verbose) {
        $mempool = new MempoolManager($pdo, ['min_fee' => 0.001]);
        
        // Check for transactions
        $txs = $mempool->getTransactionsForBlock($maxTransactions);
        if (empty($txs)) {
            return [
                'mined' => false,
                'message' => 'No transactions in mempool to mine'
            ];
        }
        
        // Determine next block height
        $prevHash = 'GENESIS';
        $height = 0;
        $stmt = $pdo->query("SELECT hash, height FROM blocks ORDER BY height DESC LIMIT 1");
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $prevHash = $row['hash'];
            $height = (int)$row['height'] + 1;
        }
        
        log_message("Mining block height $height with " . count($txs) . " transactions", $verbose);
        
        // Get original hashes for cleanup
        $stmt = $pdo->prepare("
            SELECT tx_hash FROM mempool 
            ORDER BY priority_score DESC, created_at ASC 
            LIMIT :max_count
        ");
        $stmt->bindParam(':max_count', $maxTransactions, PDO::PARAM_INT);
        $stmt->execute();
        $originalHashes = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $originalHashes[] = $row['tx_hash'];
        }
        
        // Initialize mining components
        $validatorManager = new ValidatorManager($pdo, $config);
        
        require_once __DIR__ . '/core/Logging/NullLogger.php';
        $logger = new \Blockchain\Core\Logging\NullLogger();
        
        $pos = new ProofOfStake($logger);
        $pos->setValidatorManager($validatorManager);
        
        $block = new Block($height, $txs, $prevHash, [], []);
        
        // Sign block
        try { 
            $pos->signBlock($block); 
        } catch (Throwable $e) { 
            log_message('Block signing failed: ' . $e->getMessage(), $verbose); 
        }
        
        // Save block
        $storage = new BlockStorage(__DIR__ . '/storage/blockchain_runtime.json', $pdo, $validatorManager);
        $ok = $storage->saveBlock($block);
        
        if (!$ok) {
            return [
                'mined' => false,
                'message' => 'Failed to save block'
            ];
        }
        
        // Clean up mempool
        $removed = 0;
        $del = $pdo->prepare('DELETE FROM mempool WHERE tx_hash = ?');
        foreach ($originalHashes as $originalHash) {
            $del->execute([$originalHash]);
            $removed += $del->rowCount();
        }
        
        return [
            'mined' => true,
            'block_height' => $height,
            'block_hash' => $block->getHash(),
            'transactions_processed' => count($txs),
            'transactions_removed' => $removed,
            'message' => "Block $height mined successfully"
        ];
    }

    // Execute command
    switch ($command) {
        case 'auto':
            $results = [
                'success' => true,
                'timestamp' => time(),
                'steps' => []
            ];
            
            // Step 1: Process raw files (if not skipped)
            if (!$skipProcessing) {
                log_message("Step 1: Processing raw transaction files", true);
                $processResult = processRawFiles($pdo, $maxFiles, $verbose);
                $results['steps']['processing'] = $processResult;
                log_message($processResult['message'], true);
            } else {
                $results['steps']['processing'] = ['skipped' => true];
            }
            
            // Step 2: Mine blocks (if not skipped)
            if (!$skipMining) {
                log_message("Step 2: Mining blocks", true);
                $mineResult = mineBlocks($pdo, $config, $maxTransactions, $verbose);
                $results['steps']['mining'] = $mineResult;
                log_message($mineResult['message'], true);
            } else {
                $results['steps']['mining'] = ['skipped' => true];
            }
            
            // Summary
            $processed = $results['steps']['processing']['processed'] ?? 0;
            $mined = $results['steps']['mining']['mined'] ?? false;
            $results['summary'] = [
                'raw_files_processed' => $processed,
                'block_mined' => $mined,
                'message' => "Automation complete: $processed raw files processed, " . ($mined ? '1 block mined' : 'no blocks mined')
            ];
            
            $result = $results;
            break;

        case 'status':
            // Get various stats
            $rawMempoolDir = __DIR__ . '/storage/raw_mempool';
            $processedDir = $rawMempoolDir . '/processed';
            
            $rawFiles = is_dir($rawMempoolDir) ? count(glob($rawMempoolDir . '/*.json')) : 0;
            $processedFiles = is_dir($processedDir) ? count(glob($processedDir . '/*.json')) : 0;
            
            $stmt = $pdo->query("SELECT COUNT(*) as pending_tx FROM mempool");
            $mempoolCount = $stmt->fetchColumn();
            
            $stmt = $pdo->query("SELECT height, hash, timestamp FROM blocks ORDER BY height DESC LIMIT 1");
            $latestBlock = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $result = [
                'success' => true,
                'raw_files_pending' => $rawFiles,
                'processed_files_total' => $processedFiles,
                'mempool_transactions' => (int)$mempoolCount,
                'latest_block' => $latestBlock ? [
                    'height' => (int)$latestBlock['height'],
                    'hash' => $latestBlock['hash'],
                    'timestamp' => (int)$latestBlock['timestamp']
                ] : null,
                'needs_processing' => $rawFiles > 0,
                'ready_to_mine' => $mempoolCount > 0
            ];
            break;

        case 'help':
        default:
            $result = [
                'success' => true,
                'message' => 'Blockchain automation endpoint help',
                'commands' => [
                    'auto' => 'Process raw files and mine blocks automatically',
                    'status' => 'Get comprehensive blockchain status'
                ],
                'parameters' => [
                    'cmd' => 'Command to execute (auto|status)',
                    'token' => 'Authentication token (required)',
                    'max_files' => 'Maximum raw files to process (default: 100)',
                    'max_tx' => 'Maximum transactions per block (default: 100)',
                    'verbose' => 'Enable verbose logging',
                    'skip_processing' => 'Skip raw file processing step',
                    'skip_mining' => 'Skip block mining step'
                ],
                'examples' => [
                    'blockchain_automation.php?cmd=auto&token=YOUR_TOKEN',
                    'blockchain_automation.php?cmd=status&token=YOUR_TOKEN',
                    'blockchain_automation.php?cmd=auto&token=YOUR_TOKEN&max_files=50&max_tx=75&verbose=1'
                ]
            ];
            break;
    }

    // Output result
    if ($isWeb) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } else {
        if ($result['success']) {
            echo $result['message'] ?? $result['summary']['message'] ?? 'Operation completed' . "\n";
        } else {
            echo "Error: " . $result['message'] . "\n";
            exit(1);
        }
    }

} catch (Throwable $e) {
    $error = [
        'success' => false,
        'message' => 'Automation error: ' . $e->getMessage(),
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

<?php
/**
 * Mine Blocks Web Endpoint
 * URL: https://yournode.com/mine_blocks.php?cmd=mine&token=YOUR_TOKEN
 */

use Blockchain\Core\Blockchain\Block;
use Blockchain\Core\Transaction\MempoolManager;
use Blockchain\Core\Storage\BlockStorage;
use Blockchain\Core\Consensus\ValidatorManager;
use Blockchain\Core\Consensus\ProofOfStake;
use Blockchain\Core\Transaction\Transaction;

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
    $command = 'mine'; // default
    $maxTransactions = 100;
    $verbose = false;
    $dryRun = false;
    
    if ($isWeb) {
        $command = $_GET['cmd'] ?? $_GET['command'] ?? 'mine';
        $maxTransactions = (int)($_GET['max'] ?? $_GET['max_tx'] ?? 100);
        $verbose = isset($_GET['verbose']) || isset($_GET['v']);
        $dryRun = isset($_GET['dry']) || isset($_GET['dry_run']);
    } else {
        // CLI mode
        foreach ($argv as $arg) {
            if (preg_match('/^--max=(\d+)$/', $arg, $m)) $maxTransactions = (int)$m[1];
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

    // Execute command
    switch ($command) {
        case 'mine':
            // Initialize components
            $mempool = new MempoolManager($pdo, ['min_fee' => 0.001]);

            // Determine previous block index/hash from DB blocks table
            $prevHash = 'GENESIS';
            $height = 0;
            $stmt = $pdo->query("SELECT hash, height FROM blocks ORDER BY height DESC LIMIT 1");
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $prevHash = $row['hash'];
                $height = (int)$row['height'] + 1;
            }

            $txs = $mempool->getTransactionsForBlock($maxTransactions);
            if (empty($txs)) {
                $result = [
                    'success' => true,
                    'message' => 'No transactions in mempool to mine',
                    'block_height' => $height,
                    'transactions_processed' => 0
                ];
                break;
            }

            log_message('Preparing block height ' . $height . ' with ' . count($txs) . ' tx(s)', true);

            // Get original hashes from mempool for cleanup
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

            if ($dryRun) {
                $result = [
                    'success' => true,
                    'message' => '[DRY RUN] Block prepared but not mined',
                    'block_height' => $height,
                    'transactions_count' => count($txs),
                    'original_hashes' => $originalHashes
                ];
                break;
            }

            // Initialize PoS + validator manager for signature
            $validatorManager = new ValidatorManager($pdo, $config);
            
            // Use NullLogger for PSR-3 compatibility
            require_once __DIR__ . '/core/Logging/NullLogger.php';
            $logger = new \Blockchain\Core\Logging\NullLogger();
            
            $pos = new ProofOfStake($logger);
            $pos->setValidatorManager($validatorManager);

            $block = new Block($height, $txs, $prevHash, [], []);

            // Sign block via ValidatorManager
            try { 
                $pos->signBlock($block); 
            } catch (Throwable $e) { 
                log_message('Block signing failed: ' . $e->getMessage(), true); 
            }

            $storage = new BlockStorage(__DIR__ . '/storage/blockchain_runtime.json', $pdo, $validatorManager);
            $ok = $storage->saveBlock($block);
            if (!$ok) {
                $result = [
                    'success' => false,
                    'message' => 'Failed to persist block',
                    'block_height' => $height
                ];
                break;
            }

            // Remove mined tx from mempool using original hashes
            $removed = 0;
            $del = $pdo->prepare('DELETE FROM mempool WHERE tx_hash = ? OR tx_hash = ?');
            foreach ($originalHashes as $originalHash) {
                $dh = strtolower(trim((string)$originalHash));
                $dh0 = str_starts_with($dh,'0x') ? $dh : ('0x'.$dh);
                $dh1 = str_starts_with($dh,'0x') ? substr($dh,2) : $dh;
                $del->execute([$dh0, $dh1]);
                $removed += $del->rowCount();
            }

            $result = [
                'success' => true,
                'message' => 'Block mined successfully',
                'block_height' => $height,
                'block_hash' => $block->getHash(),
                'transactions_processed' => count($txs),
                'transactions_removed_from_mempool' => $removed,
                'timestamp' => time()
            ];

            log_message('Block mined: height=' . $height . ' hash=' . $block->getHash() . ' txs=' . count($txs) . ' removed=' . $removed, true);
            break;

        case 'status':
            // Get mempool status
            $stmt = $pdo->query("SELECT COUNT(*) as pending_tx FROM mempool");
            $mempoolCount = $stmt->fetchColumn();
            
            // Get latest block
            $stmt = $pdo->query("SELECT height, hash, timestamp FROM blocks ORDER BY height DESC LIMIT 1");
            $latestBlock = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $result = [
                'success' => true,
                'mempool_transactions' => (int)$mempoolCount,
                'latest_block' => $latestBlock ? [
                    'height' => (int)$latestBlock['height'],
                    'hash' => $latestBlock['hash'],
                    'timestamp' => (int)$latestBlock['timestamp']
                ] : null,
                'ready_to_mine' => $mempoolCount > 0
            ];
            break;

        case 'help':
        default:
            $result = [
                'success' => true,
                'message' => 'Mining endpoint help',
                'commands' => [
                    'mine' => 'Mine new block from mempool transactions',
                    'status' => 'Get mining and mempool status'
                ],
                'parameters' => [
                    'cmd' => 'Command to execute (mine|status)',
                    'token' => 'Authentication token (required)',
                    'max' => 'Maximum transactions per block (default: 100)',
                    'verbose' => 'Enable verbose logging',
                    'dry' => 'Dry run mode (prepare but don\'t mine)'
                ],
                'examples' => [
                    'mine_blocks.php?cmd=mine&token=YOUR_TOKEN',
                    'mine_blocks.php?cmd=status&token=YOUR_TOKEN',
                    'mine_blocks.php?cmd=mine&token=YOUR_TOKEN&max=50&verbose=1'
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
            if (isset($result['block_hash'])) {
                echo "Block Hash: " . $result['block_hash'] . "\n";
            }
        } else {
            echo "Error: " . $result['message'] . "\n";
            exit(1);
        }
    }

} catch (Throwable $e) {
    $error = [
        'success' => false,
        'message' => 'Mining error: ' . $e->getMessage(),
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

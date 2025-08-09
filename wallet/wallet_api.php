<?php
/**
 * Wallet API
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Accept, Origin');

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include logger
require_once __DIR__ . '/WalletLogger.php';

/**
 * Log helper wrapper (kept for backward compatibility)
 */
function writeLog($message, $level = 'DEBUG') {
    \Blockchain\Wallet\WalletLogger::log($message, $level);
}

// Early, dependency-free request logging to guarantee request traces even if later init fails
// This writes a minimal entry and preserves body/request ID for later use.
if (!defined('WALLET_API_EARLY_LOGGED')) {
    define('WALLET_API_EARLY_LOGGED', true);
    try {
        $baseDir = dirname(__DIR__);
        $logDir = $baseDir . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/wallet_api.log';
        $reqId = bin2hex(random_bytes(6));
        $GLOBALS['__REQ_ID'] = $reqId;
        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
        // Read body once and keep it for later handlers
        $rawBodyEarly = file_get_contents('php://input');
        $GLOBALS['__RAW_BODY'] = $rawBodyEarly;
        $timestamp = date('Y-m-d H:i:s');
        $line1 = "[{$timestamp}] [REQUEST] [{$reqId}] {$method} {$uri} from {$ip}";
        @file_put_contents($logFile, $line1 . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($method === 'POST' && !empty($rawBodyEarly)) {
            $bodyPreview = substr($rawBodyEarly, 0, 200);
            $line2 = "[{$timestamp}] [REQUEST] [{$reqId}] body: {$bodyPreview}";
            @file_put_contents($logFile, $line2 . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    } catch (\Throwable $e) {
        // Never break the API path due to logging issues
    }
}

// Fatal error handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
    \Blockchain\Wallet\WalletLogger::error("FATAL ERROR: " . $error['message']);
    \Blockchain\Wallet\WalletLogger::error("File: " . $error['file']);
    \Blockchain\Wallet\WalletLogger::error("Line: " . $error['line']);
        
    // Try to output JSON error response if possible
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Fatal error occurred: ' . $error['message'],
                'debug_info' => [
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'type' => 'fatal_error'
                ]
            ]);
        }
    }
});

try {
    // Determine project base directory
    $baseDir = dirname(__DIR__);
    
    // Include Composer autoloader
    $autoloader = $baseDir . '/vendor/autoload.php';
    if (!file_exists($autoloader)) {
        throw new Exception('Composer autoloader not found. Please run "composer install"');
    }
    require_once $autoloader;
    
    // Load environment variables
    require_once $baseDir . '/core/Environment/EnvironmentLoader.php';
    \Blockchain\Core\Environment\EnvironmentLoader::load($baseDir);
    
    // Load config
    $configFile = $baseDir . '/config/config.php';
    $config = [];
    if (file_exists($configFile)) {
        $config = require $configFile;
    }
    
    // Add debug_mode if not set
    if (!isset($config['debug_mode'])) {
        $config['debug_mode'] = true; // Default: debug enabled
    }
    
    // Initialize logger with configuration
    \Blockchain\Wallet\WalletLogger::init($config);

    // In development, aggressively reset OPcache so updated API logic (idempotent create) is picked up
    if (($config['debug_mode'] ?? false) && function_exists('opcache_reset')) {
        @opcache_reset();
    }

    // Allow force-enabling wallet API logging via query/header/env for diagnostics
    $forceLog = false;
    if (isset($_GET['log']) && ($_GET['log'] === '1' || strtolower((string)$_GET['log']) === 'true')) {
        $forceLog = true;
    }
    if (isset($_SERVER['HTTP_X_ENABLE_LOGGING']) && in_array(strtolower($_SERVER['HTTP_X_ENABLE_LOGGING']), ['1','true','yes','on'], true)) {
        $forceLog = true;
    }
    if ($forceLog) {
        $config['wallet_logging_enabled'] = true;
    \Blockchain\Wallet\WalletLogger::init($config); // re-init with logging enabled
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
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        ];
    }
    
    // Log connection attempt
    writeLog("Attempting database connection using DatabaseManager");
    
    // Connect to database via DatabaseManager
    require_once $baseDir . '/core/Database/DatabaseManager.php';
    $pdo = \Blockchain\Core\Database\DatabaseManager::getConnection();
    
    writeLog("Database connection successful");
    
    // Include Wallet classes
    require_once $baseDir . '/wallet/WalletManager.php';
    require_once $baseDir . '/wallet/WalletBlockchainManager.php';
    require_once $baseDir . '/core/Config/NetworkConfig.php';
    require_once $baseDir . '/core/Cryptography/MessageEncryption.php';
    require_once $baseDir . '/core/Cryptography/KeyPair.php';
    
    // Instantiate WalletManager with full config
    $fullConfig = array_merge($config, ['database' => $dbConfig]);
    $walletManager = new \Blockchain\Wallet\WalletManager($pdo, $fullConfig);
    
    // Instantiate WalletBlockchainManager for blockchain integration
    $blockchainManager = new \Blockchain\Wallet\WalletBlockchainManager($pdo, $fullConfig);
    
    // Instantiate NetworkConfig to fetch network settings
    $networkConfig = new \Blockchain\Core\Config\NetworkConfig($pdo);
    
    // Correlation ID for request tracing in logs (use early one if present)
    $requestId = $GLOBALS['__REQ_ID'] ?? bin2hex(random_bytes(6));

    // Parse input payload (reuse early-read body if available)
    $rawBody = $GLOBALS['__RAW_BODY'] ?? file_get_contents('php://input');
    $input = json_decode($rawBody, true);
    $jsonError = json_last_error();

    // For GET requests use $_GET
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $input = $_GET;
        $jsonError = JSON_ERROR_NONE;
    }

    // Simple request logging
    $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    
    // Log incoming request
    writeLog("[$requestId] $method $uri from $ip");
    
    // Log POST body preview (first 200 chars, no sensitive data)
    if ($method === 'POST' && !empty($rawBody)) {
        $bodyPreview = substr($rawBody, 0, 200);
        writeLog("[$requestId] POST body: $bodyPreview");
    }

    // Support clean /rpc alias via PATH_INFO or URL ending with /rpc
    $pathInfo = $_SERVER['PATH_INFO'] ?? '';
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $isRpcAlias = ($pathInfo === '/rpc') || (substr($reqPath, -4) === '/rpc');

    // Lightweight diagnostics: ensure we can write logs and return recent lines
    if (isset($_GET['action']) && $_GET['action'] === 'ping_log') {
        $ts = date('Y-m-d H:i:s');
        writeLog("[{$requestId}] ping_log at {$ts}", 'INFO');
        $baseDir = dirname(__DIR__);
        $logFile = $baseDir . '/logs/wallet_api.log';
        $tail = [];
        if (file_exists($logFile)) {
            $lines = @file($logFile, FILE_IGNORE_NEW_LINES);
            if (is_array($lines)) {
                $tail = array_slice($lines, -10);
            }
        }
        echo json_encode([
            'success' => true,
            'requestId' => $requestId,
            'logWritable' => is_writable(dirname($logFile)),
            'hasLogFile' => file_exists($logFile),
            'lastLines' => $tail,
        ]);
        return;
    }

    // Helper: return list of supported JSON-RPC method names
    $supportedRpcMethods = function() {
        // Keep in sync with handleRpcRequest cases
        return [
            'web3_clientVersion','web3_sha3',
            'net_version','net_listening','net_peerCount',
            'eth_chainId','eth_blockNumber','eth_getBalance','eth_coinbase','eth_getTransactionCount','eth_gasPrice','eth_maxPriorityFeePerGas','eth_estimateGas','eth_getTransactionByHash','eth_getTransactionReceipt','eth_sendRawTransaction','eth_sendTransaction','eth_getBlockByNumber','eth_getBlockByHash','eth_getStorageAt','eth_getCode','eth_getLogs','eth_getBlockTransactionCountByNumber','eth_getTransactionByBlockNumberAndIndex','eth_feeHistory','eth_syncing','eth_mining',
            'eth_accounts','eth_requestAccounts',
            'personal_listAccounts','personal_newAccount','personal_unlockAccount','personal_lockAccount','personal_sign','eth_sign',
            'wallet_addEthereumChain','wallet_switchEthereumChain','wallet_requestPermissions','wallet_getPermissions','wallet_watchAsset'
        ];
    };

    // Helper: build JSON-RPC error response
    $jsonRpcErrorResponse = function($id, int $code, string $message, $data = null) {
        $err = ['code' => $code, 'message' => $message];
        if ($data !== null) $err['data'] = $data;
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $err];
    };

    // Helper: process a single JSON-RPC request object
    $processJsonRpc = function($req) use ($pdo, $walletManager, $networkConfig, $supportedRpcMethods, $jsonRpcErrorResponse, $requestId) {
        // Validate request shape
        if (!is_array($req)) {
            return $jsonRpcErrorResponse(null, -32600, 'Invalid Request');
        }
        $id = $req['id'] ?? null;
        $method = $req['method'] ?? '';
        $params = $req['params'] ?? [];
        // Log incoming RPC call (sanitized)
        try {
            $toLog = $params;
            if (is_array($toLog)) {
                // Minimal inline masking of sensitive fields
                $maskKeys = ['privateKey','password','signature'];
                $toLog = array_map(function($v) use ($maskKeys) {
                    if (is_array($v)) {
                        foreach ($maskKeys as $k) { if (isset($v[$k])) { $v[$k] = '***'; } }
                    }
                    return $v;
                }, $toLog);
            }
            \Blockchain\Wallet\WalletLogger::info("RPC $requestId: method=" . (is_string($method)?$method:'') . " id=" . json_encode($id));
            \Blockchain\Wallet\WalletLogger::debug("RPC $requestId: params=" . json_encode($toLog, JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {}
        if (!is_string($method) || $method === '') {
            return $jsonRpcErrorResponse($id, -32600, 'Invalid Request');
        }
        // If method not supported by this endpoint, return standard error
        $supported = $supportedRpcMethods();
        if (!in_array($method, $supported, true)) {
            $resp = $jsonRpcErrorResponse($id, -32601, 'Method not found');
            try { \Blockchain\Wallet\WalletLogger::warning("RPC $requestId: method_not_found method=$method"); } catch (Throwable $e) {}
            return $resp;
        }
        // Execute and normalize result/error
        $res = handleRpcRequest($pdo, $walletManager, $networkConfig, $method, is_array($params) ? $params : []);
        if (is_array($res) && array_key_exists('code', $res) && array_key_exists('message', $res) && count($res) >= 2) {
            // Treat arrays with code+message as JSON-RPC error objects returned from rpcError()
            try { \Blockchain\Wallet\WalletLogger::warning("RPC $requestId: error code={$res['code']} message={$res['message']}"); } catch (Throwable $e) {}
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $res];
        }
        try {
            $preview = is_scalar($res) ? (string)$res : json_encode($res, JSON_UNESCAPED_SLASHES);
            if ($preview !== null) { $preview = substr((string)$preview, 0, 300); }
            \Blockchain\Wallet\WalletLogger::info("RPC $requestId: result_preview=" . ($preview ?? 'null'));
        } catch (Throwable $e) {}
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $res];
    };

    // Determine if this is a JSON-RPC call (single or batch) or /rpc alias
    $isJsonRpcSingle = is_array($input) && isset($input['jsonrpc']) && isset($input['method']);
    $isJsonRpcBatch = is_array($input) && array_keys($input) === range(0, count($input) - 1) && isset($input[0]['jsonrpc']);

    if ($isRpcAlias || $isJsonRpcSingle || $isJsonRpcBatch || ($_SERVER['REQUEST_METHOD'] === 'POST' && $rawBody !== '' && $jsonError !== JSON_ERROR_NONE)) {
        // If JSON parse failed
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rawBody !== '' && $jsonError !== JSON_ERROR_NONE) {
            echo json_encode($jsonRpcErrorResponse(null, -32700, 'Parse error'));
            exit;
        }

        // Handle single or batch
        if ($isJsonRpcBatch) {
            $responses = [];
            try { \Blockchain\Wallet\WalletLogger::info("RPC $requestId: batch size=" . count($input)); } catch (Throwable $e) {}
            foreach ($input as $req) {
                $responses[] = $processJsonRpc($req);
            }
            echo json_encode($responses);
            exit;
        }

        // For /rpc alias via GET or POST with jsonrpc
        if ($isRpcAlias && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            // Allow method and params via query for /rpc alias
            $rpcMethod = $_GET['method'] ?? '';
            $paramsRaw = $_GET['params'] ?? [];
            if (is_string($paramsRaw)) {
                $decoded = json_decode($paramsRaw, true);
                $params = json_last_error() === JSON_ERROR_NONE ? $decoded : [$paramsRaw];
            } else {
                $params = $paramsRaw;
            }
            $req = ['jsonrpc' => '2.0', 'id' => ($_GET['id'] ?? 1), 'method' => $rpcMethod, 'params' => $params];
            try { \Blockchain\Wallet\WalletLogger::info("RPC $requestId: alias method=$rpcMethod"); } catch (Throwable $e) {}
            echo json_encode($processJsonRpc($req));
            exit;
        }

        // Standard single JSON-RPC
        $response = $processJsonRpc($input);
        
        // Force commit any pending transaction before sending response
        try {
            if ($pdo && $pdo->inTransaction()) {
                writeLog("Forcing commit before JSON-RPC response", 'INFO');
                $pdo->commit();
                writeLog("Transaction committed before JSON-RPC response", 'INFO');
            }
        } catch (Exception $e) {
            writeLog("Failed to commit before JSON-RPC response: " . $e->getMessage(), 'ERROR');
            try {
                if ($pdo && $pdo->inTransaction()) {
                    $pdo->rollBack();
                    writeLog("Transaction rolled back before JSON-RPC response", 'INFO');
                }
            } catch (Exception $e2) {
                writeLog("Failed to rollback before JSON-RPC response: " . $e2->getMessage(), 'ERROR');
            }
        }
        
        echo json_encode($response);
        exit;
    }

    $action = $input['action'] ?? '';
    if ($action !== '') {
        try {
            $safe = $input;
            if (is_array($safe)) {
                $maskKeys = ['private_key','password'];
                foreach ($maskKeys as $k) { if (isset($safe[$k])) { $safe[$k] = '***'; } }
            }
            \Blockchain\Wallet\WalletLogger::info("ACTION $requestId: action=$action");
            \Blockchain\Wallet\WalletLogger::debug("ACTION $requestId: params=" . (is_array($safe)?json_encode($safe, JSON_UNESCAPED_SLASHES):'n/a'));
        } catch (Throwable $e) {}
    }
    
    switch ($action) {
        case 'dapp_config':
            // Return EIP-3085 compatible chain parameters for wallet_addEthereumChain
            $result = getDappConfig($networkConfig);
            break;
        case 'create_wallet':
            $result = createWallet($walletManager, $blockchainManager);
            break;
        case 'ensure_wallet':
            // Ensure a wallet record exists for the provided address (auto-create if missing)
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Address is required');
            }
            // Trigger auto-create via balance fetch and then return info
            try {
                $walletManager->getAvailableBalance($address);
                $result = getWalletInfo($walletManager, $address);
            } catch (Exception $e) {
                throw new Exception('Failed to ensure wallet: ' . $e->getMessage());
            }
            break;
            
        case 'list_wallets':
            $result = listWallets($walletManager);
            break;
            
        case 'get_balance':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Address is required');
            }
            $result = getBalance($walletManager, $address);
            break;
            
        case 'get_wallet_info':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Address is required');
            }
            $result = getWalletInfo($walletManager, $address);
            break;
            
        case 'stake_tokens':
            $address = $input['address'] ?? '';
            $amount = $input['amount'] ?? 0;
            $period = $input['period'] ?? 30;
            $privateKey = $input['private_key'] ?? '';
            if (!$address || !$amount || !$privateKey) {
                throw new Exception('Address, amount and private key are required');
            }
            $result = stakeTokens($walletManager, $address, $amount, $period, $privateKey);
            break;
            
        case 'generate_mnemonic':
            $result = generateMnemonic($walletManager);
            break;
            
        case 'debug_logs':
            // Debug endpoint to check logging status and recent entries
            $baseDir = dirname(__DIR__);
            $logFile = $baseDir . '/logs/wallet_api.log';
            $logExists = file_exists($logFile);
            $lastLines = [];
            if ($logExists) {
                $content = file_get_contents($logFile);
                $lines = explode("\n", $content);
                $lastLines = array_slice(array_filter($lines), -10); // Last 10 non-empty lines
            }
            
            // Force a test log entry
            writeLog("DEBUG: Test log entry at " . date('Y-m-d H:i:s') . " from debug_logs action");
            
            $result = [
                'debug_info' => [
                    'log_file_exists' => $logExists,
                    'log_file_path' => $logFile,
                    'log_file_size' => $logExists ? filesize($logFile) : 0,
                    'last_10_lines' => $lastLines,
                    'logger_enabled' => class_exists('WalletLogger'),
                    'test_entry_written' => true
                ]
            ];
            break;
            
        case 'get_config':
            $result = getConfigInfo($config, $networkConfig);
            break;
            
        case 'create_wallet_from_mnemonic':
            $mnemonicInput = $input['mnemonic'] ?? [];
            $mnemonic = normalizeMnemonicInput($mnemonicInput);
            if (empty($mnemonic)) {
                throw new Exception('Mnemonic phrase is required');
            }
            if (!in_array(count($mnemonic), [12,15,18,21,24], true)) {
                throw new Exception('Invalid mnemonic word count. Expected 12/15/18/21/24 words, got ' . count($mnemonic));
            }
            try {
                $result = createWalletFromMnemonic($walletManager, $blockchainManager, $mnemonic);
                if (!isset($result['existing'])) {
                    $result['existing'] = false;
                }
            } catch (Exception $e) {
                if (strpos($e->getMessage(), "Wallet with this mnemonic already exists") !== false) {
                    // Fallback idempotent response if underlying function still throws (e.g. stale opcode cache)
                    writeLog('Fallback idempotent handling triggered for existing mnemonic', 'INFO');
                    $keyPair = \Blockchain\Core\Cryptography\KeyPair::fromMnemonic($mnemonic);
                    $result = [
                        'address' => $keyPair->getAddress(),
                        'public_key' => $keyPair->getPublicKey(),
                        'private_key' => $keyPair->getPrivateKey(),
                        'mnemonic' => implode(' ', $mnemonic),
                        'existing' => true,
                        'message' => 'Idempotent success (handled in switch): wallet already existed.'
                    ];
                } else {
                    throw $e;
                }
            }
            break;
            
        case 'validate_mnemonic':
            $mnemonicInput = $input['mnemonic'] ?? [];
            $mnemonic = normalizeMnemonicInput($mnemonicInput);
            if (empty($mnemonic)) {
                throw new Exception('Mnemonic phrase is required');
            }
            $result = validateMnemonic($mnemonic);
            break;
            
        case 'restore_wallet_from_mnemonic':
            $mnemonicInput = $input['mnemonic'] ?? [];
            $mnemonic = normalizeMnemonicInput($mnemonicInput);
            if (empty($mnemonic)) {
                throw new Exception('Mnemonic phrase is required');
            }
            if (!in_array(count($mnemonic), [12,15,18,21,24], true)) {
                throw new Exception('Invalid mnemonic word count. Expected 12/15/18/21/24 words, got ' . count($mnemonic));
            }
            $result = restoreWalletFromMnemonic($walletManager, $blockchainManager, $mnemonic);
            break;
            
        case 'sync_binary_to_db':
            $result = syncBinaryToDatabase($walletManager);
            break;
            
        case 'sync_db_to_binary':
            $result = syncDatabaseToBinary($walletManager);
            break;
            
        case 'validate_blockchain':
            $result = validateBlockchain($walletManager);
            break;
            
        case 'blockchain_stats':
            $result = getBlockchainStats($walletManager);
            break;
            
        case 'create_backup':
            $backupPath = $input['backup_path'] ?? null;
            $result = createBackup($walletManager, $backupPath);
            break;
            
        case 'get_wallet_transaction_history':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Wallet address is required');
            }
            $result = getWalletTransactionHistory($blockchainManager, $address);
            break;
            
        case 'verify_wallet_in_blockchain':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Wallet address is required');
            }
            $result = verifyWalletInBlockchain($blockchainManager, $address);
            break;
            
        case 'transfer_tokens':
            $fromAddress = $input['from_address'] ?? '';
            $toAddress = $input['to_address'] ?? '';
            $amount = $input['amount'] ?? 0;
            $privateKey = $input['private_key'] ?? '';
            $memo = $input['memo'] ?? '';
            
            if (!$fromAddress || !$toAddress || !$amount || !$privateKey) {
                throw new Exception('From address, to address, amount and private key are required');
            }
            
            $result = transferTokens($walletManager, $blockchainManager, $fromAddress, $toAddress, $amount, $privateKey, $memo);
            break;
            
        case 'decrypt_message':
            $encryptedMessage = $input['encrypted_message'] ?? '';
            $privateKey = $input['private_key'] ?? '';
            $senderPublicKey = $input['sender_public_key'] ?? '';
            
            if (!$encryptedMessage || !$privateKey) {
                throw new Exception('Encrypted message and private key are required');
            }
            
            $result = decryptMessage($encryptedMessage, $privateKey, $senderPublicKey);
            break;
            
        case 'get_transaction_history':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Address is required');
            }
            $result = getTransactionHistory($walletManager, $address);
            break;
            
        case 'decrypt_transaction_message':
            $txHash = $input['tx_hash'] ?? '';
            $walletAddress = $input['wallet_address'] ?? '';
            $privateKey = $input['private_key'] ?? '';
            
            if (!$txHash || !$walletAddress || !$privateKey) {
                throw new Exception('Transaction hash, wallet address and private key are required');
            }
            
            $result = decryptTransactionMessage($walletManager, $txHash, $walletAddress, $privateKey);
            break;
            
        case 'get_transaction':
            $hash = $input['hash'] ?? '';
            if (!$hash) {
                throw new Exception('Transaction hash is required');
            }
            
            $transaction = $walletManager->getTransactionByHash($hash);
            if (!$transaction) {
                throw new Exception('Transaction not found');
            }
            
            $result = [
                'success' => true,
                'transaction' => $transaction
            ];
            break;
            
        case 'stake_tokens_new':
            $address = $input['address'] ?? '';
            $amount = $input['amount'] ?? 0;
            $period = $input['period'] ?? 30;
            $privateKey = $input['private_key'] ?? '';
            
            if (!$address || !$amount || !$privateKey) {
                throw new Exception('Address, amount and private key are required');
            }
            
            $result = stakeTokensWithBlockchain($walletManager, $blockchainManager, $address, $amount, $period, $privateKey);
            break;
            
        case 'unstake_tokens':
            $address = $input['address'] ?? '';
            $amount = $input['amount'] ?? 0;
            $privateKey = $input['private_key'] ?? '';
            
            if (!$address || !$amount || !$privateKey) {
                throw new Exception('Address, amount and private key are required');
            }
            
            $result = unstakeTokens($walletManager, $blockchainManager, $address, $amount, $privateKey);
            break;

        case 'get_staking_contract':
            // Resolve or auto-deploy staking contract and return its address
            $addr = getOrDeployStakingContract($pdo, $input['deployer'] ?? '0x0000000000000000000000000000000000000000');
            if (!$addr) {
                throw new Exception('Staking contract not available');
            }
            $result = ['staking_contract' => $addr];
            break;
            
        case 'get_staking_info':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Wallet address is required');
            }
            $result = getStakingInfo($walletManager, $address);
            break;
            
        case 'get_blockchain_wallet_info':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Wallet address is required');
            }
            $result = getBlockchainWalletInfo($blockchainManager, $walletManager, $address);
            break;
            
        case 'activate_restored_wallet':
            $address = $input['address'] ?? '';
            $publicKey = $input['public_key'] ?? '';
            if (!$address || !$publicKey) {
                throw new Exception('Address and public_key are required');
            }
            $result = activateRestoredWallet($walletManager, $blockchainManager, $address, $publicKey);
            break;
            
        case 'delete_wallet':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Wallet address is required');
            }
            $result = deleteWallet($walletManager, $address);
            break;

        case 'rpc':
            // Minimal JSON-RPC over query/body: ?action=rpc&method=eth_chainId or JSON body handled above
            $rpcMethod = $input['method'] ?? ($_GET['method'] ?? '');
            // params[] can be passed via query string or JSON body
            $rpcParams = $input['params'] ?? ($_GET['params'] ?? []);
            if (is_string($rpcParams)) {
                // Allow params to be JSON string in query
                $decoded = json_decode($rpcParams, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $rpcParams = $decoded;
                } else {
                    $rpcParams = [$rpcParams];
                }
            }
            $rpcResult = handleRpcRequest($pdo, $walletManager, $networkConfig, $rpcMethod, $rpcParams);
            $result = ['rpc' => $rpcResult];
            break;
            
        default:
            throw new Exception('Unknown action: ' . $action);
    }
    
    $responsePayload = [
        'success' => true,
        ...$result
    ];
    try { \Blockchain\Wallet\WalletLogger::debug("RESP $requestId: action=$action keys=" . implode(',', array_keys($responsePayload))); } catch (Throwable $e) {}
    
    // Force commit any pending transaction before sending response
    try {
        if ($pdo && $pdo->inTransaction()) {
            writeLog("Forcing commit before response", 'INFO');
            $pdo->commit();
            writeLog("Transaction committed before response", 'INFO');
        }
    } catch (Exception $e) {
        writeLog("Failed to commit before response: " . $e->getMessage(), 'ERROR');
        try {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
                writeLog("Transaction rolled back before response", 'INFO');
            }
        } catch (Exception $e2) {
            writeLog("Failed to rollback before response: " . $e2->getMessage(), 'ERROR');
        }
    }
    
    echo json_encode($responsePayload);
    
} catch (Exception $e) {
    // Log full error info
    $errorInfo = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'action' => $input['action'] ?? 'unknown',
        'timestamp' => date('Y-m-d H:i:s'),
        'input_data' => $input ?? []
    ];
    
    // Write to logs
    writeLog("Wallet API Error: " . json_encode($errorInfo), 'ERROR');
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug_info' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'action' => $input['action'] ?? 'unknown',
            'trace' => explode("\n", $e->getTraceAsString())
        ]
    ]);
}

/**
 * Create a new wallet via WalletManager
 */
function createWallet($walletManager, $blockchainManager) {
    try {
        writeLog("Creating new wallet with blockchain integration", 'INFO');
        
    // 1. Create wallet using WalletManager
        $walletData = $walletManager->createWallet();
        writeLog("Wallet created successfully: " . $walletData['address'], 'INFO');
        
    // 2. Record wallet creation in blockchain
        $blockchainResult = $blockchainManager->createWalletWithBlockchain($walletData);
        writeLog("Blockchain recording result: " . json_encode($blockchainResult['blockchain_recorded']), 'INFO');
        
    // 3. Return combined result
        return [
            'wallet' => $walletData,
            'blockchain' => [
                'recorded' => $blockchainResult['blockchain_recorded'],
                'transaction_hash' => $blockchainResult['transaction']['hash'] ?? null,
                'block_hash' => $blockchainResult['block']['hash'] ?? null,
                'block_height' => $blockchainResult['block']['height'] ?? null
            ]
        ];
    } catch (Exception $e) {
        writeLog("Error creating wallet: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to create wallet: ' . $e->getMessage());
    }
}

/**
 * List wallets via WalletManager
 */
function listWallets($walletManager) {
    try {
        $wallets = $walletManager->listWallets(20);
        return [
            'wallets' => $wallets
        ];
    } catch (Exception $e) {
        throw new Exception('Failed to list wallets: ' . $e->getMessage());
    }
}

/**
 * Get wallet balance via WalletManager
 */
function getBalance($walletManager, $address) {
    try {
        $availableBalance = $walletManager->getAvailableBalance($address);
        $stakedBalance = $walletManager->getStakedBalance($address);
        $totalBalance = $availableBalance + $stakedBalance;
        
        return [
            'balance' => [
                'available' => $availableBalance,
                'staked' => $stakedBalance,
                'total' => $totalBalance
            ]
        ];
    } catch (Exception $e) {
        throw new Exception('Failed to get balance: ' . $e->getMessage());
    }
}

/**
 * Get wallet information via WalletManager
 */
function getWalletInfo($walletManager, $address) {
    try {
        $walletInfo = $walletManager->getWalletInfo($address);
        $stats = $walletManager->getWalletStats($address);
        
        return [
            'wallet' => $walletInfo,
            'stats' => $stats
        ];
    } catch (Exception $e) {
        throw new Exception('Failed to get wallet info: ' . $e->getMessage());
    }
}

/**
 * Stake tokens via WalletManager
 */
function stakeTokens($walletManager, $address, $amount, $period, $privateKey) {
    try {
        $result = $walletManager->stake($address, $amount, $privateKey);
        
        if ($result) {
            // Fetch updated balances
            $availableBalance = $walletManager->getAvailableBalance($address);
            $stakedBalance = $walletManager->getStakedBalance($address);
            
            return [
                'staked' => [
                    'success' => true,
                    'amount' => $amount,
                    'period' => $period,
                    'new_balances' => [
                        'available' => $availableBalance,
                        'staked' => $stakedBalance,
                        'total' => $availableBalance + $stakedBalance
                    ]
                ]
            ];
        } else {
            throw new Exception('Staking operation failed');
        }
    } catch (Exception $e) {
        throw new Exception('Failed to stake tokens: ' . $e->getMessage());
    }
}

/**
 * Generate a new mnemonic phrase
 */
function normalizeMnemonicInput($input): array {
    // Accept: array of words, space-delimited string, string with extra spaces, JSON string
    if (is_string($input)) {
        $trim = trim($input);
        if ($trim === '') { return []; }
        // If JSON encoded array
        if ((str_starts_with($trim, '[') && str_ends_with($trim, ']')) || (str_starts_with($trim, '"') && str_ends_with($trim, '"'))) {
            $decoded = json_decode($trim, true);
            if (is_array($decoded)) { $input = $decoded; }
        }
        if (is_string($input)) {
            $parts = preg_split('/\s+/', strtolower($input));
            $input = array_values(array_filter($parts, fn($w)=>$w !== ''));
        }
    }
    if (!is_array($input)) { return []; }
    // Normalize: lowercase, trim, remove empties
    $words = [];
    foreach ($input as $w) {
        if (!is_string($w)) continue;
        $w = strtolower(trim($w));
        if ($w === '') continue;
        $words[] = $w;
    }
    return $words;
}

function generateMnemonic($walletManager) {
    try {
        $mnemonic = $walletManager->generateMnemonic();
        return [
            'mnemonic' => $mnemonic
        ];
    } catch (Exception $e) {
        throw new Exception('Failed to generate mnemonic: ' . $e->getMessage());
    }
}

/**
 * Create a wallet from a mnemonic phrase
 */
function createWalletFromMnemonic($walletManager, $blockchainManager, array $mnemonic) {
    try {
        writeLog("Creating wallet from mnemonic with blockchain integration", 'INFO');
        writeLog("Mnemonic word count: " . count($mnemonic), 'DEBUG');
        writeLog("Mnemonic words: " . implode(' ', $mnemonic), 'DEBUG');
        
        // 1. First, derive address from mnemonic WITHOUT creating wallet record
        writeLog("Deriving address from mnemonic to check if wallet already exists", 'DEBUG');
        try {
            // Use KeyPair to derive address without creating database record
            $keyPair = \Blockchain\Core\Cryptography\KeyPair::fromMnemonic($mnemonic);
            $derivedAddress = $keyPair->getAddress();
            writeLog("Address derived from mnemonic: " . $derivedAddress, 'DEBUG');
            
            // Check if this address already exists in database
            $existingWallet = $walletManager->getWalletInfo($derivedAddress);
            if ($existingWallet) {
                // Idempotent behavior: instead of error, return existing wallet info
                writeLog("Wallet already exists in database, returning existing info (idempotent create)", 'INFO');
                return [
                    'address' => $derivedAddress,
                    'public_key' => $existingWallet['public_key'] ?? $keyPair->getPublicKey(),
                    // We can safely return the private key because the caller proved knowledge of the mnemonic
                    'private_key' => $keyPair->getPrivateKey(),
                    'mnemonic' => implode(' ', $mnemonic),
                    'existing' => true,
                    'message' => 'Wallet already existed. This create call is idempotent. Use restore endpoint for future explicit restores.'
                ];
            }
            writeLog("Wallet does not exist in database, proceeding with creation", 'DEBUG');
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                throw $e; // Re-throw our custom message
            }
            writeLog("Error deriving address from mnemonic: " . $e->getMessage(), 'ERROR');
            throw new Exception("Invalid mnemonic phrase: " . $e->getMessage());
        }
        
        // 2. Create wallet using WalletManager
        writeLog("Calling WalletManager::createWalletFromMnemonic", 'DEBUG');
        $walletData = $walletManager->createWalletFromMnemonic($mnemonic);
        writeLog("Wallet created from mnemonic: " . $walletData['address'], 'INFO');
        
        // 3. Record wallet creation in blockchain (non-blocking)
        writeLog("Recording wallet creation in blockchain", 'DEBUG');
        $blockchainRecorded = false;
        $blockchainError = null;
        try {
            $blockchainResult = $blockchainManager->createWalletWithBlockchain($walletData);
            $blockchainRecorded = $blockchainResult['blockchain_recorded'];
            writeLog("Blockchain recording result: " . json_encode($blockchainRecorded), 'INFO');
        } catch (Exception $blockchainException) {
            writeLog("Blockchain recording failed: " . $blockchainException->getMessage(), 'WARNING');
            $blockchainError = $blockchainException->getMessage();
            // Don't throw - wallet should still be created even if blockchain fails
        }
        
        // 4. Return combined result
        $result = [
            'wallet' => $walletData,
            'blockchain' => [
                'recorded' => $blockchainRecorded,
                'error' => $blockchainError,
                'transaction_hash' => isset($blockchainResult) ? ($blockchainResult['transaction']['hash'] ?? null) : null,
                'block_hash' => isset($blockchainResult) ? ($blockchainResult['block']['hash'] ?? null) : null,
                'block_height' => isset($blockchainResult) ? ($blockchainResult['block']['height'] ?? null) : null
            ]
        ];
        
        writeLog("Wallet creation from mnemonic completed successfully", 'INFO');
        return $result;
        
    } catch (Exception $e) {
        writeLog("Error creating wallet from mnemonic: " . $e->getMessage(), 'ERROR');
        writeLog("Exception trace: " . $e->getTraceAsString(), 'DEBUG');
        throw new Exception('Failed to create wallet from mnemonic: ' . $e->getMessage());
    }
}

/**
 * Validate a mnemonic phrase
 */
function validateMnemonic(array $mnemonic) {
    try {
        $isValid = \Blockchain\Core\Cryptography\Mnemonic::validate($mnemonic);
        return [
            'valid' => $isValid
        ];
    } catch (Exception $e) {
        throw new Exception('Failed to validate mnemonic: ' . $e->getMessage());
    }
}

/**
 * Restore a wallet from a mnemonic phrase
 */
function restoreWalletFromMnemonic($walletManager, $blockchainManager, array $mnemonic) {
    try {
        writeLog("Starting wallet restoration from mnemonic", 'INFO');
        writeLog("Mnemonic word count: " . count($mnemonic), 'DEBUG');
        writeLog("Mnemonic words: " . implode(' ', $mnemonic), 'DEBUG');
        
        // 1. Restore wallet using WalletManager
        writeLog("Calling WalletManager::restoreWalletFromMnemonic", 'DEBUG');
        $walletData = $walletManager->restoreWalletFromMnemonic($mnemonic);
        writeLog("Wallet restored: " . $walletData['address'] . " from: " . ($walletData['restored_from'] ?? 'unknown'), 'INFO');
        
    // 2. Check transaction history for additional context
        writeLog("Getting wallet transaction history", 'DEBUG');
        $transactionHistory = $blockchainManager->getWalletTransactionHistory($walletData['address']);
        writeLog("Verifying wallet in blockchain", 'DEBUG');
        $isVerified = $blockchainManager->verifyWalletInBlockchain($walletData['address']);
        
        writeLog("Wallet verification in blockchain: " . ($isVerified ? 'FOUND' : 'NOT_FOUND'), 'INFO');
        writeLog("Transaction history count: " . count($transactionHistory), 'INFO');
        
    // 3. If the wallet needs blockchain registration - register it
        $blockchainRegistered = false;
        $blockchainError = null;
        
        if (isset($walletData['needs_blockchain_registration']) && $walletData['needs_blockchain_registration'] && !$isVerified) {
            writeLog("Wallet needs blockchain registration, registering now", 'INFO');
            try {
                $blockchainResult = $blockchainManager->createWalletWithBlockchain($walletData);
                $blockchainRegistered = $blockchainResult['blockchain_recorded'];
                writeLog("Blockchain registration result: " . json_encode($blockchainRegistered), 'INFO');
            } catch (Exception $blockchainException) {
                writeLog("Blockchain registration failed: " . $blockchainException->getMessage(), 'WARNING');
                $blockchainError = $blockchainException->getMessage();
                // Don't throw - wallet is still restored in database
            }
        } else {
            $blockchainRegistered = $isVerified; // Already verified
        }
        
        // 4. Return result with blockchain registration status
        $result = [
            'wallet' => $walletData,
            'public_key' => $walletData['public_key'] ?? null,
            'private_key' => $walletData['private_key'] ?? null,
            'restored' => true,
            'blockchain' => [
                'registered' => $blockchainRegistered,
                'error' => $blockchainError,
                'was_already_verified' => $isVerified
            ],
            'verification' => [
                'exists_in_blockchain' => $blockchainRegistered,
                'transaction_count' => count($transactionHistory),
                'last_activity' => !empty($transactionHistory) ? $transactionHistory[0]['block_timestamp'] ?? null : null
            ],
            'note' => $blockchainRegistered ? 
                     'Wallet restored and registered in blockchain successfully.' : 
                     'Wallet restored in database. Blockchain registration may be needed.'
        ];
        
        writeLog("Wallet restoration completed successfully", 'INFO');
        return $result;
        
    } catch (Exception $e) {
        writeLog("Exception in restoreWalletFromMnemonic: " . $e->getMessage(), 'ERROR');
        writeLog("Exception trace: " . $e->getTraceAsString(), 'DEBUG');
        throw new Exception('Failed to restore wallet: ' . $e->getMessage());
    }
}

/**
 * Get configuration information
 */
function getConfigInfo(array $config, ?\Blockchain\Core\Config\NetworkConfig $networkConfig = null) {
    // Load settings from DB when available
    if ($networkConfig) {
        $tokenInfo = $networkConfig->getTokenInfo();
        $networkInfo = $networkConfig->getNetworkInfo();
        
        return [
            'config' => [
                'crypto_symbol' => $tokenInfo['symbol'],
                'crypto_name' => $tokenInfo['name'],
                'crypto_decimals' => $tokenInfo['decimals'],
                'initial_supply' => $tokenInfo['initial_supply'],
                'network_name' => $networkInfo['name'],
                'chain_id' => $networkInfo['chain_id'],
                'consensus_algorithm' => $networkInfo['consensus_algorithm'],
                'min_stake_amount' => $networkInfo['min_stake'],
                'block_time' => $networkInfo['block_time'],
                'protocol_version' => $networkInfo['protocol_version']
            ]
        ];
    }
    
    // Fallback to static configuration
    return [
        'config' => [
            'crypto_symbol' => $config['crypto']['symbol'] ?? 'COIN',
            'crypto_name' => $config['crypto']['name'] ?? 'Blockchain',
            'network' => $config['crypto']['network'] ?? 'mainnet'
        ]
    ];
}

/**
 * Synchronize binary blockchain to database
 */
function syncBinaryToDatabase($walletManager) {
    try {
        writeLog("Starting binary to database synchronization", 'INFO');
        
        $result = $walletManager->syncBinaryToDatabase();
        
        writeLog("Binary to database sync completed: {$result['exported']} exported, {$result['errors']} errors", 'INFO');
        
        return [
            'sync_result' => $result,
            'success' => $result['errors'] === 0
        ];
    } catch (Exception $e) {
        writeLog("Binary to database sync failed: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to sync binary to database: ' . $e->getMessage());
    }
}

/**
 * Synchronize database to binary blockchain
 */
function syncDatabaseToBinary($walletManager) {
    try {
        writeLog("Starting database to binary synchronization", 'INFO');
        
        $result = $walletManager->syncDatabaseToBinary();
        
        writeLog("Database to binary sync completed: {$result['imported']} imported, {$result['errors']} errors", 'INFO');
        
        return [
            'sync_result' => $result,
            'success' => $result['errors'] === 0
        ];
    } catch (Exception $e) {
        writeLog("Database to binary sync failed: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to sync database to binary: ' . $e->getMessage());
    }
}

/**
 * Validate blockchain integrity
 */
function validateBlockchain($walletManager) {
    try {
        writeLog("Starting blockchain validation", 'INFO');
        
        $validation = $walletManager->validateBlockchain();
        
        $status = $validation['valid'] ? 'valid' : 'invalid';
        writeLog("Blockchain validation completed: $status", $validation['valid'] ? 'INFO' : 'ERROR');
        
        return [
            'validation' => $validation,
            'is_valid' => $validation['valid'],
            'errors_count' => count($validation['errors'] ?? []),
            'warnings_count' => count($validation['warnings'] ?? [])
        ];
    } catch (Exception $e) {
        writeLog("Blockchain validation failed: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to validate blockchain: ' . $e->getMessage());
    }
}

/**
 * Get comprehensive blockchain statistics
 */
function getBlockchainStats($walletManager) {
    try {
        writeLog("Retrieving blockchain statistics", 'DEBUG');
        
        $stats = $walletManager->getBlockchainStats();
        
        return [
            'stats' => $stats,
            'summary' => [
                'total_blocks' => $stats['binary']['total_blocks'] ?? 0,
                'total_transactions' => $stats['binary']['total_transactions'] ?? 0,
                'blockchain_size' => $stats['binary']['size_formatted'] ?? '0 B',
                'database_blocks' => $stats['database']['blocks'] ?? 0,
                'database_transactions' => $stats['database']['transactions'] ?? 0,
                'database_wallets' => $stats['database']['wallets'] ?? 0
            ]
        ];
    } catch (Exception $e) {
        writeLog("Failed to get blockchain stats: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to get blockchain statistics: ' . $e->getMessage());
    }
}

/**
 * Create blockchain backup
 */
function createBackup($walletManager, ?string $customPath = null) {
    try {
        $backupPath = $customPath ?? dirname(__DIR__) . '/storage/backups/blockchain_backup_' . date('Y-m-d_H-i-s') . '.zip';
        
        writeLog("Creating blockchain backup at: $backupPath", 'INFO');
        
        // Ensure backup directory exists
        $backupDir = dirname($backupPath);
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $success = $walletManager->createBackup($backupPath);
        
        if ($success) {
            $backupSize = file_exists($backupPath) ? filesize($backupPath) : 0;
            writeLog("Backup created successfully: " . number_format($backupSize) . " bytes", 'INFO');
            
            return [
                'backup_created' => true,
                'backup_path' => $backupPath,
                'backup_size' => $backupSize,
                'backup_size_formatted' => formatBytes($backupSize)
            ];
        } else {
            throw new Exception('Backup creation returned false');
        }
        
    } catch (Exception $e) {
        writeLog("Backup creation failed: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to create backup: ' . $e->getMessage());
    }
}

/**
 * Format bytes to human readable format
 */
function formatBytes(int $bytes): string {
    if ($bytes === 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $factor = floor(log($bytes, 1024));
    return sprintf('%.1f %s', $bytes / (1024 ** $factor), $units[$factor] ?? 'TB');
}

/**
 * Get wallet transaction history from blockchain
 */
function getWalletTransactionHistory($blockchainManager, string $address) {
    try {
        writeLog("Getting transaction history for wallet: " . $address, 'INFO');
        
        $transactions = $blockchainManager->getWalletTransactionHistory($address);
        
        writeLog("Found " . count($transactions) . " transactions for wallet: " . $address, 'INFO');
        
        return [
            'address' => $address,
            'transactions' => $transactions,
            'transaction_count' => count($transactions)
        ];
    } catch (Exception $e) {
        writeLog("Error getting wallet transaction history: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to get transaction history: ' . $e->getMessage());
    }
}

/**
 * Verify wallet exists in blockchain
 */
function verifyWalletInBlockchain($blockchainManager, string $address) {
    try {
        writeLog("Verifying wallet in blockchain: " . $address, 'INFO');
        
        $exists = $blockchainManager->verifyWalletInBlockchain($address);
        
        writeLog("Wallet verification result for " . $address . ": " . ($exists ? 'EXISTS' : 'NOT_FOUND'), 'INFO');
        
        return [
            'address' => $address,
            'exists_in_blockchain' => $exists,
            'verified' => $exists
        ];
    } catch (Exception $e) {
        writeLog("Error verifying wallet in blockchain: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to verify wallet: ' . $e->getMessage());
    }
}

/**
 * Get comprehensive wallet information from both database and blockchain
 */
function getBlockchainWalletInfo($blockchainManager, $walletManager, string $address) {
    try {
        writeLog("Getting comprehensive wallet info for: " . $address, 'INFO');
        
        // Get wallet info from database
        $walletInfo = $walletManager->getWalletInfo($address);
        
        // Get balance from wallet manager
        $balance = $walletManager->getBalance($address);
        $availableBalance = $walletManager->getAvailableBalance($address);
        $stakedBalance = $walletManager->getStakedBalance($address);
        
        // Verify wallet in blockchain
        $blockchainExists = $blockchainManager->verifyWalletInBlockchain($address);
        
        // Get transaction history
        $transactions = $blockchainManager->getWalletTransactionHistory($address);
        
        writeLog("Comprehensive wallet info gathered for: " . $address, 'INFO');
        
        return [
            'address' => $address,
            'database_info' => $walletInfo,
            'balances' => [
                'total' => $balance,
                'available' => $availableBalance,
                'staked' => $stakedBalance
            ],
            'blockchain' => [
                'exists' => $blockchainExists,
                'transaction_count' => count($transactions),
                'recent_transactions' => array_slice($transactions, 0, 5) // Last 5 transactions
            ],
            'synchronized' => $walletInfo && $blockchainExists
        ];
    } catch (Exception $e) {
        writeLog("Error getting comprehensive wallet info: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to get wallet info: ' . $e->getMessage());
    }
}

/**
 * Activate a restored wallet in the blockchain
 */
function activateRestoredWallet($walletManager, $blockchainManager, string $address, string $publicKey) {
    try {
        writeLog("Activating restored wallet in blockchain: $address", 'INFO');
        
    // Ensure the wallet has actually been restored
        writeLog("Checking if wallet exists in database", 'DEBUG');
        $walletInfo = $walletManager->getWalletInfo($address);
        writeLog("Wallet info check result: " . ($walletInfo ? 'FOUND' : 'NOT_FOUND'), 'DEBUG');
        
        if (!$walletInfo) {
            // Attempt automatic restoration if the wallet exists on the blockchain
            writeLog("Wallet not found in database, checking blockchain", 'INFO');
            $blockchainBalance = $walletManager->calculateBalanceFromBlockchain($address);
            $stakedBalance = $walletManager->calculateStakedBalanceFromBlockchain($address);
            
            writeLog("Blockchain balances - Available: $blockchainBalance, Staked: $stakedBalance", 'INFO');
            
            if ($blockchainBalance > 0 || $stakedBalance > 0) {
                // Wallet exists on blockchain but not in DB - restore the record
                writeLog("Wallet found in blockchain but not in database, creating record", 'INFO');
                
                $stmt = $walletManager->getDatabase()->prepare("
                    INSERT INTO wallets (address, public_key, balance, staked_balance, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE 
                    public_key = VALUES(public_key),
                    balance = VALUES(balance),
                    staked_balance = VALUES(staked_balance),
                    updated_at = NOW()
                ");
                
                $stmt->execute([$address, $publicKey, $blockchainBalance, $stakedBalance]);
                writeLog("Wallet record created/updated in database", 'INFO');
                
                // Re-fetch wallet information
                $walletInfo = $walletManager->getWalletInfo($address);
                writeLog("Wallet info after creation: " . ($walletInfo ? 'FOUND' : 'STILL_NOT_FOUND'), 'DEBUG');
            } else {
                writeLog("Wallet not found in blockchain either", 'ERROR');
                throw new Exception('Wallet not found in database or blockchain. Please restore it first.');
            }
        }
        
        if (!$walletInfo) {
            throw new Exception('Wallet not found. Please restore it first.');
        }
        
    // Check if it's already activated
        $isInBlockchain = $blockchainManager->verifyWalletInBlockchain($address);
        if ($isInBlockchain) {
            return [
                'already_active' => true,
                'message' => 'Wallet is already active in blockchain',
                'address' => $address
            ];
        }
        
    // IMPORTANT: Recalculate balance from blockchain before activation
        $blockchainBalance = $walletManager->calculateBalanceFromBlockchain($address);
        $stakedBalance = $walletManager->calculateStakedBalanceFromBlockchain($address);
        
        writeLog("Calculated balances - Available: $blockchainBalance, Staked: $stakedBalance", 'INFO');
        
    // Update wallet balance
        writeLog("Updating wallet balances in database", 'INFO');
        if ($blockchainBalance > 0) {
            $walletManager->updateBalance($address, $blockchainBalance);
            writeLog("Updated available balance to: $blockchainBalance", 'INFO');
        }
        if ($stakedBalance > 0) {
            $walletManager->updateStakedBalance($address, $stakedBalance);
            writeLog("Updated staked balance to: $stakedBalance", 'INFO');
        }
        
    // Create activation transaction with correct balance
        $walletData = [
            'address' => $address,
            'public_key' => $publicKey,
            'balance' => $blockchainBalance,
            'staked_balance' => $stakedBalance,
            'restored' => true
        ];
        
        writeLog("Created wallet data for blockchain activation: " . json_encode($walletData), 'INFO');
        
    // Record activation on the blockchain (without changing balance)
        writeLog("Starting blockchain activation process", 'INFO');
        try {
            $blockchainResult = $blockchainManager->createWalletWithBlockchain($walletData);
            writeLog("Blockchain activation completed successfully", 'INFO');
        } catch (Exception $blockchainError) {
            writeLog("Blockchain activation failed: " . $blockchainError->getMessage(), 'ERROR');
            writeLog("Blockchain error trace: " . $blockchainError->getTraceAsString(), 'ERROR');
            throw $blockchainError;
        }
        
        writeLog("Wallet activated in blockchain with balance: $blockchainBalance", 'INFO');
        
        return [
            'activated' => true,
            'address' => $address,
            'balance' => $blockchainBalance,
            'staked_balance' => $stakedBalance,
            'blockchain' => [
                'recorded' => $blockchainResult['blockchain_recorded'],
                'transaction_hash' => $blockchainResult['transaction']['hash'] ?? null,
                'block_hash' => $blockchainResult['block']['hash'] ?? null,
                'block_height' => $blockchainResult['block']['height'] ?? null
            ],
            'message' => 'Wallet successfully activated in blockchain'
        ];
        
    } catch (Exception $e) {
        writeLog("Error activating restored wallet: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to activate wallet: ' . $e->getMessage());
    }
}

/**
 * Transfer tokens between wallets with blockchain recording
 */
function transferTokens($walletManager, $blockchainManager, string $fromAddress, string $toAddress, float $amount, string $privateKey, string $memo = '') {
    try {
        writeLog("Starting token transfer: $fromAddress -> $toAddress, amount: $amount", 'INFO');
        
        // 0. Check and cleanup any active transactions
        $pdo = $walletManager->getDatabase();
        try {
            // Try to check if transaction is active
            if ($pdo->inTransaction()) {
                writeLog("Found active PDO transaction, rolling back to start fresh", 'WARNING');
                $pdo->rollBack();
            }
        } catch (Exception $e) {
            writeLog("PDO transaction state check failed: " . $e->getMessage(), 'DEBUG');
            // Continue anyway, error might be that there's no transaction
        }
        
        // 1. Validate addresses
        if ($fromAddress === $toAddress) {
            throw new Exception('Cannot transfer to the same address');
        }
        
        // 2. Check sender balance
        $senderBalance = $walletManager->getAvailableBalance($fromAddress);
        if ($senderBalance < $amount) {
            throw new Exception("Insufficient balance. Available: $senderBalance, Required: $amount");
        }
        
        // 3. Verify private key (simplified - in production use proper cryptographic verification)
        if (strlen($privateKey) < 32) {
            throw new Exception('Invalid private key format');
        }
        
        // 4. Handle memo encryption
        $finalMemo = '';
        $encryptedData = null;
        if (!empty($memo)) {
            try {
                // Check memo length
                if (strlen($memo) > 1000) {
                    throw new Exception('Message is too long. Maximum 1000 characters allowed.');
                }
                
                // Get recipient public key for encryption
                $recipientWallet = $walletManager->getWalletByAddress($toAddress);
                writeLog("Looking for recipient wallet: $toAddress", 'INFO');
                writeLog("Recipient wallet found: " . ($recipientWallet ? 'YES' : 'NO'), 'INFO');
                writeLog("Recipient wallet data: " . json_encode($recipientWallet), 'DEBUG');
                
                if ($recipientWallet && !empty($recipientWallet['public_key'])) {
                    writeLog("Recipient has public key, proceeding with encryption", 'INFO');
                    writeLog("Recipient public key: " . $recipientWallet['public_key'], 'DEBUG');
                    
                    // Encrypt messages using MessageEncryption with secp256k1 keys
                    $encryptedData = \Blockchain\Core\Cryptography\MessageEncryption::createSecureMessage(
                        $memo, 
                        $recipientWallet['public_key'], 
                        $privateKey
                    );
                    writeLog("Message encrypted and signed for recipient using ECIES", 'INFO');
                } else {
                    // Recipient wallet not found or no public key - this is a security issue
                    throw new Exception("Recipient public key not found. Cannot encrypt message for address: $toAddress. All messages must be encrypted.");
                }
            } catch (Exception $e) {
                writeLog("Encryption failed: " . $e->getMessage(), 'ERROR');
                throw $e; // Re-throw to maintain security
            }
        }
        
        // 5. Check if recipient wallet exists, create if needed
        $recipientInfo = $walletManager->getWalletInfo($toAddress);
        if (!$recipientInfo) {
            writeLog("Recipient wallet not found in database, checking if we can create it", 'WARNING');
            
            // Try to create recipient wallet entry if we have enough information
            // This can happen when someone restores a wallet but it wasn't properly saved
            
            // We can derive the public key from the address if needed, but for now
            // we'll require the recipient to be properly registered
            throw new Exception('Recipient wallet not found. Please ensure the recipient has created their wallet first.');
        }
        
        // 5. Create transfer transaction
        $dynamicFee = \Blockchain\Core\Transaction\FeePolicy::computeFee($walletManager->getDatabase(), $amount);
        $transferTx = [
            'hash' => hash('sha256', 'transfer_' . $fromAddress . '_' . $toAddress . '_' . $amount . '_' . time()),
            'type' => 'transfer',
            'from' => $fromAddress,
            'to' => $toAddress,
            'amount' => $amount,
            'fee' => $dynamicFee,
            'timestamp' => time(),
            'data' => [
                'action' => 'transfer_tokens',
                'memo' => $finalMemo, // This will be empty if message was encrypted
                'transfer_type' => 'wallet_to_wallet',
                'original_memo_length' => strlen($memo)
            ],
            'signature' => hash_hmac('sha256', $fromAddress . $toAddress . $amount, $privateKey),
            'status' => 'pending'
        ];
        
        // Add encrypted data to the transaction data if available
        if ($encryptedData !== null) {
            $transferTx['data']['memo'] = $encryptedData; // Store the full encrypted message structure
            $transferTx['data']['encrypted'] = true; // Mark explicitly only when encrypted
        }
        
        // 6. Update balances in database
        $pdo = $walletManager->getDatabase();
        
        // Ensure we can start a new transaction
        if ($pdo->inTransaction()) {
            writeLog("PDO already in transaction, committing previous transaction", 'WARNING');
            try {
                $pdo->commit();
            } catch (Exception $e) {
                writeLog("Failed to commit previous transaction: " . $e->getMessage(), 'WARNING');
                try {
                    $pdo->rollBack();
                } catch (Exception $e2) {
                    writeLog("Failed to rollback previous transaction: " . $e2->getMessage(), 'ERROR');
                }
            }
        }
        
        $pdo->beginTransaction();
        
        try {
            // Deduct from sender
            $stmt = $pdo->prepare("UPDATE wallets SET balance = balance - ?, updated_at = NOW() WHERE address = ?");
            $stmt->execute([$amount + $transferTx['fee'], $fromAddress]);
            
            // Add to recipient
            $stmt = $pdo->prepare("UPDATE wallets SET balance = balance + ?, updated_at = NOW() WHERE address = ?");
            $stmt->execute([$amount, $toAddress]);
            
            $pdo->commit();
            writeLog("Database balances updated successfully", 'INFO');
            
        } catch (Exception $e) {
            try {
                $pdo->rollback();
            } catch (Exception $rollbackError) {
                writeLog("Failed to rollback transaction: " . $rollbackError->getMessage(), 'ERROR');
            }
            throw new Exception('Failed to update balances: ' . $e->getMessage());
        }
        
        // 7. Record in blockchain
        $blockchainResult = $blockchainManager->recordTransactionInBlockchain($transferTx);
        
        // 8. Update transaction status
        $transferTx['status'] = 'confirmed';
        
        writeLog("Token transfer completed successfully", 'INFO');
        
        return [
            'transaction' => $transferTx,
            'blockchain' => $blockchainResult,
            'new_balances' => [
                'sender' => $walletManager->getBalance($fromAddress),
                'recipient' => $walletManager->getBalance($toAddress)
            ]
        ];
        
    } catch (Exception $e) {
        // Ensure any pending transaction is rolled back
        try {
            $pdo = $walletManager->getDatabase();
            if ($pdo->inTransaction()) {
                writeLog("Rolling back transaction due to error", 'INFO');
                $pdo->rollBack();
            }
        } catch (Exception $cleanupError) {
            writeLog("Error during cleanup: " . $cleanupError->getMessage(), 'ERROR');
        }
        
        writeLog("Error in token transfer: " . $e->getMessage(), 'ERROR');
        throw new Exception('Transfer failed: ' . $e->getMessage());
    }
}

/**
 * Stake tokens with blockchain recording
 */
function stakeTokensWithBlockchain($walletManager, $blockchainManager, string $address, float $amount, int $period, string $privateKey) {
    try {
        writeLog("Starting token staking: address=$address, amount=$amount, period=$period", 'INFO');
        
        // 0. Check and cleanup any active transactions
        $pdo = $walletManager->getDatabase();
        try {
            // Try to check if transaction is active
            if ($pdo->inTransaction()) {
                writeLog("Found active PDO transaction, rolling back to start fresh", 'WARNING');
                $pdo->rollBack();
            }
        } catch (Exception $e) {
            writeLog("PDO transaction state check failed: " . $e->getMessage(), 'DEBUG');
            // Continue anyway, error might be that there's no transaction
        }
        
        // 1. Check available balance
        $availableBalance = $walletManager->getAvailableBalance($address);
        if ($availableBalance < $amount) {
            throw new Exception("Insufficient balance for staking. Available: $availableBalance, Required: $amount");
        }
        
        // 2. Validate staking parameters
        $minStakeAmount = 100; // Minimum stake amount
        if ($amount < $minStakeAmount) {
            throw new Exception("Minimum staking amount is $minStakeAmount tokens");
        }
        
        if ($period < 7 || $period > 365) {
            throw new Exception("Staking period must be between 7 and 365 days");
        }
        
        // 3. Calculate staking rewards (APY based on period)
        $apy = calculateStakingAPY($period);
        $expectedRewards = $amount * ($apy / 100) * ($period / 365);
        
        // 4. Ensure staking smart contract exists (deploy on first use)
        $pdo = $walletManager->getDatabase();
        $stakingContract = getOrDeployStakingContract($pdo, $address);
        if (!$stakingContract) {
            throw new Exception('Failed to deploy or obtain staking contract address');
        }

        // 5. Create staking transaction
        $stakeTx = [
            'hash' => hash('sha256', 'stake_' . $address . '_' . $amount . '_' . time()),
            'type' => 'stake',
            'from' => $address,
            // Use real staking contract address
            'to' => $stakingContract,
            'amount' => $amount,
            'fee' => 0, // No fee for staking
            'timestamp' => time(),
            'data' => [
                'action' => 'stake_tokens',
                'period_days' => $period,
                'apy' => $apy,
                'expected_rewards' => $expectedRewards,
                'unlock_date' => time() + ($period * 24 * 60 * 60),
                'stake_type' => 'fixed_term'
            ],
            'signature' => hash_hmac('sha256', $address . 'stake' . $amount, $privateKey),
            'status' => 'pending'
        ];
        
        // 6. Update balances in database
        $pdo = $walletManager->getDatabase();
        
        // Ensure we can start a new transaction
        if ($pdo->inTransaction()) {
            writeLog("PDO already in transaction, committing previous transaction", 'WARNING');
            try {
                $pdo->commit();
            } catch (Exception $e) {
                writeLog("Failed to commit previous transaction: " . $e->getMessage(), 'WARNING');
                try {
                    $pdo->rollBack();
                } catch (Exception $e2) {
                    writeLog("Failed to rollback previous transaction: " . $e2->getMessage(), 'ERROR');
                }
            }
        }
        
        $pdo->beginTransaction();
        
        try {
            // Move from available to staked balance
            $stmt = $pdo->prepare("UPDATE wallets SET balance = balance - ?, staked_balance = staked_balance + ?, updated_at = NOW() WHERE address = ?");
            $stmt->execute([$amount, $amount, $address]);
            
            // Record staking details
            $stmt = $pdo->prepare("
                INSERT INTO staking (validator, staker, amount, reward_rate, start_block, rewards_earned, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$address, $address, $amount, $apy/100, 0, $expectedRewards]);
            
            $pdo->commit();
            writeLog("Staking record created successfully", 'INFO');
            
        } catch (Exception $e) {
            try {
                $pdo->rollback();
            } catch (Exception $rollbackError) {
                writeLog("Failed to rollback transaction: " . $rollbackError->getMessage(), 'ERROR');
            }
            throw new Exception('Failed to create staking record: ' . $e->getMessage());
        }
        
        // 7. Record in blockchain
        $blockchainResult = $blockchainManager->recordTransactionInBlockchain($stakeTx);
        
        // 8. Update transaction status
        $stakeTx['status'] = 'confirmed';
        
        writeLog("Token staking completed successfully", 'INFO');
        
        return [
            'transaction' => $stakeTx,
            'blockchain' => $blockchainResult,
            'staking_info' => [
                'amount' => $amount,
                'period' => $period,
                'apy' => $apy,
                'expected_rewards' => $expectedRewards,
                'unlock_date' => date('Y-m-d H:i:s', $stakeTx['data']['unlock_date'])
            ],
            'staking_contract' => $stakingContract,
            'new_balance' => $walletManager->getBalance($address)
        ];
        
    } catch (Exception $e) {
        // Ensure any pending transaction is rolled back
        try {
            $pdo = $walletManager->getDatabase();
            if ($pdo->inTransaction()) {
                writeLog("Rolling back staking transaction due to error", 'INFO');
                $pdo->rollBack();
            }
        } catch (Exception $cleanupError) {
            writeLog("Error during staking cleanup: " . $cleanupError->getMessage(), 'ERROR');
        }
        
        writeLog("Error in token staking: " . $e->getMessage(), 'ERROR');
        throw new Exception('Staking failed: ' . $e->getMessage());
    }
}

/**
 * Calculate staking APY based on period
 */
function calculateStakingAPY(int $periodDays): float {
    // Longer periods get better rates
    if ($periodDays >= 365) return 12.0; // 12% APY for 1+ year
    if ($periodDays >= 180) return 10.0; // 10% APY for 6+ months
    if ($periodDays >= 90) return 8.0;   // 8% APY for 3+ months
    if ($periodDays >= 30) return 6.0;   // 6% APY for 1+ month
    return 4.0; // 4% APY for less than 1 month
}

/**
 * Unstake tokens
 */
function unstakeTokens($walletManager, $blockchainManager, string $address, float $amount, string $privateKey) {
    try {
        writeLog("Starting token unstaking: address=$address, amount=$amount", 'INFO');
        
        // 1. Get staking records
        $pdo = $walletManager->getDatabase();
        $stmt = $pdo->prepare("
            SELECT * 
            FROM staking 
            WHERE staker = ? AND status = 'active' 
            AND (end_block IS NOT NULL OR status = 'pending_withdrawal')
            ORDER BY created_at ASC
        ");
        $stmt->execute([$address]);
        $stakingRecords = $stmt->fetchAll();
        
        if (empty($stakingRecords)) {
            throw new Exception('No unlocked staking records found');
        }
        
        // 2. Calculate available amount to unstake
        $availableToUnstake = array_sum(array_column($stakingRecords, 'amount'));
        if ($amount > $availableToUnstake) {
            throw new Exception("Insufficient staked amount. Available: $availableToUnstake, Requested: $amount");
        }
        
        // 3. Calculate rewards
        $totalRewards = 0;
        $amountRemaining = $amount;
        $recordsToProcess = [];
        
        foreach ($stakingRecords as $record) {
            if ($amountRemaining <= 0) break;
            
            $recordAmount = min($record['amount'], $amountRemaining);
            $recordRewards = $record['rewards_earned'] * ($recordAmount / $record['amount']);
            
            $recordsToProcess[] = [
                'id' => $record['id'],
                'amount' => $recordAmount,
                'rewards' => $recordRewards
            ];
            
            $totalRewards += $recordRewards;
            $amountRemaining -= $recordAmount;
        }
        
        // 4. Ensure staking smart contract exists (needed for from-address)
        $pdo = $walletManager->getDatabase();
        $stakingContract = getOrDeployStakingContract($pdo, $address);

        // 5. Create unstaking transaction
        $unstakeTx = [
            'hash' => hash('sha256', 'unstake_' . $address . '_' . $amount . '_' . time()),
            'type' => 'unstake',
            // Use real staking contract address
            'from' => $stakingContract,
            'to' => $address,
            'amount' => $amount + $totalRewards,
            'fee' => 0,
            'timestamp' => time(),
            'data' => [
                'action' => 'unstake_tokens',
                'principal' => $amount,
                'rewards' => $totalRewards,
                'records_processed' => count($recordsToProcess)
            ],
            'signature' => hash_hmac('sha256', $address . 'unstake' . $amount, $privateKey),
            'status' => 'pending'
        ];
        
        // 6. Update database
        $pdo->beginTransaction();
        
        try {
            // Update wallet balances
            $stmt = $pdo->prepare("UPDATE wallets SET balance = balance + ?, staked_balance = staked_balance - ?, updated_at = NOW() WHERE address = ?");
            $stmt->execute([$amount + $totalRewards, $amount, $address]);
            
            // Update staking records
            foreach ($recordsToProcess as $processRecord) {
                $stmt = $pdo->prepare("UPDATE staking SET status = 'withdrawn', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$processRecord['id']]);
            }
            
            $pdo->commit();
            writeLog("Unstaking completed successfully", 'INFO');
            
        } catch (Exception $e) {
            $pdo->rollback();
            throw new Exception('Failed to process unstaking: ' . $e->getMessage());
        }
        
    // 7. Record in blockchain
        $blockchainResult = $blockchainManager->recordTransactionInBlockchain($unstakeTx);
        
        return [
            'transaction' => $unstakeTx,
            'blockchain' => $blockchainResult,
            'unstaked_amount' => $amount,
            'rewards_earned' => $totalRewards,
            'total_received' => $amount + $totalRewards,
            'new_balance' => $walletManager->getBalance($address)
        ];
        
    } catch (Exception $e) {
        writeLog("Error in token unstaking: " . $e->getMessage(), 'ERROR');
        throw new Exception('Unstaking failed: ' . $e->getMessage());
    }
}

/**
 * Get staking information for a wallet
 */
function getStakingInfo($walletManager, string $address) {
    try {
        writeLog("Getting staking info for: " . $address, 'INFO');
        
        $pdo = $walletManager->getDatabase();
        
        // Get active and pending withdrawal staking records
        $stmt = $pdo->prepare("
            SELECT *, 
                   amount as total_staked,
                   CASE 
                       WHEN status = 'active' THEN 'active'
                       WHEN status = 'pending_withdrawal' THEN 'pending'
                       ELSE 'completed'
                   END as lock_status,
                   COALESCE(rewards_earned, 0) as current_rewards,
                   reward_rate as apy,
                   start_block,
                   end_block
            FROM staking 
            WHERE staker = ? AND status IN ('active', 'pending_withdrawal')
            ORDER BY created_at DESC
        ");
        $stmt->execute([$address]);
        $activeStakes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get completed staking history
        $stmt = $pdo->prepare("
            SELECT * FROM staking 
            WHERE staker = ? AND status = 'withdrawn'
            ORDER BY updated_at DESC
            LIMIT 10
        ");
        $stmt->execute([$address]);
        $completedStakes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        $totalStaked = 0;
        $totalRewardsEarning = 0;
        $unlockedAmount = 0;
        
        if (is_array($activeStakes) && !empty($activeStakes)) {
            foreach ($activeStakes as $stake) {
                $stakeAmount = (float)($stake['amount'] ?? 0);
                $stakeRewards = (float)($stake['rewards_earned'] ?? 0);
                
                $totalStaked += $stakeAmount;
                $totalRewardsEarning += $stakeRewards;
                
                // Check if stake is unlocked (has end_block set or is pending withdrawal)
                if ($stake['status'] === 'pending_withdrawal' || 
                    (!empty($stake['end_block']) && $stake['end_block'] > 0)) {
                    $unlockedAmount += $stakeAmount + $stakeRewards;
                    $stake['lock_status'] = 'unlocked';
                }
            }
        }
        
        return [
            'staking_info' => [
                'address' => $address,
                'total_staked' => (float) $totalStaked,
                'total_rewards_earning' => (float) $totalRewardsEarning,
                'unlocked_amount' => (float) $unlockedAmount,
                'active_stakes' => $activeStakes ?: [],
                'completed_stakes' => $completedStakes ?: [],
                'staking_available' => (float) $walletManager->getAvailableBalance($address),
                'has_active_stakes' => !empty($activeStakes),
                'stake_count' => count($activeStakes ?: [])
            ]
        ];
        
    } catch (Exception $e) {
        writeLog("Error getting staking info: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to get staking info: ' . $e->getMessage());
    }
}

/**
 * Decrypt an encrypted message
 */
function decryptMessage(string $encryptedMessage, string $privateKey, string $senderPublicKey = '') {
    try {
        // For new format, expect the encrypted message to be a JSON object
        $secureMessage = json_decode($encryptedMessage, true);
        
        if (!$secureMessage || !isset($secureMessage['encrypted_data'])) {
            return [
                'success' => true,
                'decrypted' => false,
                'message' => $encryptedMessage,
                'error' => 'Message is not encrypted or in old format'
            ];
        }
        
        // Decrypt message using new format
        if (!empty($senderPublicKey)) {
            // Use full verification if sender public key is provided
            $decryptedMessage = \Blockchain\Core\Cryptography\MessageEncryption::decryptSecureMessage(
                $secureMessage, 
                $privateKey, 
                $senderPublicKey
            );
            $verified = true;
        } else {
            // Use ECIES-only decryption without signature verification
            $decryptedMessage = \Blockchain\Core\Cryptography\MessageEncryption::decryptSecureMessageNoVerify(
                $secureMessage, 
                $privateKey
            );
            $verified = false;
        }
        
        return [
            'success' => true,
            'decrypted' => true,
            'message' => $decryptedMessage,
            'verified' => $verified
        ];
        
    } catch (Exception $e) {
        writeLog("Message decryption failed: " . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'error' => 'Failed to decrypt message: ' . $e->getMessage()
        ];
    }
}

/**
 * Get transaction history for a wallet
 */
function getTransactionHistory($walletManager, string $address) {
    try {
        $transactions = $walletManager->getTransactionHistory($address);
        
        return [
            'success' => true,
            'transactions' => $transactions,
            'count' => count($transactions)
        ];
        
    } catch (Exception $e) {
        writeLog("Error getting transaction history: " . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'error' => 'Failed to get transaction history: ' . $e->getMessage()
        ];
    }
}

/**
 * Decrypt transaction message
 */
function decryptTransactionMessage($walletManager, string $txHash, string $walletAddress, string $privateKey) {
    try {
        // Get transaction details
        $transaction = $walletManager->getTransactionByHash($txHash);
        
        if (!$transaction) {
            throw new Exception('Transaction not found');
        }
        
        // Verify wallet involvement
        if ($transaction['from_address'] !== $walletAddress && $transaction['to_address'] !== $walletAddress) {
            throw new Exception('Access denied: wallet not involved in this transaction');
        }

        // Memo can be string (legacy) or structured array with encrypted_data (new)
        $memo = $transaction['memo'] ?? '';

        if ($memo === '' || $memo === null) {
            return [
                'success' => true,
                'decrypted' => false,
                'message' => 'No message in this transaction'
            ];
        }

        // New format: structured encrypted memo as array/object
        if (is_array($memo) && isset($memo['encrypted_data'])) {
            // Get sender public key for verification
            $senderAddress = $transaction['from_address'];
            $senderWallet = $walletManager->getWalletByAddress($senderAddress);
            $senderPublicKey = $senderWallet['public_key'] ?? '';

            try {
                // Prefer full verification if sender public key is available
                if (!empty($senderPublicKey)) {
                    $decrypted = \Blockchain\Core\Cryptography\MessageEncryption::decryptSecureMessage(
                        $memo,
                        $privateKey,
                        $senderPublicKey
                    );
                    return [
                        'success' => true,
                        'decrypted' => true,
                        'message' => $decrypted,
                        'verified' => true
                    ];
                }

                // Fallback: decrypt without signature verification
                $decrypted = \Blockchain\Core\Cryptography\MessageEncryption::decryptSecureMessageNoVerify(
                    $memo,
                    $privateKey
                );
                return [
                    'success' => true,
                    'decrypted' => true,
                    'message' => $decrypted,
                    'verified' => false
                ];
            } catch (Exception $e) {
                writeLog("Structured memo decryption failed: " . $e->getMessage(), 'ERROR');
                return [
                    'success' => false,
                    'error' => 'Failed to decrypt structured message: ' . $e->getMessage()
                ];
            }
        }

        // Legacy format: JSON string or ENCRYPTED: prefix string
        if (is_string($memo)) {
            // If memo is JSON string, try decryptMessage helper which handles new format JSON
            $maybeJson = trim($memo);
            $isJsonLike = strlen($maybeJson) > 0 && ($maybeJson[0] === '{' || $maybeJson[0] === '[');

            if ($isJsonLike || str_starts_with($maybeJson, 'ENCRYPTED:')) {
                $senderAddress = $transaction['from_address'];
                $senderWallet = $walletManager->getWalletByAddress($senderAddress);
                $senderPublicKey = $senderWallet['public_key'] ?? '';

                $decryptResult = decryptMessage($maybeJson, $privateKey, $senderPublicKey);
                if ($decryptResult['success'] && ($decryptResult['decrypted'] ?? false)) {
                    return [
                        'success' => true,
                        'decrypted' => true,
                        'message' => $decryptResult['message'],
                        'verified' => $decryptResult['verified'] ?? false
                    ];
                }

                // If helper says not encrypted, treat it as plain memo (unlikely for strict server policy)
                return $decryptResult;
            }

            // Plain text memo (legacy, but our transfer API should not produce this for new transfers)
            return [
                'success' => true,
                'decrypted' => false,
                'message' => $memo
            ];
        }

        // Unknown memo format
        return [
            'success' => false,
            'error' => 'Unsupported memo format'
        ];
        
    } catch (Exception $e) {
        writeLog("Error decrypting transaction message: " . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'error' => 'Failed to decrypt transaction message: ' . $e->getMessage()
        ];
    }
}

/**
 * Delete wallet from database
 */
function deleteWallet($walletManager, string $address) {
    try {
        writeLog("Deleting wallet: " . $address, 'INFO');
        
        $pdo = $walletManager->getDatabase();
        
        // Check if wallet exists
        $walletInfo = $walletManager->getWalletInfo($address);
        if (!$walletInfo) {
            throw new Exception('Wallet not found');
        }
        
        // Check if wallet has any balance or active staking
        $availableBalance = $walletManager->getAvailableBalance($address);
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as active_stakes FROM staking WHERE staker = ? AND status = 'active'");
        $stmt->execute([$address]);
        $activeStakes = $stmt->fetchColumn();
        
        if ($availableBalance > 0) {
            throw new Exception('Cannot delete wallet with available balance. Please transfer funds first.');
        }
        
        if ($activeStakes > 0) {
            throw new Exception('Cannot delete wallet with active staking. Please unstake first.');
        }
        
        $pdo->beginTransaction();
        
        try {
            // Delete completed staking records
            $stmt = $pdo->prepare("DELETE FROM staking WHERE staker = ?");
            $stmt->execute([$address]);
            
            // Delete transaction history
            $stmt = $pdo->prepare("DELETE FROM transactions WHERE from_address = ? OR to_address = ?");
            $stmt->execute([$address, $address]);
            
            // Delete wallet
            $stmt = $pdo->prepare("DELETE FROM wallets WHERE address = ?");
            $stmt->execute([$address]);
            
            $pdo->commit();
            
            writeLog("Wallet deleted successfully: " . $address, 'INFO');
            
            return [
                'deleted' => true,
                'address' => $address,
                'message' => 'Wallet deleted successfully'
            ];
            
        } catch (Exception $e) {
            $pdo->rollback();
            throw new Exception('Failed to delete wallet from database: ' . $e->getMessage());
        }
        
    } catch (Exception $e) {
        writeLog("Error deleting wallet: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to delete wallet: ' . $e->getMessage());
    }
}

/**
 * Minimal EVM-compatible JSON-RPC handler for Trust Wallet compatibility (read-only)
 * Supported methods: web3_clientVersion, net_version, eth_chainId, eth_blockNumber,
 * eth_getBalance, eth_getTransactionByHash, eth_getBlockByNumber (partial mapping)
 */
function handleRpcRequest(PDO $pdo, $walletManager, $networkConfig, string $method, array $params)
{
    try {
        // Define a wrapper to handle transactions for RPC methods
        $transactionWrapper = function($handler, $pdo, $walletManager, $networkConfig, $method, $params) {
            // For read-only methods, we don't need a transaction
            $readOnlyMethods = [
                'eth_getBalance', 'eth_blockNumber', 'eth_call', 'eth_estimateGas', 
                'eth_gasPrice', 'eth_getTransactionCount', 'eth_getCode', 
                'eth_getTransactionByHash', 'eth_getTransactionReceipt', 'net_version', 'eth_chainId'
            ];

            if (in_array($method, $readOnlyMethods)) {
                // No transaction needed for these methods
                return $handler($pdo, $walletManager, $networkConfig, $params);
            }

            // For write methods, wrap in a transaction
            $pdo->beginTransaction();
            try {
                $result = $handler($pdo, $walletManager, $networkConfig, $params);
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
                return $result;
            } catch (\Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e; // Re-throw exception to be caught by the main handler
            }
        };

        switch ($method) {
            case 'web3_clientVersion':
                return 'phpblockchain/1.0 (wallet_api)';

            // dApp/browser convenience
            case 'eth_accounts': {
                // Return unlocked accounts if any, else fallback to a known address in DB or env
                $unlocked = getUnlockedAccounts();
                if (!empty($unlocked)) {
                    return array_values($unlocked);
                }
                // ENV override for a primary account (useful for demos)
                $envAddr = getenv('PRIMARY_WALLET_ADDRESS') ?: getenv('WALLET_ADDRESS');
                if (is_string($envAddr) && $envAddr !== '') {
                    $norm = normalizeHexAddress($envAddr);
                    if ($norm) return [$norm];
                }
                try {
                    // Return addresses with balance > 0 first, then most recent ones
                    $stmt = $pdo->query("SELECT address FROM wallets WHERE balance > 0 ORDER BY balance DESC, created_at DESC LIMIT 5");
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($rows)) {
                        return array_map(function($row) {
                            return normalizeHexAddress($row['address']) ?: $row['address'];
                        }, $rows);
                    }
                    // Fallback: return most recent address if no balances
                    $stmt = $pdo->query("SELECT address FROM wallets ORDER BY created_at DESC LIMIT 1");
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $row ? [normalizeHexAddress($row['address']) ?: $row['address']] : [];
                } catch (\Throwable $e) {
                    return [];
                }
            }
            case 'eth_requestAccounts': {
                // For MetaMask compatibility, return available accounts or prompt for connection
                // In server context, return available unlocked accounts or known accounts
                $unlocked = getUnlockedAccounts();
                if (!empty($unlocked)) {
                    return array_values($unlocked);
                }
                // Fallback: return addresses with balance first, then most recent ones
                try {
                    $stmt = $pdo->query("SELECT address FROM wallets WHERE balance > 0 ORDER BY balance DESC, created_at DESC LIMIT 5");
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($rows)) {
                        return array_map(function($row) { return $row['address']; }, $rows);
                    }
                    // Fallback: return most recent address if no balances
                    $stmt = $pdo->query("SELECT address FROM wallets ORDER BY created_at DESC LIMIT 1");
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $row ? [$row['address']] : [];
                } catch (\Throwable $e) {
                    return [];
                }
            }
            case 'personal_listAccounts': {
                // Return all known wallet addresses from DB
                try {
                    $stmt = $pdo->query("SELECT address FROM wallets ORDER BY created_at DESC LIMIT 100");
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    return array_map(fn($r) => $r['address'], $rows);
                } catch (\Throwable $e) {
                    return [];
                }
            }
            case 'personal_newAccount': {
                // params: [password]
                $password = $params[0] ?? '';
                if (!is_string($password) || $password === '') {
                    return rpcError(-32602, 'Password is required');
                }
                try {
                    $wallet = $walletManager->createWallet(null, true);
                    // Store encrypted keystore for server-managed account (optional use)
                    saveKeystoreEncrypted($wallet['address'], $wallet['private_key'], $password);
                    return $wallet['address'];
                } catch (\Throwable $e) {
                    writeLog('personal_newAccount error: ' . $e->getMessage(), 'ERROR');
                    return rpcError(-32603, 'Failed to create account');
                }
            }
            case 'personal_unlockAccount': {
                // params: [address, password, duration]
                $address = normalizeHexAddress($params[0] ?? '');
                $password = $params[1] ?? '';
                $duration = (int)($params[2] ?? 300);
                if (!$address || !is_string($password) || $password === '') {
                    return rpcError(-32602, 'Invalid params');
                }
                $ok = unlockAccount($address, $password, $duration);
                return (bool)$ok;
            }
            case 'personal_lockAccount': {
                $address = normalizeHexAddress($params[0] ?? '');
                if (!$address) return rpcError(-32602, 'Invalid address');
                lockAccount($address);
                return true;
            }
            case 'wallet_addEthereumChain': {
                // Validate provided params and return canonical chain config for client UIs
                $req = $params[0] ?? [];
                $providedId = is_array($req) ? ($req['chainId'] ?? null) : null;
                $info = method_exists($networkConfig, 'getNetworkInfo') ? $networkConfig->getNetworkInfo() : [];
                $ourId = (int)($info['chain_id'] ?? 0);
                $providedInt = null;
                if (is_string($providedId) && str_starts_with($providedId, '0x')) {
                    $providedInt = (int)hexdec($providedId);
                } elseif ($providedId !== null) {
                    $providedInt = (int)$providedId;
                }
                if ($providedInt !== null && $providedInt !== $ourId) {
                    writeLog('wallet_addEthereumChain chainId mismatch: provided=' . json_encode($providedId) . ' expected=' . $ourId, 'WARNING');
                    return rpcError(-32602, 'Invalid chainId for this RPC endpoint');
                }
                // Return our canonical dApp config (EIP-3085 shape)
                return getDappConfig($networkConfig);
            }
            case 'wallet_switchEthereumChain': {
                // Validate requested chain id; return null on success per spec guidance
                $req = $params[0] ?? [];
                $target = is_array($req) ? ($req['chainId'] ?? null) : null;
                $info = method_exists($networkConfig, 'getNetworkInfo') ? $networkConfig->getNetworkInfo() : [];
                $ourId = (int)($info['chain_id'] ?? 0);
                $targetInt = null;
                if (is_string($target) && str_starts_with($target, '0x')) {
                    $targetInt = (int)hexdec($target);
                } elseif ($target !== null) {
                    $targetInt = (int)$target;
                }
                if ($targetInt === null) {
                    writeLog('wallet_switchEthereumChain missing chainId', 'WARNING');
                    return rpcError(-32602, 'chainId is required');
                }
                if ($targetInt !== $ourId) {
                    writeLog('wallet_switchEthereumChain unknown chainId: ' . $targetInt . ' expected=' . $ourId, 'WARNING');
                    // 4902 is commonly used for unknown chain, but we keep JSON-RPC style here
                    return rpcError(-32602, 'Unknown chainId for this RPC endpoint');
                }
                return null; // success
            }

            case 'wallet_requestPermissions': {
                // EIP-2255: Request permissions from wallet
                $permissions = $params[0] ?? [];
                if (!is_array($permissions)) {
                    return rpcError(-32602, 'Invalid params');
                }
                
                // For eth_accounts permission, return permission descriptor
                $result = [];
                foreach ($permissions as $permission) {
                    if (isset($permission['eth_accounts']) && is_array($permission['eth_accounts'])) {
                        $result[] = [
                            'parentCapability' => 'eth_accounts',
                            'id' => bin2hex(random_bytes(16)),
                            'date' => time() * 1000, // milliseconds
                            'invoker' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
                            'caveats' => []
                        ];
                    }
                }
                return $result;
            }

            case 'wallet_getPermissions': {
                // Return currently granted permissions
                return [
                    [
                        'parentCapability' => 'eth_accounts',
                        'id' => bin2hex(random_bytes(16)),
                        'date' => time() * 1000,
                        'invoker' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
                        'caveats' => []
                    ]
                ];
            }

            case 'wallet_watchAsset': {
                // EIP-747: Watch asset in wallet
                $params_obj = $params[0] ?? [];
                if (!is_array($params_obj)) {
                    return rpcError(-32602, 'Invalid params');
                }
                
                $type = $params_obj['type'] ?? '';
                $options = $params_obj['options'] ?? [];
                
                if ($type === 'ERC20' && is_array($options)) {
                    // For our blockchain, we could add token to a watched list
                    // For now, just return true (success)
                    writeLog('wallet_watchAsset requested for: ' . json_encode($options), 'INFO');
                    return true;
                }
                
                return false;
            }

            case 'net_version': {
                $info = method_exists($networkConfig, 'getNetworkInfo') ? $networkConfig->getNetworkInfo() : [];
                return (string)($info['chain_id'] ?? 0);
            }

            case 'eth_chainId': {
                $info = method_exists($networkConfig, 'getNetworkInfo') ? $networkConfig->getNetworkInfo() : [];
                $cid = (int)($info['chain_id'] ?? 0);
                return '0x' . dechex($cid);
            }

            case 'eth_blockNumber': {
                $height = getCurrentBlockHeight($walletManager);
                return '0x' . dechex(max(0, (int)$height));
            }

            case 'net_listening':
                // Assume node is listening if API is up
                return true;

            case 'net_peerCount':
                // No P2P peers count exposed; return 0 as hex
                return '0x0';

            case 'eth_protocolVersion':
                // Arbitrary protocol version string
                return '0x1';

            case 'eth_syncing':
                // Report not syncing (boolean false) for simple clients
                return false;

            case 'eth_mining':
                // We're not mining in this wallet API
                return false;

            case 'eth_getBalance': {
                $address = $params[0] ?? '';
                if (!$address) return '0x0';
                // Use spendable balance (exclude staked) for wallet UIs
                $balance = $walletManager->getAvailableBalance($address);
                $decimals = getTokenDecimals($networkConfig);
                // Convert decimal balance to smallest units precisely (no float rounding)
                $units = convertAmountToUnitsInt($balance, $decimals);
                return '0x' . dechex(max(0, (int)$units));
            }

            case 'eth_coinbase': {
                // Return a default local address if available (some wallets probe this)
                $unlocked = getUnlockedAccounts();
                if (!empty($unlocked)) {
                    $first = array_values($unlocked)[0];
                    return $first;
                }
                try {
                    $stmt = $pdo->query("SELECT address FROM wallets ORDER BY created_at DESC LIMIT 1");
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $row ? $row['address'] : '0x0000000000000000000000000000000000000000';
                } catch (\Throwable $e) {
                    return '0x0000000000000000000000000000000000000000';
                }
            }

            case 'eth_getTransactionCount': {
                // params: [address, blockTag]
                $address = $params[0] ?? '';
                $blockTag = strtolower($params[1] ?? 'latest');
                if (!$address) return '0x0';
                // Use WalletManager nonce; treat it as next nonce, return as is for 'pending' and for 'latest'
                $next = 0;
                try { $next = (int)$walletManager->getNextNonce($address); } catch (Throwable $e) {}
                // If we consider 'latest' to be confirmed only, you might subtract 1 when next>0. Keep simple for now.
                return '0x' . dechex(max(0, $next));
            }

            case 'eth_gasPrice':
                // No gas market; return zero
                return '0x0';

            case 'eth_maxPriorityFeePerGas':
                return '0x0';

            case 'eth_estimateGas':
                // Return a fixed 21000 units as a placeholder
                return '0x5208';

            case 'eth_feeHistory': {
                // params: [blockCount, newestBlock, rewardPercentiles]
                // Provide a minimal stable response with zeros; enough for many wallets/UIs
                $blockCount = $params[0] ?? '0x0';
                $newest = $params[1] ?? 'latest';
                $count = 0;
                if (is_string($blockCount) && str_starts_with($blockCount, '0x')) {
                    $count = (int)hexdec($blockCount);
                } elseif (is_numeric($blockCount)) {
                    $count = (int)$blockCount;
                }
                if ($count <= 0) $count = 1;
                $baseFees = array_fill(0, $count, '0x0');
                $gasUsedRatio = array_fill(0, $count, 0.0);
                $reward = [];
                $percentiles = $params[2] ?? [];
                if (is_array($percentiles) && !empty($percentiles)) {
                    $reward = array_fill(0, $count, array_fill(0, count($percentiles), '0x0'));
                }
                return [
                    'oldestBlock' => is_string($newest) ? $newest : 'latest',
                    'baseFeePerGas' => $baseFees,
                    'gasUsedRatio' => $gasUsedRatio,
                    'reward' => $reward,
                ];
            }

            case 'eth_getTransactionByHash': {
                $hash = $params[0] ?? '';
                if (!$hash) return null;
                $tx = $walletManager->getTransactionByHash($hash);
                if (!$tx) {
                    // Fallback to mempool for pending transactions
                    try {
                        $pdo = $walletManager->getDatabase();
                        $stmt = $pdo->prepare("SELECT hash, from_address, to_address, amount, fee, nonce, gas_limit, gas_price, data, signature, timestamp FROM mempool WHERE hash = ? LIMIT 1");
                        $stmt->execute([$hash]);
                        $mp = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($mp) {
                            $decimals = getTokenDecimals($networkConfig);
                            $multiplier = 10 ** $decimals;
                            $valueHex = '0x' . dechex((int)floor(((float)($mp['amount'] ?? 0)) * $multiplier));
                            $chainInfo = method_exists($networkConfig, 'getNetworkInfo') ? $networkConfig->getNetworkInfo() : [];
                            $chainIdHex = '0x' . dechex((int)($chainInfo['chain_id'] ?? 0));
                            return [
                                'hash' => $mp['hash'],
                                'nonce' => '0x' . dechex((int)($mp['nonce'] ?? 0)),
                                'blockHash' => null,
                                'blockNumber' => null,
                                'transactionIndex' => null,
                                'from' => $mp['from_address'] ?? null,
                                'to' => $mp['to_address'] ?? null,
                                'value' => $valueHex,
                                'gas' => '0x' . dechex((int)($mp['gas_limit'] ?? 21000)),
                                'gasPrice' => '0x' . dechex((int)($mp['gas_price'] ?? 0)),
                                'maxFeePerGas' => '0x0',
                                'maxPriorityFeePerGas' => '0x0',
                                'type' => '0x0',
                                'accessList' => [],
                                'chainId' => $chainIdHex,
                                'v' => '0x0', 'r' => '0x0', 's' => '0x0',
                                'input' => is_string($mp['data'] ?? null) ? $mp['data'] : '0x',
                            ];
                        }
                    } catch (Throwable $e) {
                        writeLog('eth_getTransactionByHash mempool fallback error: ' . $e->getMessage(), 'ERROR');
                    }
                    return null;
                }
                $blockNumberHex = isset($tx['block_height']) && $tx['block_height'] !== null
                    ? ('0x' . dechex((int)$tx['block_height']))
                    : null;
                $decimals = getTokenDecimals($networkConfig);
                $multiplier = 10 ** $decimals;
                $valueHex = '0x' . dechex((int)floor(((float)($tx['amount'] ?? 0)) * $multiplier));
                $chainInfo = method_exists($networkConfig, 'getNetworkInfo') ? $networkConfig->getNetworkInfo() : [];
                $chainIdHex = '0x' . dechex((int)($chainInfo['chain_id'] ?? 0));
                return [
                    'hash' => $tx['hash'],
                    'nonce' => '0x0',
                    'blockHash' => $tx['block_hash'] ?? null,
                    'blockNumber' => $blockNumberHex,
                    'transactionIndex' => '0x0',
                    'from' => $tx['from_address'] ?? null,
                    'to' => $tx['to_address'] ?? null,
                    'value' => $valueHex,
                    'gas' => '0x0',
                    'gasPrice' => '0x0',
                    // EIP-1559 style fields (placeholders; no gas market)
                    'maxFeePerGas' => '0x0',
                    'maxPriorityFeePerGas' => '0x0',
                    'type' => '0x0',
                    'accessList' => [],
                    'chainId' => $chainIdHex,
                    // Signature placeholders
                    'v' => '0x0',
                    'r' => '0x0',
                    's' => '0x0',
                    'input' => '0x',
                ];
            }

            case 'eth_getTransactionReceipt': {
                $hash = $params[0] ?? '';
                if (!$hash) return null;
                return getTransactionReceipt($walletManager, $hash);
            }

            case 'eth_sendRawTransaction': {
                // Accept raw Ethereum transaction, queue for processing, return tx hash
                $raw = $params[0] ?? '';
                if (!is_string($raw) || strlen($raw) < 4 || !str_starts_with($raw, '0x')) {
                    return rpcError(-32602, 'Invalid raw transaction');
                }

                $rawHex = strtolower($raw);
                $bin = @hex2bin(substr($rawHex, 2));
                if ($bin === false) {
                    return rpcError(-32602, 'Raw transaction hex decode failed');
                }

                // Compute tx hash as keccak256 of raw bytes (Ethereum-style)
                $txHash = '0x' . \Blockchain\Core\Crypto\Hash::keccak256($bin);

                // Try to parse minimal fields from RLP for visibility (best-effort)
                $parsed = parseEthRawTransaction($rawHex);

                // Persist raw tx to local queue for asynchronous processing
                $queued = queueRawTransaction($txHash, $rawHex, $parsed);
                if (!$queued) {
                    // Still return hash so dApps have a handle; log the issue
                    writeLog('Failed to persist raw tx queue for ' . $txHash, 'ERROR');
                }

                return $txHash;
            }

            case 'eth_sendTransaction': {
                // params: [txObject]
                $tx = $params[0] ?? [];
                if (!is_array($tx)) return rpcError(-32602, 'Invalid transaction object');
                
                $from = normalizeHexAddress($tx['from'] ?? '');
                $to = normalizeHexAddress($tx['to'] ?? '');
                $valueHex = $tx['value'] ?? '0x0';
                $gasHex = $tx['gas'] ?? null;
                $gasPriceHex = $tx['gasPrice'] ?? null;
                $dataHex = $tx['data'] ?? ($tx['input'] ?? null);
                $nonceHex = $tx['nonce'] ?? null;
                
                if (!$from) return rpcError(-32602, 'from address is required');
                if (!$to) return rpcError(-32602, 'to address is required');

                // Check if account is unlocked or private key provided (non-standard extension)
                $priv = getUnlockedPrivateKey($from);
                if (!$priv && isset($tx['privateKey']) && is_string($tx['privateKey'])) {
                    $priv = $tx['privateKey'];
                }
                if (!$priv) {
                    return rpcError(-32601, 'Account not unlocked. Use personal_unlockAccount first');
                }

                // Convert value from hex wei to float amount using chain decimals
                $decimals = getTokenDecimals($networkConfig);
                $intValue = 0;
                if (is_string($valueHex) && str_starts_with(strtolower($valueHex), '0x')) {
                    $intValue = (int)hexdec($valueHex);
                } elseif (is_numeric($valueHex)) {
                    $intValue = (int)$valueHex;
                }
                $amount = $intValue / (10 ** $decimals);

                // Handle gas parameters
                $gasLimit = 21000; // Default gas limit
                if ($gasHex) {
                    $gasLimit = is_string($gasHex) && str_starts_with(strtolower($gasHex), '0x') 
                        ? (int)hexdec($gasHex) 
                        : (int)$gasHex;
                }

                $gasPrice = 0;
                if ($gasPriceHex) {
                    $gasPrice = is_string($gasPriceHex) && str_starts_with(strtolower($gasPriceHex), '0x') 
                        ? (int)hexdec($gasPriceHex) 
                        : (int)$gasPriceHex;
                }

                // Our blockchain doesn't use gas fees, so fee = 0
                $fee = 0.0;

                // Handle transaction data
                $data = null;
                if (is_string($dataHex) && $dataHex !== '0x' && $dataHex !== '') {
                    $data = $dataHex;
                }

                // Handle nonce override
                $nonce = null;
                if ($nonceHex) {
                    $nonce = is_string($nonceHex) && str_starts_with(strtolower($nonceHex), '0x')
                        ? (int)hexdec($nonceHex)
                        : (int)$nonceHex;
                }

                try {
                    // Check balance
                    $balance = $walletManager->getAvailableBalance($from);
                    if ($balance < $amount) {
                        return rpcError(-32000, 'Insufficient funds for transaction');
                    }

                    // Create transaction
                    $txObj = $walletManager->createTransaction($from, $to, $amount, $fee, $priv, $data);
                    
                    // Override gas fields if supported
                    if (method_exists($txObj, 'setGasLimit')) $txObj->setGasLimit($gasLimit);
                    if (method_exists($txObj, 'setGasPrice')) $txObj->setGasPrice($gasPrice);
                    
                    // Override nonce if provided
                    if ($nonce !== null && method_exists($txObj, 'setNonce')) {
                        $txObj->setNonce($nonce);
                    }

                    // Send to mempool
                    $success = $walletManager->sendTransaction($txObj);
                    if (!$success) {
                        return rpcError(-32603, 'Failed to submit transaction to mempool');
                    }

                    $txHash = $txObj->getHash();
                    $result = str_starts_with($txHash, '0x') ? $txHash : ('0x' . $txHash);
                    
                    writeLog("Transaction submitted: $result from $from to $to amount $amount", 'INFO');
                    return $result;
                    
                } catch (\Throwable $e) {
                    writeLog('eth_sendTransaction error: ' . $e->getMessage(), 'ERROR');
                    return rpcError(-32603, 'Transaction execution failed: ' . $e->getMessage());
                }
            }

            case 'eth_getBlockByHash': {
                $hash = $params[0] ?? '';
                $full = (bool)($params[1] ?? false);
                if (!$hash) return null;
                $block = getBlockByHash($walletManager, $hash);
                if (!$block) return null;
                $height = (int)($block['height'] ?? 0);
                $txs = [];
                $txList = $block['transactions'] ?? [];
                if ($full) {
                    foreach ($txList as $t) {
                        $decimals = getTokenDecimals($networkConfig);
                        $multiplier = 10 ** $decimals;
                        $txs[] = [
                            'hash' => $t['hash'],
                            'nonce' => '0x0',
                            'blockHash' => $block['hash'] ?? null,
                            'blockNumber' => '0x' . dechex((int)$height),
                            'transactionIndex' => '0x0',
                            'from' => $t['from_address'] ?? $t['from'] ?? null,
                            'to' => $t['to_address'] ?? $t['to'] ?? null,
                            'value' => '0x' . dechex((int)floor(((float)($t['amount'] ?? 0)) * $multiplier)),
                            'gas' => '0x0',
                            'gasPrice' => '0x0',
                            'maxFeePerGas' => '0x0',
                            'maxPriorityFeePerGas' => '0x0',
                            'type' => '0x0',
                            'accessList' => [],
                            'input' => '0x',
                        ];
                    }
                } else {
                    foreach ($txList as $t) {
                        $txs[] = $t['hash'];
                    }
                }
                // Augment with common Ethereum fields as placeholders
                return [
                    'number' => '0x' . dechex($height),
                    'hash' => $block['hash'] ?? null,
                    'parentHash' => $block['parent_hash'] ?? $block['previous_hash'] ?? null,
                    'nonce' => '0x0000000000000000',
                    'sha3Uncles' => '0x1dcc4de8dec75d7aab85b567b6ccd41ad312451b948a7413f0a142fd40d49347',
                    'logsBloom' => '0x',
                    'transactionsRoot' => '0x',
                    'stateRoot' => '0x',
                    'receiptsRoot' => '0x',
                    'miner' => $block['validator'] ?? null,
                    'difficulty' => '0x0',
                    'totalDifficulty' => '0x0',
                    'extraData' => '0x',
                    'size' => '0x0',
                    'gasLimit' => '0x0',
                    'gasUsed' => '0x0',
                    'timestamp' => '0x' . dechex((int)($block['timestamp'] ?? time())),
                    'uncles' => [],
                    'mixHash' => '0x' . str_repeat('0', 64),
                    'baseFeePerGas' => '0x0',
                    'transactions' => $txs,
                ];
            }

            case 'eth_getStorageAt': {
                // params: [address, storagePosition, blockTag]
                $address = normalizeHexAddress($params[0] ?? '');
                $position = $params[1] ?? '0x0';
                $blockTag = $params[2] ?? 'latest';
                
                if (!$address) {
                    return rpcError(-32602, 'Invalid address');
                }
                
                // For our blockchain, we could fetch from smart_contracts storage field
                try {
                    $stmt = $pdo->prepare("SELECT storage FROM smart_contracts WHERE address = ? LIMIT 1");
                    $stmt->execute([$address]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($row && !empty($row['storage'])) {
                        $storage = json_decode($row['storage'], true);
                        if (is_array($storage)) {
                            // Convert position to string key
                            $key = is_string($position) && str_starts_with($position, '0x') 
                                ? $position 
                                : '0x' . dechex((int)$position);
                            
                            $value = $storage[$key] ?? '0x0';
                            return is_string($value) ? $value : '0x0';
                        }
                    }
                } catch (\Throwable $e) {
                    writeLog('eth_getStorageAt error: ' . $e->getMessage(), 'ERROR');
                }
                
                return '0x' . str_repeat('0', 64); // Empty storage slot
            }

            case 'eth_getCode': {
                // params: [address, blockTag]
                $address = $params[0] ?? '';
                $norm = normalizeHexAddress($address);
                if (!$norm) return '0x';
                $code = getContractCodeHex($pdo, $norm);
                return $code ?: '0x';
            }

            case 'personal_sign':
            case 'eth_sign': {
                // personal_sign params variants: [data, address] or [address, data]
                // eth_sign params: [address, data]
                $p0 = $params[0] ?? '';
                $p1 = $params[1] ?? '';
                $addrFirst = normalizeHexAddress($p0);
                $addrSecond = normalizeHexAddress($p1);
                $address = $addrFirst ?: $addrSecond;
                $data = $addrFirst ? $p1 : $p0;
                if (!$address || !is_string($data)) return rpcError(-32602, 'Invalid params');

                $priv = getUnlockedPrivateKey($address);
                if (!$priv && isset($params[2]) && is_string($params[2])) { $priv = $params[2]; }
                if (!$priv) return rpcError(-32601, 'Account not unlocked');

                // If data is hex 0x..., decode to bytes for hashing; else hash raw string
                if (str_starts_with($data, '0x')) {
                    $bin = @hex2bin(substr($data, 2));
                    if ($bin === false) $bin = '';
                } else {
                    $bin = $data;
                }
                $hash = \Blockchain\Core\Crypto\Hash::keccak256($bin);
                try {
                    $sig = \Blockchain\Core\Cryptography\Signature::sign($hash, $priv);
                    // Return 0x-prefixed signature if not already
                    return str_starts_with($sig, '0x') ? $sig : ('0x' . $sig);
                } catch (\Throwable $e) {
                    writeLog('eth_sign error: ' . $e->getMessage(), 'ERROR');
                    return rpcError(-32603, 'Internal error');
                }
            }

            case 'eth_call': {
                // params: [callObject, blockTag]
                $call = $params[0] ?? [];
                if (!is_array($call)) {
                    writeLog('eth_call invalid params: not an object', 'WARNING');
                    return rpcError(-32602, 'Invalid params');
                }
                $to = $call['to'] ?? '';
                $norm = normalizeHexAddress($to);
                if (!$norm) {
                    writeLog('eth_call missing/invalid to address', 'WARNING');
                    return rpcError(-32602, 'Invalid to address');
                }
                $code = getContractCodeHex($pdo, $norm);
                if (!$code || $code === '0x') return '0x';

                // Prepare VM and context
                $gasHex = $call['gas'] ?? null;
                $gasLimit = is_string($gasHex) && str_starts_with($gasHex, '0x') ? (int)hexdec($gasHex) : 3000000;
                if ($gasLimit <= 0) $gasLimit = 3000000;

                try {
                    $vm = new \Blockchain\Core\SmartContract\VirtualMachine($gasLimit);
                } catch (\Throwable $e) {
                    writeLog('eth_call VM init failed: ' . $e->getMessage(), 'ERROR');
                    return rpcError(-32603, 'Internal error');
                }

                $from = normalizeHexAddress($call['from'] ?? '') ?: '0x0000000000000000000000000000000000000000';
                $valueHex = $call['value'] ?? '0x0';
                $value = 0;
                if (is_string($valueHex) && str_starts_with($valueHex, '0x')) {
                    $value = (int)hexdec($valueHex);
                }
                $dataHex = $call['data'] ?? ($call['input'] ?? '0x');
                $dataBin = '';
                if (is_string($dataHex) && str_starts_with(strtolower($dataHex), '0x')) {
                    $dataBin = @hex2bin(substr(strtolower($dataHex), 2)) ?: '';
                }

                $context = [
                    'caller' => $from,
                    'value' => $value,
                    'gasPrice' => 1,
                    'blockNumber' => getCurrentBlockHeight($walletManager),
                    'timestamp' => time(),
                    'calldata' => $dataBin,
                    'getBalance' => function($addr) use ($walletManager) {
                        try { return 0; } catch (\Throwable $e) { return 0; }
                    }
                ];

                // Execute contract bytecode
                $bytecode = substr($code, 0, 2) === '0x' ? substr($code, 2) : $code;
                try {
                    $result = $vm->execute($bytecode, $context);
                    if (!($result['success'] ?? false)) {
                        writeLog('eth_call execution failed: ' . ($result['error'] ?? 'unknown'), 'WARNING');
                        return '0x';
                    }
                    $out = $result['result'] ?? '';
                    if ($out === '' || $out === null) return '0x';
                    return '0x' . bin2hex($out);
                } catch (\Throwable $e) {
                    writeLog('eth_call execution error: ' . $e->getMessage(), 'ERROR');
                    return rpcError(-32603, 'Internal error');
                }
            }

            case 'eth_getBlockByNumber': {
                $tag = $params[0] ?? 'latest';
                $full = (bool)($params[1] ?? false);
                $height = null;
                if (is_string($tag) && str_starts_with($tag, '0x')) {
                    $height = hexdec($tag);
                } elseif ($tag === 'latest') {
                    $height = getCurrentBlockHeight($walletManager);
                }
                if ($height === null) return null;
                $block = getBlockByHeight($walletManager, (int)$height);
                if (!$block) return null;

                $txs = [];
                $txList = $block['transactions'] ?? [];
                if ($full) {
                    foreach ($txList as $t) {
                        $decimals = getTokenDecimals($networkConfig);
                        $multiplier = 10 ** $decimals;
                        $txs[] = [
                            'hash' => $t['hash'],
                            'nonce' => '0x0',
                            'blockHash' => $block['hash'] ?? null,
                            'blockNumber' => '0x' . dechex((int)$height),
                            'transactionIndex' => '0x0',
                            'from' => $t['from_address'] ?? $t['from'] ?? null,
                            'to' => $t['to_address'] ?? $t['to'] ?? null,
                            'value' => '0x' . dechex((int)floor(((float)($t['amount'] ?? 0)) * $multiplier)),
                            'gas' => '0x0',
                            'gasPrice' => '0x0',
                            'maxFeePerGas' => '0x0',
                            'maxPriorityFeePerGas' => '0x0',
                            'type' => '0x0',
                            'accessList' => [],
                            'input' => '0x',
                        ];
                    }
                } else {
                    foreach ($txList as $t) {
                        $txs[] = $t['hash'];
                    }
                }

                // Add standard Ethereum block fields (placeholder values where not applicable)
                return [
                    'number' => '0x' . dechex((int)$height),
                    'hash' => $block['hash'] ?? null,
                    'parentHash' => $block['parent_hash'] ?? $block['previous_hash'] ?? null,
                    'nonce' => '0x0000000000000000',
                    'sha3Uncles' => '0x1dcc4de8dec75d7aab85b567b6ccd41ad312451b948a7413f0a142fd40d49347',
                    'logsBloom' => '0x',
                    'transactionsRoot' => '0x',
                    'stateRoot' => '0x',
                    'receiptsRoot' => '0x',
                    'miner' => $block['validator'] ?? null,
                    'difficulty' => '0x0',
                    'totalDifficulty' => '0x0',
                    'extraData' => '0x',
                    'size' => '0x0',
                    'gasLimit' => '0x0',
                    'gasUsed' => '0x0',
                    'timestamp' => '0x' . dechex((int)($block['timestamp'] ?? time())),
                    'uncles' => [],
                    'mixHash' => '0x' . str_repeat('0', 64),
                    'baseFeePerGas' => '0x0',
                    'transactions' => $txs,
                ];
            }

            case 'web3_sha3': {
                $data = $params[0] ?? '';
                if (!is_string($data)) return '0x';
                // Accept hex string with 0x prefix or raw string
                if (str_starts_with($data, '0x')) {
                    $bin = @hex2bin(substr($data, 2));
                    if ($bin === false) $bin = '';
                } else {
                    $bin = $data;
                }
                $hash = \Blockchain\Core\Crypto\Hash::keccak256($bin);
                return '0x' . $hash;
            }

            case 'eth_getLogs': {
                // params: [filter]
                $filter = $params[0] ?? [];
                if (!is_array($filter)) {
                    writeLog('eth_getLogs invalid params', 'WARNING');
                    return rpcError(-32602, 'Invalid params');
                }
                $address = $filter['address'] ?? null;
                $fromTag = $filter['fromBlock'] ?? '0x0';
                $toTag = $filter['toBlock'] ?? 'latest';
                $from = is_string($fromTag) && str_starts_with($fromTag, '0x') ? (int)hexdec($fromTag) : (int)$fromTag;
                $to = ($toTag === 'latest') ? getCurrentBlockHeight($walletManager) : (is_string($toTag) && str_starts_with($toTag, '0x') ? (int)hexdec($toTag) : (int)$toTag);
                try {
                    $stateStorage = new \Blockchain\Core\Storage\StateStorage($pdo);
                    if ($address && is_string($address)) {
                        $addr = normalizeHexAddress($address);
                        if (!$addr) return rpcError(-32602, 'Invalid address');
                        return $stateStorage->getContractEvents($addr, $from, $to);
                    }
                    // If no address provided, return empty as we do not index global logs yet
                    return [];
                } catch (\Throwable $e) {
                    writeLog('eth_getLogs error: ' . $e->getMessage(), 'ERROR');
                    return rpcError(-32603, 'Internal error');
                }
            }

            case 'eth_getBlockTransactionCountByNumber': {
                $tag = $params[0] ?? 'latest';
                $height = null;
                if (is_string($tag) && str_starts_with($tag, '0x')) {
                    $height = hexdec($tag);
                } elseif ($tag === 'latest') {
                    $height = getCurrentBlockHeight($walletManager);
                }
                if ($height === null) return '0x0';
                $block = getBlockByHeight($walletManager, (int)$height);
                $count = $block ? count($block['transactions'] ?? []) : 0;
                return '0x' . dechex((int)$count);
            }

            case 'eth_getTransactionByBlockNumberAndIndex': {
                // params: [blockNumberTag, indexHex]
                $tag = $params[0] ?? 'latest';
                $indexHex = $params[1] ?? '0x0';
                $height = null;
                if (is_string($tag) && str_starts_with($tag, '0x')) {
                    $height = hexdec($tag);
                } elseif ($tag === 'latest') {
                    $height = getCurrentBlockHeight($walletManager);
                }
                if ($height === null) return null;
                $index = is_string($indexHex) && str_starts_with($indexHex, '0x') ? hexdec($indexHex) : (int)$indexHex;
                $block = getBlockByHeight($walletManager, (int)$height);
                if (!$block) return null;
                $txList = $block['transactions'] ?? [];
                if (!isset($txList[$index])) return null;
                $txHash = $txList[$index]['hash'] ?? null;
                if (!$txHash) return null;
                // Reuse existing method mapping
                return handleRpcRequest($pdo, $walletManager, $networkConfig, 'eth_getTransactionByHash', [$txHash]);
            }

            default:
                return null;
        }
    } catch (Throwable $e) {
        writeLog("RPC handler error: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

// Helpers to access height and block details with current codebase
function getCurrentBlockHeight($walletManager): ?int
{
    // Try DB-based highest height from blocks table
    try {
        $pdo = $walletManager->getDatabase();
        $stmt = $pdo->query("SELECT MAX(height) AS h FROM blocks");
        $row = $stmt->fetch();
        if ($row && isset($row['h'])) {
            return (int)$row['h'];
        }
    } catch (Exception $e) {
        writeLog("getCurrentBlockHeight DB error: " . $e->getMessage(), 'DEBUG');
    }
    return 0;
}

function getBlockByHeight($walletManager, int $height): ?array
{
    try {
        $pdo = $walletManager->getDatabase();
        $stmt = $pdo->prepare("SELECT hash, parent_hash, height, timestamp, validator, merkle_root FROM blocks WHERE height = ? LIMIT 1");
        $stmt->execute([$height]);
        $block = $stmt->fetch();
        if (!$block) return null;

        // Load transactions for this block (minimal fields used by RPC)
        $txStmt = $pdo->prepare("SELECT hash, from_address, to_address, amount FROM transactions WHERE block_height = ? ORDER BY id ASC");
        $txStmt->execute([$height]);
        $block['transactions'] = $txStmt->fetchAll();
        return $block;
    } catch (Exception $e) {
        writeLog("getBlockByHeight DB error: " . $e->getMessage(), 'DEBUG');
        return null;
    }
}

function getBlockByHash($walletManager, string $hash): ?array
{
    try {
        $pdo = $walletManager->getDatabase();
        $stmt = $pdo->prepare("SELECT hash, parent_hash, height, timestamp, validator, merkle_root FROM blocks WHERE hash = ? LIMIT 1");
        $stmt->execute([$hash]);
        $block = $stmt->fetch();
        if (!$block) return null;
        $height = (int)($block['height'] ?? 0);
        $txStmt = $pdo->prepare("SELECT hash, from_address, to_address, amount FROM transactions WHERE block_height = ? ORDER BY id ASC");
        $txStmt->execute([$height]);
        $block['transactions'] = $txStmt->fetchAll();
        return $block;
    } catch (Exception $e) {
        writeLog("getBlockByHash DB error: " . $e->getMessage(), 'DEBUG');
        return null;
    }
}

function getTransactionReceipt($walletManager, string $hash): ?array
{
    try {
        $tx = $walletManager->getTransactionByHash($hash);
        if (!$tx) {
            // If not confirmed yet, check mempool; auto-confirm no-op self-transfers
            try {
                $pdo = $walletManager->getDatabase();
                $stmt = $pdo->prepare("SELECT hash, from_address, to_address, amount, fee, nonce, gas_limit, gas_price, data, signature, timestamp FROM mempool WHERE hash = ? LIMIT 1");
                $stmt->execute([$hash]);
                $mp = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($mp) {
                    $from = strtolower((string)($mp['from_address'] ?? ''));
                    $to = strtolower((string)($mp['to_address'] ?? ''));
                    $amount = (float)($mp['amount'] ?? 0);
                    $isNoop = ($from !== '' && $from === $to && $amount <= 0);
                    if ($isNoop) {
                        // Promote to confirmed transaction without block
                        $started = false;
                        if (method_exists($pdo, 'inTransaction') && !$pdo->inTransaction()) {
                            $pdo->beginTransaction();
                            $started = true;
                        }
                        try {
                            $ins = $pdo->prepare("INSERT INTO transactions (hash, from_address, to_address, amount, fee, nonce, gas_limit, gas_price, data, signature, timestamp, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed') ON DUPLICATE KEY UPDATE status='confirmed'");
                            $ins->execute([$hash, $from, $to, $amount, (float)($mp['fee'] ?? 0), (int)($mp['nonce'] ?? 0), (int)($mp['gas_limit'] ?? 21000), (int)($mp['gas_price'] ?? 0), (string)($mp['data'] ?? ''), (string)($mp['signature'] ?? ''), (int)($mp['timestamp'] ?? time())]);
                            $del = $pdo->prepare("DELETE FROM mempool WHERE hash = ?");
                            $del->execute([$hash]);
                            if ($started) { $pdo->commit(); }
                        } catch (Throwable $e) {
                            if ($started && method_exists($pdo, 'inTransaction') && $pdo->inTransaction()) { $pdo->rollBack(); }
                            writeLog('getTransactionReceipt promote noop error: ' . $e->getMessage(), 'ERROR');
                        }
                        // Re-read as confirmed
                        $tx = $walletManager->getTransactionByHash($hash);
                        if (!$tx) {
                            // Fallback to synthetic receipt with status=1 and no block
                            return [
                                'transactionHash' => $hash,
                                'transactionIndex' => '0x0',
                                'blockHash' => null,
                                'blockNumber' => null,
                                'from' => $from,
                                'to' => $to,
                                'cumulativeGasUsed' => '0x0',
                                'gasUsed' => '0x0',
                                'contractAddress' => null,
                                'logs' => [],
                                'logsBloom' => '0x',
                                'status' => '0x1',
                            ];
                        }
                    } else {
                        // For pending non-noop txs return null per Ethereum behavior
                        return null;
                    }
                } else {
                    return null;
                }
            } catch (Throwable $e) {
                writeLog('getTransactionReceipt mempool check error: ' . $e->getMessage(), 'ERROR');
                return null;
            }
        }
        $blockNumberHex = isset($tx['block_height']) && $tx['block_height'] !== null
            ? ('0x' . dechex((int)$tx['block_height']))
            : null;
        $statusHex = ($tx['status'] ?? '') === 'confirmed' ? '0x1' : '0x0';
        return [
            'transactionHash' => $tx['hash'],
            'transactionIndex' => '0x0',
            'blockHash' => $tx['block_hash'] ?? null,
            'blockNumber' => $blockNumberHex,
            'from' => $tx['from_address'] ?? null,
            'to' => $tx['to_address'] ?? null,
            'cumulativeGasUsed' => '0x0',
            'gasUsed' => '0x0',
            'contractAddress' => null,
            'logs' => [],
            'logsBloom' => '0x',
            'status' => $statusHex,
        ];
    } catch (Exception $e) {
        writeLog('getTransactionReceipt error: ' . $e->getMessage(), 'ERROR');
        return null;
    }
}

// Helper to read token decimals from network configuration
function getTokenDecimals($networkConfig): int
{
    try {
        if (method_exists($networkConfig, 'getTokenInfo')) {
            $info = $networkConfig->getTokenInfo();
            $d = (int)($info['decimals'] ?? 18);
            return $d > 0 ? $d : 18; // default to 18 for MetaMask compatibility
        }
    } catch (Throwable $e) {
        // ignore
    }
    return 18; // Default decimals: 18 (MetaMask standard)
}/**
 * dApp configuration helper
 * Returns EIP-3085 compatible chain parameters and minimal metadata
 */
function getDappConfig($networkConfig): array
{
    $token = method_exists($networkConfig, 'getTokenInfo') ? $networkConfig->getTokenInfo() : [];
    $net = method_exists($networkConfig, 'getNetworkInfo') ? $networkConfig->getNetworkInfo() : [];

    $chainId = (int)($net['chain_id'] ?? 1);
    $decimals = (int)($token['decimals'] ?? 18);
    $symbol = $token['symbol'] ?? 'COIN';
    $name = $net['name'] ?? ($token['name'] ?? 'Blockchain');

    // Note: rpcUrls will be the same endpoint; explorer is optional and can be configured separately
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/wallet/wallet_api.php'), '/');
    $rpcUrl = $scheme . $host . $basePath . '/wallet_api.php';

    // Explorer URL only if explorer frontend is present
    $baseDir = dirname(__DIR__);
    $explorerExists = is_file($baseDir . '/explorer/index.php');
    $explorerUrls = $explorerExists ? [$scheme . $host . '/explorer/'] : [];

    // Icon URL from assets (ordinary hosting) - prefer PNG
    // Note: index.php router has a PNG->SVG fallback if PNG is missing.
    $iconUrl = $scheme . $host . '/public/assets/network-icon.png';

    $config = [
        'chainId' => '0x' . dechex($chainId),
        'chainName' => $name,
        'nativeCurrency' => [
            'name' => $token['name'] ?? $symbol,
            'symbol' => $symbol,
            'decimals' => $decimals,
        ],
        'rpcUrls' => [$rpcUrl],
    ];

    if (!empty($explorerUrls)) {
        $config['blockExplorerUrls'] = $explorerUrls;
    }
    $config['iconUrls'] = [$iconUrl];

    // Optional: ENS registry address if configured
    if (!empty($net['ens_address'])) {
        $config['ensAddress'] = $net['ens_address'];
    }

    return $config;
}

/**
 * Convert human-readable amount to smallest units (integer) precisely.
 * Uses bcmath if available; falls back to string math to avoid float issues.
 * Handles large numbers and prevents integer overflow.
 */
function convertAmountToUnitsInt($amount, int $decimals): int {
    // Normalize to string to avoid float precision
    $str = is_string($amount) ? $amount : (string)$amount;
    $str = trim($str);
    if ($str === '' || $str === '0') return 0;
    
    // Handle negative numbers
    $negative = false;
    if ($str[0] === '-') { 
        $negative = true; 
        $str = substr($str, 1); 
    }
    
    // Split integer and fractional parts
    $parts = explode('.', $str, 2);
    $intPart = preg_replace('/\D/', '', $parts[0] ?? '0');
    $fracPart = preg_replace('/\D/', '', $parts[1] ?? '');
    
    // Truncate fractional part to decimals precision
    if (strlen($fracPart) > $decimals) {
        $fracPart = substr($fracPart, 0, $decimals);
    }
    
    // Right-pad fractional part to decimals
    $fracPart = str_pad($fracPart, $decimals, '0');
    
    // Combine parts
    $full = ltrim($intPart . $fracPart, '0');
    if ($full === '') $full = '0';
    
    // Use bcmath for large numbers if available
    if (function_exists('bcmul')) {
        $result = bcmul($str, bcpow('10', $decimals));
        $val = (int)$result;
    } else {
        // Fallback: check for overflow
        $val = (int)$full;
        if ($val < 0) {
            // Integer overflow detected, clamp to max safe value
            $val = PHP_INT_MAX;
        }
    }
    
    return $negative ? -$val : $val;
}

/**
 * Build JSON-RPC error object with MetaMask-compatible error codes
 */
function rpcError(int $code, string $message, $data = null)
{
    $error = [
        'code' => $code,
        'message' => $message
    ];
    
    if ($data !== null) {
        $error['data'] = $data;
    }
    
    return $error;
}

/**
 * Common MetaMask error codes:
 * -32700: Parse error
 * -32600: Invalid Request
 * -32601: Method not found
 * -32602: Invalid params
 * -32603: Internal error
 * -32000: Invalid input (custom)
 * -32001: Resource not found (custom)
 * -32002: Resource unavailable (custom)
 * -32003: Transaction rejected (custom)
 * -32004: Method not supported (custom)
 * -32005: Limit exceeded (custom)
 * 4001: User rejected request
 * 4100: Unauthorized
 * 4200: Unsupported method
 * 4900: Disconnected
 * 4901: Chain disconnected
 */

/**
 * Simple unlocked accounts management (file-based, ephemeral TTL).
 * Note: For production, consider secure storage and process-level memory store.
 */
function unlockedStorePath(): string {
    $dir = dirname(__DIR__) . '/storage/keystore';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    return $dir . '/unlocked.json';
}

function getUnlockedAccounts(): array {
    $path = unlockedStorePath();
    if (!is_file($path)) return [];
    $json = @file_get_contents($path);
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return [];
    // Filter expired
    $now = time();
    $out = [];
    foreach ($data as $addr => $info) {
        if (!isset($info['expires']) || $info['expires'] >= $now) {
            $out[$addr] = $addr;
        }
    }
    return $out;
}

function getUnlockedPrivateKey(string $address): string {
    $path = unlockedStorePath();
    if (!is_file($path)) return '';
    $json = @file_get_contents($path);
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return '';
    $addr = strtolower($address);
    $now = time();
    if (isset($data[$addr]) && (!isset($data[$addr]['expires']) || $data[$addr]['expires'] >= $now)) {
        return (string)($data[$addr]['privateKey'] ?? '');
    }
    return '';
}

function lockAccount(string $address): void {
    $path = unlockedStorePath();
    if (!is_file($path)) return;
    $json = @file_get_contents($path);
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return;
    unset($data[strtolower($address)]);
    @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function unlockAccount(string $address, string $password, int $duration = 300): bool {
    // Try decrypting keystore file for this address
    $priv = loadKeystoreDecrypted($address, $password);
    if (!$priv) return false;
    $path = unlockedStorePath();
    $json = is_file($path) ? (string)@file_get_contents($path) : '{}';
    $data = json_decode($json, true);
    if (!is_array($data)) $data = [];
    $data[strtolower($address)] = [
        'privateKey' => $priv,
        'expires' => time() + max(1, $duration)
    ];
    return (bool)@file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function keystoreDir(): string {
    $dir = dirname(__DIR__) . '/storage/keystore';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    return $dir;
}

function keystorePath(string $address): string {
    return keystoreDir() . '/' . strtolower($address) . '.json';
}

function saveKeystoreEncrypted(string $address, string $privateKey, string $password): bool {
    // Very basic encryption using OpenSSL; consider stronger KDF in production
    $salt = bin2hex(random_bytes(8));
    $key = hash('sha256', $password . $salt, true);
    $iv = random_bytes(16);
    $cipher = 'aes-256-cbc';
    $enc = openssl_encrypt($privateKey, $cipher, $key, OPENSSL_RAW_DATA, $iv);
    $payload = [
        'address' => strtolower($address),
        'crypto' => [
            'cipher' => $cipher,
            'ciphertext' => bin2hex($enc),
            'iv' => bin2hex($iv),
            'salt' => $salt,
            'kdf' => 'sha256'
        ],
        'version' => 1
    ];
    return (bool)@file_put_contents(keystorePath($address), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function loadKeystoreDecrypted(string $address, string $password): string {
    $path = keystorePath($address);
    if (!is_file($path)) return '';
    $json = @file_get_contents($path);
    $data = json_decode((string)$json, true);
    if (!is_array($data) || empty($data['crypto'])) return '';
    $salt = (string)($data['crypto']['salt'] ?? '');
    $key = hash('sha256', $password . $salt, true);
    $cipher = (string)($data['crypto']['cipher'] ?? 'aes-256-cbc');
    $iv = hex2bin((string)($data['crypto']['iv'] ?? '')) ?: '';
       $ct = hex2bin((string)($data['crypto']['ciphertext'] ?? '')) ?: '';
    if ($iv === '' || $ct === '') return '';
    $dec = openssl_decrypt($ct, $cipher, $key, OPENSSL_RAW_DATA, $iv);
    return is_string($dec) ? $dec : '';
}

/**
 * Queue raw Ethereum transaction to local storage for later processing by a worker
 * Minimal, file-based queue to avoid DB schema changes.
 */
function queueRawTransaction(string $txHash, string $rawHex, array $parsed = []): bool
{
    try {
        $dir = dirname(__DIR__) . '/storage/raw_mempool';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir . '/' . str_replace('0x', '', $txHash) . '.json';
        $payload = [
            'hash' => $txHash,
            'raw' => $rawHex,
            'parsed' => $parsed,
            'received_at' => time()
        ];
        return (bool)@file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } catch (\Throwable $e) {
        writeLog('queueRawTransaction error: ' . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Minimal RLP decoding for Ethereum legacy txs to extract from/to/value/nonce (best-effort).
 * This is intentionally permissive and does not validate chain IDs or signatures.
 */
function parseEthRawTransaction(string $rawHex): array
{
    try {
        if (str_starts_with($rawHex, '0x')) $rawHex = substr($rawHex, 2);
        $bin = @hex2bin($rawHex);
        if ($bin === false) return [];

        $offset = 0;
        $read = function() use ($bin, &$offset) {
            $len = strlen($bin);
            if ($offset >= $len) return null;
            $b0 = ord($bin[$offset]);
            if ($b0 <= 0x7f) { // single byte string
                $offset += 1;
                return $bin[$offset-1];
            } elseif ($b0 <= 0xb7) { // short string
                $l = $b0 - 0x80;
                $offset += 1;
                $val = substr($bin, $offset, $l);
                $offset += $l;
                return $val;
            } elseif ($b0 <= 0xbf) { // long string
                $ll = $b0 - 0xb7;
                $offset += 1;
                $lBytes = substr($bin, $offset, $ll);
                $offset += $ll;
                $l = intval(bin2hex($lBytes), 16);
                $val = substr($bin, $offset, $l);
                $offset += $l;
                return $val;
            } elseif ($b0 <= 0xf7) { // short list
                $l = $b0 - 0xc0;
                $offset += 1;
                $end = $offset + $l;
                $items = [];
                while ($offset < $end) $items[] = $this->readItem($bin, $offset);
                return $items;
            } else { // long list
                $ll = $b0 - 0xf7;
                $offset += 1;
                $lBytes = substr($bin, $offset, $ll);
                $offset += $ll;
                $l = intval(bin2hex($lBytes), 16);
                $end = $offset + $l;
                $items = [];
                while ($offset < $end) $items[] = $this->readItem($bin, $offset);
                return $items;
            }
        };

        // Local helper to read one item (closure-compatible)
        $readItem = function() use (&$read) { return $read(); };

        // Patch closures to call nested safely
        $reflect = function($bin, &$offset) use (&$readItem, &$read) {
            $len = strlen($bin);
            if ($offset >= $len) return null;
            $b0 = ord($bin[$offset]);
            if ($b0 <= 0x7f) { $offset += 1; return $bin[$offset-1]; }
            if ($b0 <= 0xb7) { $l = $b0 - 0x80; $offset += 1; $v = substr($bin, $offset, $l); $offset += $l; return $v; }
            if ($b0 <= 0xbf) { $ll = $b0 - 0xb7; $offset += 1; $lBytes = substr($bin, $offset, $ll); $offset += $ll; $l = intval(bin2hex($lBytes), 16); $v = substr($bin, $offset, $l); $offset += $l; return $v; }
            if ($b0 <= 0xf7) { $l = $b0 - 0xc0; $offset += 1; $end = $offset + $l; $arr = []; while ($offset < $end) { $arr[] = $reflect($bin, $offset); } return $arr; }
            $ll = $b0 - 0xf7; $offset += 1; $lBytes = substr($bin, $offset, $ll); $offset += $ll; $l = intval(bin2hex($lBytes), 16); $end = $offset + $l; $arr = []; while ($offset < $end) { $arr[] = $reflect($bin, $offset); } return $arr;
        };

        $list = $reflect($bin, $offset);
        if (!is_array($list)) return [];

        // Legacy tx fields: [nonce, gasPrice, gasLimit, to, value, data, v, r, s]
        $hex = fn($v) => '0x' . bin2hex($v ?? '');
        $toHex40 = function($v) use ($hex) {
            $h = substr($hex($v), 2);
            if ($h === '') return '0x';
            $h = ltrim($h, '0');
            $h = str_pad($h, 40, '0', STR_PAD_LEFT);
            return '0x' . $h;
        };
        $numHex = function($v) { $h = bin2hex($v ?? ''); $h = ltrim($h, '0'); return '0x' . ($h === '' ? '0' : $h); };

        $out = [
            'nonce' => $numHex($list[0] ?? ''),
            'gasPrice' => $numHex($list[1] ?? ''),
            'gas' => $numHex($list[2] ?? ''),
            'to' => $toHex40($list[3] ?? ''),
            'value' => $numHex($list[4] ?? ''),
            'input' => $hex($list[5] ?? ''),
        ];
        return $out;
    } catch (\Throwable $e) {
        writeLog('parseEthRawTransaction error: ' . $e->getMessage(), 'ERROR');
        return [];
    }
}

/**
 * Normalize 0x-prefixed 20-byte hex address; returns lowercased 0x... or empty string
 */
function normalizeHexAddress(?string $addr): string {
    if (!is_string($addr)) return '';
    $a = strtolower(trim($addr));
    if (!str_starts_with($a, '0x')) return '';
    if (strlen($a) !== 42) return '';
    if (!ctype_xdigit(substr($a, 2))) return '';
    return $a;
}

/**
 * Retrieve contract bytecode hex (0x...) from DB smart_contracts or filesystem storage/contracts
 */
function getContractCodeHex(PDO $pdo, string $address): string {
    try {
        // Try DB smart_contracts table
        $stmt = $pdo->query("SHOW TABLES LIKE 'smart_contracts'");
        if ($stmt && $stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT bytecode FROM smart_contracts WHERE address = ? LIMIT 1");
            $stmt->execute([$address]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['bytecode'])) {
                $code = $row['bytecode'];
                $code = is_string($code) ? trim($code) : '';
                if ($code !== '') {
                    if (str_starts_with($code, '0x')) return $code;
                    if (ctype_xdigit($code)) return '0x' . $code;
                }
            }
        }
    } catch (\Throwable $e) {
        // ignore
    }
    // Filesystem fallback: storage/contracts/<address>.bin or .hex
    $baseDir = dirname(__DIR__);
    $contractDir = $baseDir . '/storage/contracts';
    // Optional mapping file created by Application during bootstrap deployments
    $mapFile = $baseDir . '/storage/contract_addresses.json';
    if (is_file($mapFile)) {
        $json = @file_get_contents($mapFile);
        $decoded = json_decode((string)$json, true);
        if (is_array($decoded)) {
            foreach ($decoded as $alias => $real) {
                if (strtolower((string)$real) === strtolower($address)) {
                    $address = strtolower($address);
                    break;
                }
            }
        }
    }
    $paths = [
        $contractDir . '/' . $address . '.bin',
        $contractDir . '/' . $address . '.hex',
    ];
    foreach ($paths as $p) {
        if (is_file($p)) {
            $data = @file_get_contents($p);
            if ($data === false) continue;
            $data = trim($data);
            if ($data === '') continue;
            if (str_starts_with($data, '0x')) return $data;
            if (ctype_xdigit($data)) return '0x' . $data;
        }
    }
    return '0x';
}

/**
 * Obtain or deploy a staking contract and return its 0x address.
 * Strategy:
 * 1) Try to read from DB smart_contracts by name 'Staking'.
 * 2) Try mapping cache storage/contract_addresses.json (key: staking_contract).
 * 3) Best-effort deploy via Contracts API if available in-process (Application/SmartContractManager not directly accessible here),
 *    so as a fallback we just return empty and let node bootstrap handle deployment.
 */
function getOrDeployStakingContract(PDO $pdo, string $deployerAddress): string {
    // 1) DB: try to find contract with name 'Staking'
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'smart_contracts'");
        if ($stmt && $stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT address FROM smart_contracts WHERE name = 'Staking' AND status = 'active' ORDER BY deployment_block ASC LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['address'])) {
                return strtolower($row['address']);
            }
        }
    } catch (\Throwable $e) {
        // ignore
    }

    // 2) Mapping file
    $baseDir = dirname(__DIR__);
    $mapFile = $baseDir . '/storage/contract_addresses.json';
    if (is_file($mapFile)) {
        $json = @file_get_contents($mapFile);
        $decoded = json_decode((string)$json, true);
        if (is_array($decoded) && !empty($decoded['staking_contract'])) {
            $addr = strtolower((string)$decoded['staking_contract']);
            if (str_starts_with($addr, '0x') && strlen($addr) === 42) return $addr;
        }
    }

    // 3) Try to deploy now using SmartContractManager (no mocks)
    try {
        // Minimal logger implementation
        $logger = new class implements \Psr\Log\LoggerInterface {
            // No-op PSR-3 logger implementation for temporary deployment context
            public function emergency(string|\Stringable $message, array $context = []): void {}
            public function alert(string|\Stringable $message, array $context = []): void {}
            public function critical(string|\Stringable $message, array $context = []): void {}
            public function error(string|\Stringable $message, array $context = []): void {}
            public function warning(string|\Stringable $message, array $context = []): void {}
            public function notice(string|\Stringable $message, array $context = []): void {}
            public function info(string|\Stringable $message, array $context = []): void {}
            public function debug(string|\Stringable $message, array $context = []): void {}
            public function log($level, string|\Stringable $message, array $context = []): void {}
        };

        $vm = new \Blockchain\Core\SmartContract\VirtualMachine(3000000);
        $stateStorage = new \Blockchain\Core\Storage\StateStorage($pdo);
        $cfg = $GLOBALS['config'] ?? [];
        $manager = new \Blockchain\Contracts\SmartContractManager($vm, $stateStorage, $logger, is_array($cfg) ? $cfg : []);

        // Deploy standard set and extract staking
        $res = $manager->deployStandardContracts($deployerAddress);
        if (is_array($res) && !empty($res['staking']['success']) && !empty($res['staking']['address'])) {
            $addr = strtolower((string)$res['staking']['address']);
            // Persist mapping to cache file
            $existing = [];
            if (is_file($mapFile)) {
                $json = @file_get_contents($mapFile);
                $decoded = json_decode((string)$json, true);
                if (is_array($decoded)) $existing = $decoded;
            }
            $existing['staking_contract'] = $addr;
            @file_put_contents($mapFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $addr;
        }
    } catch (\Throwable $e) {
        // Swallow and fallback
        writeLog('Staking autodeploy failed: ' . $e->getMessage(), 'ERROR');
    }

    // If still not available
    return '';
}

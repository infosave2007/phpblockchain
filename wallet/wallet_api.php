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
    try {
        // Try to use WalletLogger first
    \Blockchain\Wallet\WalletLogger::log($message, $level);
    } catch (\Throwable $e) {
        // Fallback to direct file logging if WalletLogger fails
        $baseDir = dirname(__DIR__);
        $logDir = $baseDir . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/wallet_api_direct.log';
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Get current block height from database
 */
function getCurrentBlockHeight(PDO $pdo): int
{
    try {
        $stmt = $pdo->query("SELECT MAX(height) as max_height FROM blocks");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['max_height'] ?? 0);
    } catch (Exception $e) {
        return 0; // Fallback to 0 if query fails
    }
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
        // Secure body logging: disabled by default. Enable via header X-Log-Body: true or env WALLET_API_LOG_BODY=1.
        $logBodyAllowed = false;
        $hdr = $_SERVER['HTTP_X_LOG_BODY'] ?? '';
        if (is_string($hdr)) {
            $v = strtolower(trim($hdr));
            $logBodyAllowed = in_array($v, ['1','true','yes','on'], true);
        }
        if (!$logBodyAllowed) {
            $envToggle = getenv('WALLET_API_LOG_BODY');
            if ($envToggle !== false) {
                $v = strtolower(trim((string)$envToggle));
                $logBodyAllowed = in_array($v, ['1','true','yes','on'], true);
            }
        }
        // Allow forcing full raw body logging via env/header (with safe caps)
        $logBodyFull = false;
        $hdrFull = $_SERVER['HTTP_X_LOG_BODY_FULL'] ?? '';
        if (is_string($hdrFull)) {
            $v = strtolower(trim($hdrFull));
            $logBodyFull = in_array($v, ['1','true','yes','on'], true);
        }
        if (!$logBodyFull) {
            $envToggleFull = getenv('WALLET_API_LOG_BODY_FULL');
            if ($envToggleFull !== false) {
                $v = strtolower(trim((string)$envToggleFull));
                $logBodyFull = in_array($v, ['1','true','yes','on'], true);
            }
        }

        $previewMax = 1000; // default cap
        $envPreview = getenv('WALLET_API_LOG_PREVIEW_MAX');
        if ($envPreview !== false && is_numeric($envPreview)) {
            $previewMax = max(100, min(200000, (int)$envPreview));
        }

        if ($logBodyAllowed && $method === 'POST' && !empty($rawBodyEarly)) {
            // Try to safely mask sensitive fields in JSON
            $bodyPreview = '';
            $decoded = json_decode($rawBodyEarly, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $maskKeys = ['private_key','password','mnemonic','seed','signature'];
                $walker = function(&$arr) use (&$walker, $maskKeys) {
                    foreach ($arr as $k => &$v) {
                        if (in_array((string)$k, $maskKeys, true) && (is_string($v) || is_numeric($v))) {
                            $v = '***';
                        } elseif (is_array($v)) {
                            $walker($v);
                        }
                    }
                };
                $walker($decoded);
                $jsonMasked = json_encode($decoded, JSON_UNESCAPED_SLASHES);
                $bodyPreview = $logBodyFull ? (string)$jsonMasked : substr((string)$jsonMasked, 0, $previewMax);
            } else {
                // Fallback: regex mask common secrets in raw text
                $masked = preg_replace(
                    '/("?(private_key|password|mnemonic|seed|signature)"?\s*:\s*")([^"]+)(")/i',
                    '$1***$4',
                    $rawBodyEarly
                );
                $bodyPreview = $logBodyFull ? (string)$masked : substr((string)$masked, 0, $previewMax);
            }
            $len = strlen($rawBodyEarly);
            $line2 = "[{$timestamp}] [REQUEST] [{$reqId}] body_len={$len} body_preview: {$bodyPreview}";
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
require_once $baseDir . '/core/Transaction/Transaction.php';
require_once $baseDir . '/core/Transaction/MempoolManager.php';
    
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

    // For GET requests use $_GET and log parameters
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $input = $_GET;
        $jsonError = JSON_ERROR_NONE;
        // Log GET parameters as request body
        $rawBodyEarly = http_build_query($_GET);
        $GLOBALS['__RAW_BODY'] = $rawBodyEarly;
    }

    // Simple request logging
    $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    
    // Log incoming request
    writeLog("[$requestId] $method $uri from $ip");
    
    // Avoid logging raw POST body here to prevent accidental leakage. Early logger handles optional masked logging.

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
            'eth_chainId','eth_blockNumber','eth_getBalance','eth_coinbase','eth_getTransactionCount','eth_gasPrice','eth_maxPriorityFeePerGas','eth_estimateGas','eth_call','eth_getTransactionByHash','eth_getTransactionReceipt','eth_sendRawTransaction','eth_sendTransaction','eth_getBlockByNumber','eth_getBlockByHash','eth_getStorageAt','eth_getCode','eth_getLogs','eth_getBlockTransactionCountByNumber','eth_getTransactionByBlockNumberAndIndex','eth_feeHistory','eth_syncing','eth_mining',
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
            $parseErrorResponse = $jsonRpcErrorResponse(null, -32700, 'Parse error');
            
            // Log parse error response
            try {
                $reqId = $GLOBALS['__REQ_ID'] ?? 'unknown';
                writeLog("RPC $reqId: PARSE_ERROR: " . json_encode($parseErrorResponse), 'ERROR');
            } catch (\Throwable $e) {
                // Don't break response sending due to logging issues
            }
            
            echo json_encode($parseErrorResponse);
            exit;
        }

        // Handle single or batch
        if ($isJsonRpcBatch) {
            $responses = [];
            try { \Blockchain\Wallet\WalletLogger::info("RPC $requestId: batch size=" . count($input)); } catch (Throwable $e) {}
            foreach ($input as $req) {
                $responses[] = $processJsonRpc($req);
            }
            
            // Log batch response
            try {
                $reqId = $GLOBALS['__REQ_ID'] ?? 'unknown';
                $responsePreview = substr(json_encode($responses), 0, 500);
                writeLog("RPC $reqId: BATCH_RESPONSE: $responsePreview", 'INFO');
            } catch (\Throwable $e) {
                // Don't break response sending due to logging issues
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
            
            $aliasResponse = $processJsonRpc($req);
            
            // Log alias response
            try {
                $reqId = $GLOBALS['__REQ_ID'] ?? 'unknown';
                $responsePreview = substr(json_encode($aliasResponse), 0, 500);
                writeLog("RPC $reqId: ALIAS_RESPONSE: $responsePreview", 'INFO');
            } catch (\Throwable $e) {
                // Don't break response sending due to logging issues
            }
            
            echo json_encode($aliasResponse);
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
        
        // Log the response before sending
        try {
            $reqId = $GLOBALS['__REQ_ID'] ?? 'unknown';
            $responsePreview = substr(json_encode($response), 0, 500);
            // Direct logging to guarantee response logging
            $baseDir = dirname(__DIR__);
            $logDir = $baseDir . '/logs';
            $logFile = $logDir . '/wallet_api.log';
            $timestamp = date('Y-m-d H:i:s');
            $logLine = "[{$timestamp}] [RESPONSE] [$reqId] {$responsePreview}" . PHP_EOL;
            @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Don't break response sending due to logging issues
        }
        
        echo json_encode($response);
        exit;
    }

    $action = $input['action'] ?? '';
    if ($action !== '') {
        try {
            $safe = $input;
            if (is_array($safe)) {
                $maskKeys = ['private_key','privateKey','password','mnemonic','seed','signature','sender_private_key','funder_priv'];
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
            
            // Trigger auto-mine after wallet creation if enabled
            $config = getNetworkConfigFromDatabase($pdo);
            if ($config['auto_mine.enabled'] ?? true) {
                writeLog("Attempting auto-mine after wallet creation", 'INFO');
                $autoMineResult = autoMineBlocks($walletManager, $config);
                writeLog("Auto-mine result after wallet creation: " . json_encode($autoMineResult), 'INFO');
                $result['auto_mine'] = $autoMineResult;
            }

            // Trigger auto-sync after wallet creation if enabled
            if ($config['auto_sync.enabled'] ?? true) {
                writeLog("Attempting auto-sync after wallet creation", 'INFO');
                $autoSyncResult = autoSyncBlockchain($walletManager, $config);
                if ($autoSyncResult['synced']) {
                    writeLog("Auto-sync triggered after wallet creation: " . json_encode($autoSyncResult), 'INFO');
                    $result['auto_sync'] = $autoSyncResult;
                }
            }

            // Monitor blockchain height after wallet creation
            if ($config['monitor.enabled'] ?? true) {
                $monitorResult = monitorBlockchainHeight($walletManager, $config);
                if ($monitorResult['status'] !== 'healthy') {
                    writeLog("Height monitor alert after wallet creation: " . json_encode($monitorResult), 'WARNING');
                    $result['height_monitor'] = $monitorResult;
                }
            }
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
            $result = createWalletFromMnemonic($walletManager, $blockchainManager, $mnemonic);
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
            // Clean and validate private key; ensure it matches fromAddress
            try {
                $cleanKey = preg_replace('/[^0-9a-f]/i', '', (string)$privateKey);
                $cleanKey = strtolower(ltrim(str_replace('0x', '', $cleanKey), '0'));
                if (strlen($cleanKey) < 64) { $cleanKey = str_pad($cleanKey, 64, '0', STR_PAD_LEFT); }
                if (strlen($cleanKey) !== 64 || !ctype_xdigit($cleanKey) || $cleanKey === str_repeat('0', 64)) {
                    throw new Exception('Invalid private key format');
                }
                $kp = \Blockchain\Core\Cryptography\KeyPair::fromPrivateKey($cleanKey);
                $addrFromKey = strtolower($kp->getAddress());
                if (strtolower($fromAddress) !== $addrFromKey) {
                    throw new Exception('Private key does not correspond to from_address');
                }
                // Use cleaned key for downstream operations
                $privateKey = $cleanKey;
            } catch (Exception $e) {
                throw new Exception('Private key validation failed: ' . $e->getMessage());
            }

            $result = transferTokens($walletManager, $blockchainManager, $fromAddress, $toAddress, $amount, $privateKey, $memo);
            break;
            
        case 'decrypt_message':
            $encryptedMessage = $input['encrypted_message'] ?? '';
            $privateKey = $input['private_key'] ?? '';
            $senderPublicKey = $input['sender_public_key'] ?? '';

            // Enhanced validation for security
            if (!$encryptedMessage || !$privateKey) {
                throw new Exception('Encrypted message and private key are required');
            }

            // SECURITY FIX: Clean private key input (remove HTML tags and whitespace)
            $privateKey = strip_tags(trim($privateKey));
            
            // SECURITY FIX: Comprehensive private key validation
            // 1. Remove all non-hex characters and normalize
            $cleanKey = preg_replace('/[^0-9a-f]/i', '', $privateKey);
            $cleanKey = strtolower(ltrim(str_replace('0x', '', $cleanKey), '0'));
            
            // 2. Pad with zeros if needed and check length
            if (strlen($cleanKey) < 64) {
                $cleanKey = str_pad($cleanKey, 64, '0', STR_PAD_LEFT);
            }
            
            // 3. Validate final format
            \Blockchain\Wallet\WalletLogger::debug("Key validation steps", [
                'original' => $privateKey,
                'cleaned' => $cleanKey,
                'length' => strlen($cleanKey),
                'is_zero' => $cleanKey === str_repeat('0', 64)
            ]);
            
            if (strlen($cleanKey) !== 64 || !ctype_xdigit($cleanKey)) {
                throw new Exception('Invalid key format. Expected 64 hex chars after cleaning');
            }
            
            if ($cleanKey === str_repeat('0', 64)) {
                throw new Exception('Zero key is invalid');
            }
            
            // 4. Try to create KeyPair instance for final validation
            try {
                $keyPair = \Blockchain\Core\Cryptography\KeyPair::fromPrivateKey($cleanKey);
            } catch (Exception $e) {
                throw new Exception('Key validation failed: '.$e->getMessage());
            }

            // SECURITY FIX: Log decryption attempt for audit trail
            $requestId = $GLOBALS['__REQ_ID'] ?? 'unknown';
            writeLog("SECURITY: Decrypt attempt from {$requestId} for key hash: " . hash('sha256', $privateKey), 'WARNING');

            // Server-side compatibility: inject recipient_public_key if missing
            try {
                // Support both string JSON and array input
                if (is_array($encryptedMessage)) {
                    $secureObj = $encryptedMessage;
                } else {
                    $secureObj = json_decode((string)$encryptedMessage, true);
                }
                if (is_array($secureObj) && isset($secureObj['encrypted_data']) && is_array($secureObj['encrypted_data'])) {
                    $enc =& $secureObj['encrypted_data'];
                    if (empty($enc['recipient_public_key'])) {
                        // Derive recipient public key from provided private key (use cleaned key)
                        $kp = \Blockchain\Core\Cryptography\KeyPair::fromPrivateKey($cleanKey);
                        $enc['recipient_public_key'] = $kp->getPublicKey();
                    }
                    $encryptedMessage = json_encode($secureObj);
                }
            } catch (\Throwable $e) {
                // Best-effort; proceed without modification
            }

            try {
                // Ensure encryptedMessage is properly formatted
                if (is_array($encryptedMessage)) {
                    $encryptedMessage = json_encode($encryptedMessage, JSON_THROW_ON_ERROR);
                }
                
                if (!is_string($encryptedMessage)) {
                    throw new Exception('Encrypted message must be a JSON string or array');
                }

                // Use cleaned private key for decryption to avoid format issues (e.g., 0x prefix)
                $result = decryptMessage($encryptedMessage, $cleanKey, $senderPublicKey);
                
            } catch (JsonException $e) {
                throw new Exception('Failed to encode encrypted message: ' . $e->getMessage());
            }
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
            
        case 'broadcast_transaction':
            $transaction = $input['transaction'] ?? null;
            $sourceNode = $input['source_node'] ?? '';
            $timestamp = $input['timestamp'] ?? time();
            
            if (!$transaction) {
                throw new Exception('Transaction data is required');
            }
            
            $result = receiveBroadcastedTransaction($walletManager, $transaction, $sourceNode, $timestamp);
            break;
            
        case 'listnodes':
            $result = getNetworkTopology($walletManager);
            break;
            
        case 'update_topology':
            $result = updateNetworkTopology($walletManager);
            break;
            
        case 'get_optimal_broadcast_nodes':
            $transactionHash = $input['transaction_hash'] ?? '';
            $batchSize = (int)($input['batch_size'] ?? 10);
            
            if (!$transactionHash) {
                throw new Exception('Transaction hash is required');
            }
            
            $result = selectOptimalBroadcastNodes($walletManager, $transactionHash, $batchSize);
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

        // Emit wallet event (best-effort)
        try {
            emitWalletEvent($walletManager, [
                'update_type' => 'create_wallet',
                'address' => $walletData['address'] ?? '',
                'data' => [ 'public_key' => $walletData['public_key'] ?? null ]
            ]);
        } catch (Exception $e) { writeLog('emitWalletEvent(create_wallet) failed: ' . $e->getMessage(), 'WARNING'); }

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
 * Stake tokens via WalletManager with full blockchain integration
 */
function stakeTokens($walletManager, $address, $amount, $period, $privateKey) {
    try {
        $pdo = $walletManager->getDatabase();
        $baseDir = dirname(__DIR__);
        
        // Include SmartContractManager for contract deployment
        require_once $baseDir . '/contracts/SmartContractManager.php';
        require_once $baseDir . '/core/Storage/StateStorage.php';
        require_once $baseDir . '/core/SmartContract/VirtualMachine.php';
        require_once $baseDir . '/core/Logging/NullLogger.php';
        
        // Initialize SmartContractManager
        $stateStorage = new \Blockchain\Core\Storage\StateStorage($pdo);
        $vm = new \Blockchain\Core\SmartContract\VirtualMachine(3000000); // gas limit
        $logger = new \Blockchain\Core\Logging\NullLogger();
        $contractManager = new \Blockchain\Contracts\SmartContractManager($vm, $stateStorage, $logger);
        
        // Load config for contract deployment
        $configFile = $baseDir . '/config/config.php';
        $config = [];
        if (file_exists($configFile)) {
            $config = require $configFile;
        }
        
    // Do NOT auto-deploy contracts here; staking must use existing contract only
        // Resolve staking contract address (best effort, guarded by config inside resolver)
        $contractAddresses = [];
        try {
            $resolved = getOrDeployStakingContract($pdo, $address);
            if (is_string($resolved) && str_starts_with($resolved, '0x') && strlen($resolved) === 42) {
                $contractAddresses['staking'] = strtolower($resolved);
            }
        } catch (\Throwable $e) {
            // keep empty => staking via contract will be skipped
        }
        
        // Add validator to ValidatorManager (same as genesis installation)
        require_once $baseDir . '/core/Consensus/ValidatorManager.php';
        $validatorManager = new \Blockchain\Core\Consensus\ValidatorManager($pdo, $config);
        
        // Create validator record
        // Get public key from wallet
        $walletStmt = $pdo->prepare("SELECT public_key FROM wallets WHERE address = ?");
        $walletStmt->execute([$address]);
        $wallet = $walletStmt->fetch();
        $publicKey = $wallet['public_key'] ?? null;
        
        if ($publicKey) {
            $validatorResult = $validatorManager->addValidator($address, $publicKey, (int)$amount);
            if (!$validatorResult) {
                writeLog("Failed to add validator: " . ($validatorResult['error'] ?? 'Unknown error'), 'WARNING');
            } else {
                writeLog("Added validator: " . $address, 'INFO');
            }
        } else {
            writeLog("Cannot add validator: public key not found for address " . $address, 'WARNING');
        }
        
        // Execute staking transaction through smart contract
        if (!empty($contractAddresses['staking'])) {
            $stakingContractAddress = $contractAddresses['staking'];
            writeLog("Executing staking through contract: " . $stakingContractAddress, 'INFO');
            
            // Create staking transaction using existing blockchain manager
            $blockchainManager = new \Blockchain\Wallet\WalletBlockchainManager($pdo, $config);
            
            // Create transaction data for staking
            $transactionData = [
                'hash' => '0x' . hash('sha256', 'stake_' . $address . '_' . $amount . '_' . time()),
                'type' => 'stake',
                'from' => $address,
                'to' => $stakingContractAddress,
                'amount' => $amount,
                'fee' => 0.0,
                'timestamp' => time(),
                'data' => [
                    'method' => 'stake',
                    'params' => [
                        'staker' => $address,
                        'amount' => $amount,
                        'period' => $period,
                        'contract_address' => $stakingContractAddress
                    ],
                    'action' => 'stake_tokens'
                ],
                'signature' => '', // Will be signed by ValidatorManager
                'status' => 'pending'
            ];
            
            // Record transaction in blockchain
            $result = $blockchainManager->recordTransactionInBlockchain($transactionData);
            
            if ($result['blockchain_recorded']) {
                writeLog("Staking transaction recorded in blockchain: " . $result['block']['hash'], 'INFO');
            } else {
                writeLog("Failed to record staking transaction in blockchain: " . ($result['error'] ?? 'Unknown error'), 'WARNING');
            }
        }
        
    // Update wallet balance (subtract staked amount)
        $availableBalance = $walletManager->getAvailableBalance($address);
    $newBalance = $availableBalance - $amount;
    if ($newBalance < 0) { $newBalance = 0; }
        $stmt = $pdo->prepare("
            UPDATE wallets
            SET balance = ?, updated_at = NOW()
            WHERE address = ?
        ");
        $stmt->execute([$newBalance, $address]);
        
        // Add staking record with contract address
        $stmt = $pdo->prepare("
            INSERT INTO staking (staker, amount, status, start_block, validator, contract_address)
            VALUES (?, ?, 'active', ?, ?, ?)
        ");
        $currentBlock = getCurrentBlockHeight($pdo);
    $contractAddress = $contractAddresses['staking'] ?? null;
        $stmt->execute([$address, $amount, $currentBlock, $address, $contractAddress]);
        
        // Record staking transaction using public method
        try {
            $stakingTransaction = $walletManager->createTransaction(
                $address,
                'staking_pool',
                $amount,
                0, // No fee for staking
                null, // No private key needed for this type of transaction
                json_encode(['staking_type' => 'stake', 'timestamp' => time()])
            );
            $walletManager->sendTransaction($stakingTransaction);
        } catch (Exception $e) {
            writeLog("Failed to record staking transaction: " . $e->getMessage(), 'WARNING');
        }
        
        // Fetch updated balances
        $availableBalance = $walletManager->getAvailableBalance($address);
        $stakedBalance = $walletManager->getStakedBalance($address);
        
        // Emit wallet event (best-effort)
        try {
            emitWalletEvent($walletManager, [
                'update_type' => 'stake',
                'address' => $address,
                'data' => [
                    'amount' => $amount,
                    'period' => $period,
                    'contracts_deployed' => $contractAddresses,
                    'validator_added' => $validatorResult['success'] ?? false
                ]
            ]);
        } catch (Exception $e) { writeLog('emitWalletEvent(stake legacy) failed: ' . $e->getMessage(), 'WARNING'); }

        return [
            'staked' => [
                'success' => true,
                'amount' => $amount,
                'period' => $period,
                'contracts_deployed' => $contractAddresses,
                'validator_added' => $validatorResult['success'] ?? false,
                'new_balances' => [
                    'available' => $availableBalance,
                    'staked' => $stakedBalance,
                    'total' => $availableBalance + $stakedBalance
                ]
            ]
        ];
        
    } catch (Exception $e) {
        writeLog("Error in stakeTokens: " . $e->getMessage(), 'ERROR');
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
                writeLog("Wallet already exists in database, rejecting create request", 'INFO');
                throw new Exception("Wallet with this mnemonic already exists. Please use 'Restore Wallet' instead of 'Create Wallet'. Address: " . $derivedAddress);
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
        
        // Emit wallet event (best-effort)
        try {
            emitWalletEvent($walletManager, [
                'update_type' => 'create_wallet',
                'address' => $walletData['address'] ?? '',
                'data' => [ 'public_key' => $walletData['public_key'] ?? null ]
            ]);
        } catch (Exception $e) { writeLog('emitWalletEvent(create from mnemonic) failed: ' . $e->getMessage(), 'WARNING'); }

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

        // Auto-mine check after wallet creation
        try {
            global $config;
            $autoMineResult = autoMineBlocks($walletManager, $config);
            if (isset($autoMineResult)) {
                $result['auto_mine'] = $autoMineResult;
            }
        } catch (Exception $e) { writeLog('autoMineBlocks(create from mnemonic) failed: ' . $e->getMessage(), 'WARNING'); }
        
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
        
        // Emit wallet event (best-effort)
        try {
            emitWalletEvent($walletManager, [
                'update_type' => 'restore_wallet',
                'address' => $walletData['address'] ?? '',
                'data' => [ 'public_key' => $walletData['public_key'] ?? null ]
            ]);
        } catch (Exception $e) { writeLog('emitWalletEvent(restore_wallet) failed: ' . $e->getMessage(), 'WARNING'); }

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

        // Auto-mine check after wallet restoration (if blockchain registration occurred)
        try {
            global $config;
            if ($blockchainRegistered && !$isVerified) {
                // Only auto-mine if we actually registered in blockchain (new transaction created)
                $autoMineResult = autoMineBlocks($walletManager, $config);
                if (isset($autoMineResult)) {
                    $result['auto_mine'] = $autoMineResult;
                }
            }
        } catch (Exception $e) { writeLog('autoMineBlocks(restore_wallet) failed: ' . $e->getMessage(), 'WARNING'); }
        
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
        // Per-sender serialization lock to avoid nonce/balance races under concurrency
        $baseDir = dirname(__DIR__);
        $lockDir = $baseDir . '/storage/tmp/locks';
        if (!is_dir($lockDir)) { @mkdir($lockDir, 0777, true); }
        $lockKey = preg_replace('/[^a-z0-9]/i', '_', strtolower($fromAddress));
        $lockFile = $lockDir . '/transfer_' . $lockKey . '.lock';
        $lockFp = @fopen($lockFile, 'c');
        $gotLock = false;
        if ($lockFp) {
            // Block until we acquire an exclusive lock (short timeout)
            $waitStart = time();
            while (!( $gotLock = flock($lockFp, LOCK_EX | LOCK_NB) )) {
                if (time() - $waitStart > 10) { break; }
                usleep(100000); // 100ms
            }
        }
        if (!$gotLock) {
            writeLog("Failed to acquire transfer lock for $fromAddress", 'WARNING');
            throw new Exception('Busy: concurrent transfers from this address, please retry');
        }
        // Ensure lock file timestamp updated (for housekeeping)
        @ftruncate($lockFp, 0); @fwrite($lockFp, (string)time());
        
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
        $transferTx = [
            'hash' => '0x' . hash('sha256', 'transfer_' . $fromAddress . '_' . $toAddress . '_' . $amount . '_' . time()),
            'type' => 'transfer',
            'from' => $fromAddress,
            'to' => $toAddress,
            'amount' => $amount,
            'fee' => $amount * 0.001, // 0.1% fee
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
        
        // Check if we need to start a new transaction
        $needsTransaction = !$pdo->inTransaction();
        
        if ($needsTransaction) {
            $pdo->beginTransaction();
        }
        
        try {
            // Verify sender balance before deduction
            $stmt = $pdo->prepare("SELECT balance FROM wallets WHERE address = ?");
            $stmt->execute([$fromAddress]);
            $currentBalance = $stmt->fetchColumn();
            
            if ($currentBalance === false) {
                throw new Exception("Sender wallet not found in database: $fromAddress");
            }
            
            $totalDeduction = $amount + $transferTx['fee'];
            if ($currentBalance < $totalDeduction) {
                throw new Exception("Insufficient balance. Current: $currentBalance, Required: $totalDeduction");
            }
            
            // Auto-create recipient wallet if doesn't exist (for MetaMask/external wallets)
            if (!$walletManager->walletExists($toAddress)) {
                writeLog("Auto-creating wallet for recipient address: $toAddress (likely MetaMask wallet)", 'INFO');
                $createResult = $walletManager->createPlaceholderWallet($toAddress);
                writeLog("Wallet auto-creation result: " . json_encode($createResult), 'DEBUG');
            }
            
            // Deduct from sender
            $stmt = $pdo->prepare("UPDATE wallets SET balance = balance - ?, updated_at = NOW() WHERE address = ?");
            $stmt->execute([$totalDeduction, $fromAddress]);
            
            // Add to recipient
            $stmt = $pdo->prepare("UPDATE wallets SET balance = balance + ?, updated_at = NOW() WHERE address = ?");
            $stmt->execute([$amount, $toAddress]);
            
            if ($needsTransaction) {
                $pdo->commit();
            }
            writeLog("Database balances updated successfully", 'INFO');
            
        } catch (Exception $e) {
            if ($needsTransaction && $pdo->inTransaction()) {
                try {
                    $pdo->rollback();
                } catch (Exception $rollbackError) {
                    writeLog("Failed to rollback transaction: " . $rollbackError->getMessage(), 'ERROR');
                }
            }
            throw new Exception('Failed to update balances: ' . $e->getMessage());
        }
        
    // 7. Record in blockchain
        $blockchainResult = $blockchainManager->recordTransactionInBlockchain($transferTx);
        
        // 8. Update transaction status
        $transferTx['status'] = 'confirmed';
        
        // 9. Broadcast transaction to all network nodes via multi_curl
        $networkResult = broadcastTransactionToNetwork($transferTx, $pdo);
        
        // 10. Auto-mine blocks if enabled
        $config = getNetworkConfigFromDatabase($pdo);
        $autoMineResult = null;
        
        if ($config['auto_mine.enabled'] ?? true) {
            writeLog("Attempting auto-mine after transfer", 'INFO');
            $autoMineResult = autoMineBlocks($walletManager, $config);
            writeLog("Auto-mine result: " . json_encode($autoMineResult), 'INFO');
        }

        // 11. Auto-sync after transfer if enabled
        if ($config['auto_sync.enabled'] ?? true) {
            writeLog("Checking auto-sync after transfer", 'INFO');
            $autoSyncResult = autoSyncBlockchain($walletManager, $config);
            if ($autoSyncResult['synced']) {
                writeLog("Auto-sync triggered after transfer: " . json_encode($autoSyncResult), 'INFO');
            }
        }

        // 12. Monitor blockchain height after transfer
        if ($config['monitor.enabled'] ?? true) {
            $monitorResult = monitorBlockchainHeight($walletManager, $config);
            if ($monitorResult['status'] !== 'healthy') {
                writeLog("Height monitor alert after transfer: " . json_encode($monitorResult), 'WARNING');
            }
        }
        
        writeLog("Token transfer completed successfully", 'INFO');

        // Emit wallet events (best-effort)
        try {
            emitWalletEvent($walletManager, [
                'update_type' => 'transfer',
                'address' => $fromAddress,
                'data' => [ 'transaction' => $transferTx ]
            ]);
            emitWalletEvent($walletManager, [
                'update_type' => 'transfer',
                'address' => $toAddress,
                'data' => [ 'transaction' => $transferTx ]
            ]);
        } catch (Exception $e) { writeLog('emitWalletEvent(transfer) failed: ' . $e->getMessage(), 'WARNING'); }
        
    $response = [
            'transaction' => $transferTx,
            'blockchain' => $blockchainResult,
            'network_broadcast' => $networkResult,
            'auto_mine' => $autoMineResult,
            'new_balances' => [
                'sender' => $walletManager->getBalance($fromAddress),
                'recipient' => $walletManager->getBalance($toAddress)
            ]
    ];
    // Release sender lock
    if (isset($lockFp) && $lockFp) { @flock($lockFp, LOCK_UN); @fclose($lockFp); }
    return $response;
        
    } catch (Exception $e) {
    // Release sender lock on error
    if (isset($lockFp) && $lockFp) { @flock($lockFp, LOCK_UN); @fclose($lockFp); }
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
            'hash' => '0x' . hash('sha256', 'stake_' . $address . '_' . $amount . '_' . time()),
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
        
        // Check if we need to start a new transaction
        $needsTransaction = !$pdo->inTransaction();
        
        if ($needsTransaction) {
            $pdo->beginTransaction();
        }
        
        try {
            // Get current block height
            $currentBlockHeight = $blockchainManager->getCurrentBlockHeight();
            error_log("DEBUG: Current block height: " . $currentBlockHeight . " (type: " . gettype($currentBlockHeight) . ")");
            
            // Verify current balance before staking
            $stmt = $pdo->prepare("SELECT balance FROM wallets WHERE address = ?");
            $stmt->execute([$address]);
            $currentBalance = $stmt->fetchColumn();
            
            if ($currentBalance === false) {
                throw new Exception("Wallet not found in database: $address");
            }
            
            if ($currentBalance < $amount) {
                throw new Exception("Insufficient balance for staking. Current: $currentBalance, Required: $amount");
            }
            
            // Move from available to staked balance
            $stmt = $pdo->prepare("UPDATE wallets SET balance = balance - ?, staked_balance = staked_balance + ?, updated_at = NOW() WHERE address = ?");
            $stmt->execute([$amount, $amount, $address]);
            
            // Record staking details - use direct SQL since prepared statements cause truncation
            $escapedAddress = addslashes($address);
            $sql = "INSERT INTO staking (staker, amount, status, start_block, created_at, validator, reward_rate, rewards_earned, last_reward_block)
                    VALUES ('$escapedAddress', $amount, 'active', $currentBlockHeight, NOW(), '$escapedAddress', 0.0500, 0.00000000, 0)";
            
            error_log("DEBUG: Direct SQL query: " . $sql);
            error_log("DEBUG: Current block height: " . $currentBlockHeight . " (type: " . gettype($currentBlockHeight) . ")");
            
            try {
                $result = $pdo->exec($sql);
                error_log("DEBUG: Direct SQL result: " . $result);
            } catch (Exception $e) {
                error_log("DEBUG: Direct SQL error: " . $e->getMessage());
                throw $e;
            }
            
            if ($needsTransaction) {
                $pdo->commit();
            }
            writeLog("Staking record created successfully", 'INFO');
            
        } catch (Exception $e) {
            if ($needsTransaction && $pdo->inTransaction()) {
                try {
                    $pdo->rollback();
                } catch (Exception $rollbackError) {
                    writeLog("Failed to rollback transaction: " . $rollbackError->getMessage(), 'ERROR');
                }
            }
            throw new Exception('Failed to create staking record: ' . $e->getMessage());
        }
        
        // 7. Record in blockchain
        $blockchainResult = $blockchainManager->recordTransactionInBlockchain($stakeTx);
        
        // 8. Update transaction status
        $stakeTx['status'] = 'confirmed';
        
        // Emit wallet event (best-effort)
        try {
            emitWalletEvent($walletManager, [
                'update_type' => 'stake',
                'address' => $address,
                'data' => [ 'transaction' => $stakeTx ]
            ]);
        } catch (Exception $e) { writeLog('emitWalletEvent(stake) failed: ' . $e->getMessage(), 'WARNING'); }

        writeLog("Token staking completed successfully", 'INFO');
        
        $result = [
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

        // Auto-mine check after staking transaction
        try {
            global $config;
            $autoMineResult = autoMineBlocks($walletManager, $config);
            if (isset($autoMineResult)) {
                $result['auto_mine'] = $autoMineResult;
            }
        } catch (Exception $e) { writeLog('autoMineBlocks(stake) failed: ' . $e->getMessage(), 'WARNING'); }

        return $result;
        
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
    if ($periodDays == 7) return 4.0;    // 4% APY for 7 days
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
            'hash' => '0x' . hash('sha256', 'unstake_' . $address . '_' . $amount . '_' . time()),
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

        // Emit wallet event (best-effort)
        try {
            emitWalletEvent($walletManager, [
                'update_type' => 'unstake',
                'address' => $address,
                'data' => [ 'transaction' => $unstakeTx ]
            ]);
        } catch (Exception $e) { writeLog('emitWalletEvent(unstake) failed: ' . $e->getMessage(), 'WARNING'); }
        
        $result = [
            'transaction' => $unstakeTx,
            'blockchain' => $blockchainResult,
            'unstaked_amount' => $amount,
            'rewards_earned' => $totalRewards,
            'total_received' => $amount + $totalRewards,
            'new_balance' => $walletManager->getBalance($address)
        ];

        // Auto-mine check after unstaking transaction
        try {
            global $config;
            $autoMineResult = autoMineBlocks($walletManager, $config);
            if (isset($autoMineResult)) {
                $result['auto_mine'] = $autoMineResult;
            }
        } catch (Exception $e) { writeLog('autoMineBlocks(unstake) failed: ' . $e->getMessage(), 'WARNING'); }
        
        return $result;
        
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

            // Emit wallet event (best-effort)
            try {
                emitWalletEvent($walletManager, [
                    'update_type' => 'delete_wallet',
                    'address' => $address,
                    'data' => []
                ]);
            } catch (Exception $e) { writeLog('emitWalletEvent(delete_wallet) failed: ' . $e->getMessage(), 'WARNING'); }
            
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
                $height = getCurrentBlockHeight($pdo);
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
                $address = normalizeHexAddress($params[0] ?? '');
                if (!$address) return '0x0';
                $decimals = getTokenDecimals($networkConfig);
                try {
                    $pdo = $walletManager->getDatabase();
                    // 1) Try cached balance from wallets
                    $stmt = $pdo->prepare("SELECT balance FROM wallets WHERE address = ? LIMIT 1");
                    $stmt->execute([$address]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && isset($row['balance'])) {
                        $balDec = (float)$row['balance'];
                    } else {
                        // 2) Auto-create wallet for MetaMask/external addresses  
                        if (!$walletManager->walletExists($address)) {
                            writeLog("eth_getBalance: Auto-creating wallet for address $address (MetaMask request)", 'INFO');
                            $walletManager->createPlaceholderWallet($address);
                        }
                        
                        // 3) If no cache, compute once and persist
                        $balDec = computeWalletBalanceDecimal($pdo, $address);
                        try {
                            $upd = $pdo->prepare("UPDATE wallets SET balance = ?, updated_at = NOW() WHERE address = ?");
                            $upd->execute([$balDec, $address]);
                        } catch (\Throwable $e2) { /* ignore if wallet row absent */ }
                    }
                    // Return as wei hex
                    $unitsDec = decimalAmountToUnitsString($balDec, $decimals);
                $hex = decimalStringToHex($unitsDec);
                return '0x' . ($hex === '' ? '0' : $hex);
                } catch (\Throwable $e) {
                    return '0x0';
                }
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
                $address = normalizeHexAddress($params[0] ?? '');
                $blockTag = strtolower($params[1] ?? 'latest');
                if (!$address) return '0x0';
                
                // Auto-create wallet for MetaMask/external addresses if needed
                if (!$walletManager->walletExists($address)) {
                    writeLog("eth_getTransactionCount: Auto-creating wallet for address $address (MetaMask nonce request)", 'INFO');
                    $walletManager->createPlaceholderWallet($address);
                }
                
                // Use WalletManager nonce; treat it as next nonce, return as is for 'pending' and for 'latest'
                $next = 0;
                try { $next = (int)$walletManager->getNextNonce($address); } catch (Throwable $e) {}
                // If we consider 'latest' to be confirmed only, you might subtract 1 when next>0. Keep simple for now.
                return '0x' . dechex(max(0, $next));
            }

            case 'eth_gasPrice':
                // Return dynamic gas price (base + priority) in wei
                try {
                    $pdo = $walletManager->getDatabase();
                    $fees = getSuggestedFeesWei($pdo);
                    return '0x' . dechex(max(0, (int)$fees['gasPrice']));
                } catch (Throwable $e) {
                    return '0x3b9aca00'; // 1 gwei fallback
                }

            case 'eth_maxPriorityFeePerGas':
                try {
                    $pdo = $walletManager->getDatabase();
                    $fees = getSuggestedFeesWei($pdo);
                    return '0x' . dechex(max(0, (int)$fees['priority']));
                } catch (Throwable $e) {
                    return '0x77359400'; // 2 gwei fallback
                }

            case 'eth_estimateGas':
                // Return a fixed 21000 units as a placeholder
                return '0x5208';

            case 'eth_feeHistory': {
                // params: [blockCount, newestBlock, rewardPercentiles]
                // Provide simple, non-zero base fees so wallets can compute suggestions
                $blockCount = $params[0] ?? '0x0';
                $newest = $params[1] ?? 'latest';
                $count = 0;
                if (is_string($blockCount) && str_starts_with($blockCount, '0x')) {
                    $count = (int)hexdec($blockCount);
                } elseif (is_numeric($blockCount)) {
                    $count = (int)$blockCount;
                }
                if ($count <= 0) $count = 1;
                try {
                    $pdo = $walletManager->getDatabase();
                    $fees = getSuggestedFeesWei($pdo);
                    $base = max(1, (int)$fees['base']);
                    // build slight variation for history
                    $baseFees = [];
                    for ($i = 0; $i < $count; $i++) {
                        $adj = max(0, $base - ($i * (int)round($base * 0.02))); // small decline
                        $baseFees[] = '0x' . dechex($adj);
                    }
                    $gasUsedRatio = array_fill(0, $count, 0.15);
                    $percentiles = $params[2] ?? [];
                    $reward = [];
                    if (is_array($percentiles) && !empty($percentiles)) {
                        $priority = max(1, (int)$fees['priority']);
                        $rewardRow = array_fill(0, count($percentiles), '0x' . dechex($priority));
                        $reward = array_fill(0, $count, $rewardRow);
                    }
                } catch (Throwable $e) {
                    $baseFees = array_fill(0, $count, '0x3b9aca00');
                    $gasUsedRatio = array_fill(0, $count, 0.15);
                    $reward = [];
                }
                $percentiles = $params[2] ?? [];
                if (is_array($percentiles) && !empty($percentiles)) {
                    if (empty($reward)) {
                        $reward = array_fill(0, $count, array_fill(0, count($percentiles), '0x77359400'));
                    }
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
            $h = strtolower(trim((string)$hash));
            $h0 = str_starts_with($h,'0x') ? $h : ('0x'.$h);
            $h1 = str_starts_with($h,'0x') ? substr($h,2) : $h;
            $stmt = $pdo->prepare("SELECT tx_hash as hash, from_address, to_address, amount, fee, nonce, gas_limit, gas_price, data, signature, created_at as timestamp FROM mempool WHERE tx_hash = ? OR tx_hash = ? LIMIT 1");
            $stmt->execute([$h0, $h1]);
                        $mp = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($mp) {
                            $decimals = getTokenDecimals($networkConfig);
                            $multiplier = 10 ** $decimals;
                            $valueHex = '0x' . dechex((int)floor(((float)($mp['amount'] ?? 0)) * $multiplier));
                            $chainInfo = method_exists($networkConfig, 'getNetworkInfo') ? $networkConfig->getNetworkInfo() : [];
                            $chainIdHex = '0x' . dechex((int)($chainInfo['chain_id'] ?? 0));
                                $retHash = strtolower((string)($mp['hash'] ?? ''));
                                if ($retHash !== '' && !str_starts_with($retHash, '0x')) { $retHash = '0x' . $retHash; }
                                return [
                                    'hash' => $retHash,
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
                                'type' => '0x2',
                                'accessList' => [],
                                'chainId' => $chainIdHex,
                                'v' => '0x0', 'r' => '0x0', 's' => '0x0',
                                'input' => is_string($mp['data'] ?? null) ? $mp['data'] : '0x',
                            ];
                        }
                    } catch (Throwable $e) {
                        writeLog('eth_getTransactionByHash mempool fallback error: ' . $e->getMessage(), 'ERROR');
                    }
                    // Fallback 2: raw_mempool file -> return pending view to avoid dropped
                    try {
                        $baseDir = dirname(__DIR__);
                        $rawPath1 = $baseDir . '/storage/raw_mempool/' . strtolower($hash) . '.json';
                        $rawPath2 = $baseDir . '/storage/raw_mempool/processed/' . strtolower($hash) . '.json';
                        $rawPath = null;
                        if (is_file($rawPath1)) { $rawPath = $rawPath1; }
                        elseif (is_file($rawPath2)) { $rawPath = $rawPath2; }
                        if ($rawPath) {
                            $rawJson = json_decode((string)@file_get_contents($rawPath), true);
                            if (is_array($rawJson)) {
                                $parsed = $rawJson['parsed'] ?? [];
                                $from = $parsed['from'] ?? null;
                                $to = $parsed['to'] ?? null;
                                $value = $parsed['value'] ?? '0x0';
                                $gas = $parsed['gas'] ?? '0x5208';
                                $gasPrice = $parsed['gasPrice'] ?? '0x0';
                                $nonce = $parsed['nonce'] ?? '0x0';
                                $chainInfo = method_exists($networkConfig, 'getNetworkInfo') ? $networkConfig->getNetworkInfo() : [];
                                $chainIdHex = '0x' . dechex((int)($chainInfo['chain_id'] ?? 0));
                                return [
                                    'hash' => $hash,
                                    'nonce' => is_string($nonce) ? $nonce : ('0x' . dechex((int)$nonce)),
                                    'blockHash' => null,
                                    'blockNumber' => null,
                                    'transactionIndex' => null,
                                    'from' => $from,
                                    'to' => $to,
                                    'value' => is_string($value) ? $value : '0x0',
                                    'gas' => is_string($gas) ? $gas : ('0x' . dechex((int)$gas)),
                                    'gasPrice' => is_string($gasPrice) ? $gasPrice : ('0x' . dechex((int)$gasPrice)),
                                    'maxFeePerGas' => '0x0',
                                    'maxPriorityFeePerGas' => '0x0',
                                    'type' => '0x0',
                                    'accessList' => [],
                                    'chainId' => $chainIdHex,
                                    'v' => '0x0','r' => '0x0','s' => '0x0',
                                    'input' => is_string($parsed['input'] ?? null) ? $parsed['input'] : '0x',
                                ];
                            }
                        }
                    } catch (Throwable $e) {
                        writeLog('eth_getTransactionByHash raw_mempool fallback error: ' . $e->getMessage(), 'ERROR');
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
                writeLog('Processing eth_sendRawTransaction request', 'INFO');
                $raw = $params[0] ?? '';
                if (!is_string($raw) || strlen($raw) < 4 || !str_starts_with($raw, '0x')) {
                    writeLog('Invalid raw transaction format: ' . substr($raw, 0, 50), 'ERROR');
                    return rpcError(-32602, 'Invalid raw transaction');
                }

                $rawHex = strtolower($raw);
                writeLog('Raw transaction hex: ' . substr($rawHex, 0, 100) . '...', 'DEBUG');
                
                $bin = @hex2bin(substr($rawHex, 2));
                if ($bin === false) {
                    writeLog('Raw transaction hex decode failed for: ' . substr($rawHex, 0, 50), 'ERROR');
                    return rpcError(-32602, 'Raw transaction hex decode failed');
                }

                // Compute tx hash as keccak256 of raw bytes (Ethereum-style)
                $txHash = '0x' . \Blockchain\Core\Crypto\Hash::keccak256($bin);
                writeLog('Computed transaction hash: ' . $txHash, 'INFO');

                // ANTI-LOOP CHECK: Check if this transaction was already processed recently
                try {
                    $pdo = $walletManager->getDatabase();
                    $currentNodeId = getCurrentNodeId($pdo);
                    
                    // Check in mempool (already processed)
                    $h = strtolower($txHash);
                    $h0 = str_starts_with($h,'0x') ? $h : ('0x'.$h);
                    $h1 = str_starts_with($h,'0x') ? substr($h,2) : $h;
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mempool WHERE tx_hash = ? OR tx_hash = ?");
                    $stmt->execute([$h0, $h1]);
                    $inMempool = $stmt->fetchColumn() > 0;
                    
                    // Check in transactions (already confirmed)
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE hash = ?");
                    $stmt->execute([$txHash]);
                    $inTransactions = $stmt->fetchColumn() > 0;
                    
                    // Check in broadcast_tracking (already broadcasted recently)
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) FROM broadcast_tracking 
                        WHERE transaction_hash = ? AND expires_at > NOW()
                    ");
                    $stmt->execute([$txHash]);
                    $recentlyBroadcasted = $stmt->fetchColumn() > 0;
                    
                    if ($inMempool || $inTransactions || $recentlyBroadcasted) {
                        writeLog("DUPLICATE DETECTED: Transaction $txHash already processed (mempool: $inMempool, confirmed: $inTransactions, recent: $recentlyBroadcasted)", 'WARNING');
                        
                        // Update duplicate prevention stats
                        updateBroadcastStats($pdo, 'duplicate_prevented', $currentNodeId);
                        
                        // Return the existing transaction hash (MetaMask expects this)
                        return $txHash;
                    }
                    
                    // Record this transaction in broadcast_tracking to prevent future duplicates
                    recordBroadcastTracking($pdo, $txHash, $currentNodeId, 0, "eth_sendRawTransaction");
                    
                    writeLog("Transaction $txHash passed anti-loop check, proceeding with processing", 'INFO');
                    
                } catch (\Throwable $e) {
                    writeLog('Anti-loop check failed: ' . $e->getMessage(), 'ERROR');
                    // Continue processing even if anti-loop check fails
                }

                // Try to parse minimal fields from RLP for visibility (best-effort)
                $parsed = parseEthRawTransaction($rawHex);
                writeLog('Parsed transaction data: ' . json_encode($parsed), 'DEBUG');

                // Security: must recover sender address from signature. Reject if failed.
                $recoveredFrom = null;
                try {
                    $recoveredFrom = \Blockchain\Core\Crypto\EthereumTx::recoverAddress($rawHex);
                } catch (\Throwable $e) {}
                if (!is_string($recoveredFrom) || !preg_match('/^0x[a-f0-9]{40}$/', strtolower($recoveredFrom))) {
                    writeLog('Rejecting raw tx (invalid signature recovery) for ' . $txHash, 'WARNING');
                    return rpcError(-32602, 'Invalid transaction signature');
                }

                // Strict EIP-1559 sanity checks (chainId, r/s length)
                try {
                    $first = ord($bin[0]);
                    if ($first === 0x02) {
                        $payload = substr($bin, 1);
                        $off = 0;
                        $decode = function($b,&$o) use (&$decode){
                            $len = strlen($b); if ($o >= $len) return null; $b0 = ord($b[$o]);
                            if ($b0 <= 0x7f) { $o++; return $b[$o-1]; }
                            if ($b0 <= 0xb7) { $l=$b0-0x80; $o++; $v=substr($b,$o,$l); $o+=$l; return $v; }
                            if ($b0 <= 0xbf) { $ll=$b0-0xb7; $o++; $lBytes=substr($b,$o,$ll); $o+=$ll; $l=intval(bin2hex($lBytes),16); $v=substr($b,$o,$l); $o+=$l; return $v; }
                            if ($b0 <= 0xf7) { $l=$b0-0xc0; $o++; $end=$o+$l; $arr=[]; while($o<$end){ $arr[]=$decode($b,$o);} return $arr; }
                            $ll=$b0-0xf7; $o++; $lBytes=substr($b,$o,$ll); $o+=$ll; $l=intval(bin2hex($lBytes),16); $end=$o+$l; $arr=[]; while($o<$end){ $arr[]=$decode($b,$o);} return $arr; };
                        $list = $decode($payload,$off);
                        if (is_array($list) && count($list) >= 12) {
                            $cfg = method_exists($networkConfig, 'getNetworkInfo') ? $networkConfig->getNetworkInfo() : [];
                            $cfgCid = (int)($cfg['chain_id'] ?? 0);
                            $txCid = intval(bin2hex($list[0] ?? ''), 16);
                            if ($cfgCid > 0 && $txCid !== $cfgCid) {
                                writeLog('Rejecting raw tx due chainId mismatch txCid=' . $txCid . ' cfgCid=' . $cfgCid, 'WARNING');
                                return rpcError(-32602, 'Invalid chainId');
                            }
                            $r = $list[10] ?? '';
                            $s = $list[11] ?? '';
                            $rlen = is_string($r) ? strlen($r) : 0;
                            $slen = is_string($s) ? strlen($s) : 0;
                            if ($rlen < 1 || $rlen > 33 || $slen < 1 || $slen > 33) {
                                writeLog('Rejecting raw tx due invalid r/s length', 'WARNING');
                                return rpcError(-32602, 'Invalid signature');
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    writeLog('Strict EIP-1559 checks error: ' . $e->getMessage(), 'WARNING');
                }

                // Persist raw tx to local queue for asynchronous processing (only after signature ok)
                $queued = queueRawTransaction($txHash, $rawHex, $parsed);
                if (!$queued) {
                    writeLog('Failed to persist raw tx queue for ' . $txHash, 'ERROR');
                } else {
                    writeLog('Queued raw tx ' . $txHash . ' (from MetaMask)', 'INFO');
                }

                // Best-effort: normalize minimal fields and accept locally + broadcast immediately
                try {
                    writeLog('Starting transaction normalization and processing', 'INFO');
                    $pdo = $walletManager->getDatabase();
                    // Ensure recent network topology before proceeding to broadcast
                    $topologyStatus = ensureFreshTopology($walletManager);
                    if (($topologyStatus['success'] ?? false) === false) {
                        writeLog('Topology check (raw tx) failed: ' . ($topologyStatus['reason'] ?? 'unknown'), 'WARNING');
                    } elseif (($topologyStatus['updated'] ?? false) === true) {
                        writeLog('Topology refreshed (raw tx pipeline)', 'INFO');
                    }
                    $decimals = getTokenDecimals($networkConfig);
                    // Convert value (wei-like) from hex to a decimal amount using chain decimals with 8-digit scale
                    $amountDecimal = 0.0;
                    if (isset($parsed['value']) && is_string($parsed['value'])) {
                        $vHex = strtolower($parsed['value']);
                        if (str_starts_with($vHex, '0x')) { $vHex = substr($vHex, 2); }
                        if ($vHex === '' || !ctype_xdigit($vHex)) { $vHex = '0'; }
                        $weiDec = hexToDecStringSafe($vHex);
                        $amountStr = scaleDownByDecimals($weiDec, max(0, (int)$decimals), 8);
                        $amountDecimal = (float)$amountStr;
                    }
                    $normalized = [
                        'hash' => $txHash, // keep 0x prefix to match mempool storage
                        'from_address' => strtolower($recoveredFrom),
                        'to_address' => normalizeHexAddress($parsed['to'] ?? ''),
                        'amount' => $amountDecimal,
                        'fee' => 0.0,
                        'nonce' => isset($parsed['nonce']) ? (is_string($parsed['nonce']) && str_starts_with(strtolower($parsed['nonce']), '0x') ? (int)hexdec($parsed['nonce']) : (int)$parsed['nonce']) : 0,
                        'gas_limit' => isset($parsed['gas']) ? (is_string($parsed['gas']) && str_starts_with(strtolower($parsed['gas']), '0x') ? (int)hexdec($parsed['gas']) : (int)$parsed['gas']) : 21000,
                        'gas_price' => isset($parsed['gasPrice']) ? (is_string($parsed['gasPrice']) && str_starts_with(strtolower($parsed['gasPrice']), '0x') ? (int)hexdec($parsed['gasPrice']) : (int)$parsed['gasPrice']) : 0,
                        'data' => is_string($parsed['input'] ?? null) ? ($parsed['input'] ?: '0x') : '0x',
                        'signature' => 'raw',
                        'status' => 'pending',
                        'timestamp' => time(),
                    ];
                    writeLog('Normalized transaction data: ' . json_encode($normalized), 'DEBUG');

                    // Accept locally into mempool (idempotent if already present)
                    try {
                        writeLog('Attempting to add transaction to local mempool', 'INFO');
                        $result = receiveBroadcastedTransaction($walletManager, $normalized, 'self', time());
                        writeLog('Local mempool result: ' . json_encode($result), 'INFO');
                        
                        // Simple direct logging to debug
                        $debugLog = __DIR__ . '/../logs/debug.log';
                        $timestamp = date('Y-m-d H:i:s');
                        $debugMsg = "[{$timestamp}] receiveBroadcastedTransaction result: " . json_encode($result) . PHP_EOL;
                        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
                        
                    } catch (\Throwable $e) {
                        writeLog('Local accept of raw tx failed: ' . $e->getMessage(), 'WARNING');
                        
                        // Simple direct logging to debug
                        $debugLog = __DIR__ . '/../logs/debug.log';
                        $timestamp = date('Y-m-d H:i:s');
                        $debugMsg = "[{$timestamp}] receiveBroadcastedTransaction ERROR: " . $e->getMessage() . PHP_EOL;
                        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
                    }

                    // Broadcast to peer nodes (non-blocking via multi_curl)
                    try {
                        $broadcastResult = broadcastTransactionToNetwork($normalized, $pdo);
                        writeLog('Broadcasted raw tx to network: ' . json_encode($broadcastResult), 'INFO');
                    } catch (\Throwable $e) {
                        writeLog('Broadcast of raw tx failed: ' . $e->getMessage(), 'ERROR');
                    }

                    // Optional: attempt auto-mining on this node if enabled in config
                    try {
                        $config = getNetworkConfigFromDatabase($pdo);
                        if (!empty($config['auto_mine.enabled'])) {
                            $am = autoMineBlocks($walletManager, $config);
                            writeLog('Auto-mine after raw tx: ' . json_encode($am), 'INFO');
                        }
                    } catch (\Throwable $e) {
                        writeLog('Auto-mine after raw tx failed: ' . $e->getMessage(), 'WARNING');
                    }
                } catch (\Throwable $e) {
                    writeLog('Normalization/broadcast pipeline for raw tx failed: ' . $e->getMessage(), 'WARNING');
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

                    // Best-effort network broadcast for MetaMask-style sendTransaction as well
                    try {
                        $pdo = $walletManager->getDatabase();
                        $normalized = [
                            'hash' => $result, // keep 0x prefix
                            'from_address' => normalizeHexAddress($from),
                            'to_address' => normalizeHexAddress($to),
                            'amount' => $amount,
                            'fee' => max(0.001, 0.0),
                            'nonce' => $nonce ?? 0,
                            'gas_limit' => $gasLimit,
                            'gas_price' => $gasPrice,
                            'data' => $data ?? '0x',
                            'signature' => 'local',
                            'status' => 'pending',
                            'timestamp' => time(),
                        ];

                        // Accept locally via same path to unify behavior
                        try {
                            receiveBroadcastedTransaction($walletManager, $normalized, 'self', time());
                        } catch (\Throwable $e) {
                            writeLog('Local accept of sendTransaction failed: ' . $e->getMessage(), 'WARNING');
                        }

                        // Ensure topology fresh before broadcast
                        try {
                            $topologyStatus = ensureFreshTopology($walletManager);
                            if (($topologyStatus['success'] ?? false) === false) {
                                writeLog('Topology check (eth_sendTransaction) failed: ' . ($topologyStatus['reason'] ?? 'unknown'), 'WARNING');
                            } elseif (($topologyStatus['updated'] ?? false) === true) {
                                writeLog('Topology refreshed (eth_sendTransaction)', 'INFO');
                            }
                        } catch (\Throwable $e) {
                            writeLog('Topology ensure error (eth_sendTransaction): ' . $e->getMessage(), 'WARNING');
                        }

                        $broadcastResult = broadcastTransactionToNetwork($normalized, $pdo);
                        writeLog('Broadcasted sendTransaction to network: ' . json_encode($broadcastResult), 'INFO');

                        // Optional: attempt auto-mining on this node if enabled
                        try {
                            $config = getNetworkConfigFromDatabase($pdo);
                            if (!empty($config['auto_mine.enabled'])) {
                                $am = autoMineBlocks($walletManager, $config);
                                writeLog('Auto-mine after sendTransaction: ' . json_encode($am), 'INFO');
                            }
                        } catch (\Throwable $e) {
                            writeLog('Auto-mine after sendTransaction failed: ' . $e->getMessage(), 'WARNING');
                        }
                    } catch (\Throwable $e) {
                        writeLog('Broadcast pipeline for sendTransaction failed: ' . $e->getMessage(), 'WARNING');
                    }

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
                
                // Get PDO connection from walletManager
                $pdo = $walletManager->getDatabase();
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
                    $height = getCurrentBlockHeight($pdo);
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
                    $height = getCurrentBlockHeight($pdo);
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
                    $height = getCurrentBlockHeight($pdo);
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
    // Enhanced debug logging
    $debugLog = __DIR__ . '/../logs/debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $debugMsg = "[{$timestamp}] getTransactionReceipt called for hash: {$hash}" . PHP_EOL;
    @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
    
    writeLog("getTransactionReceipt called for hash: $hash", 'DEBUG');
    try {
        $tx = $walletManager->getTransactionByHash($hash);
        $debugMsg = "[{$timestamp}] getTransactionByHash result: " . ($tx ? 'FOUND' : 'NOT_FOUND') . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        writeLog("getTransactionByHash result: " . ($tx ? 'FOUND' : 'NOT_FOUND'), 'DEBUG');
        if ($tx) {
            $debugMsg = "[{$timestamp}] Transaction details: " . json_encode($tx) . PHP_EOL;
            @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
            writeLog("Transaction details: " . json_encode($tx), 'DEBUG');
        }
        
        if (!$tx) {
            // If not confirmed yet, check mempool; auto-confirm no-op self-transfers
            try {
                $pdo = $walletManager->getDatabase();
                $h = strtolower(trim((string)$hash));
                $h0 = str_starts_with($h,'0x') ? $h : ('0x'.$h);
                $h1 = str_starts_with($h,'0x') ? substr($h,2) : $h;
                $stmt = $pdo->prepare("SELECT tx_hash as hash, from_address, to_address, amount, fee, nonce, gas_limit, gas_price, data, signature, created_at as timestamp FROM mempool WHERE tx_hash = ? OR tx_hash = ? LIMIT 1");
                $stmt->execute([$h0, $h1]);
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
                            // Remove from mempool by tx_hash (handle 0x and non-0x)
                            $dh = strtolower(trim((string)$hash));
                            $dh0 = str_starts_with($dh,'0x') ? $dh : ('0x'.$dh);
                            $dh1 = str_starts_with($dh,'0x') ? substr($dh,2) : $dh;
                            $del = $pdo->prepare("DELETE FROM mempool WHERE tx_hash = ? OR tx_hash = ?");
                            $del->execute([$dh0, $dh1]);
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
 * Convert human-readable decimal amount to smallest units as a pure decimal string (no overflow).
 */
function decimalAmountToUnitsString($amount, int $decimals): string {
    $str = is_string($amount) ? $amount : (string)$amount;
    $str = trim($str);
    if ($str === '' || $str === '0') return '0';
    $negative = false;
    if ($str[0] === '-') { $negative = true; $str = substr($str,1); }
    if ($str === '' || $str === '.') return '0';
    if (strpos($str,'e') !== false || strpos($str,'E') !== false) {
        // Avoid scientific notation; cast via sprintf
        $str = sprintf('%.'.($decimals+2).'f', (float)$str);
    }
    $parts = explode('.', $str, 2);
    $intPart = preg_replace('/\D/','', $parts[0] ?? '0');
    $fracPart = preg_replace('/\D/','', $parts[1] ?? '');
    if (strlen($fracPart) > $decimals) $fracPart = substr($fracPart,0,$decimals); // truncate
    $fracPart = str_pad($fracPart, $decimals, '0');
    $full = ltrim($intPart . $fracPart, '0');
    if ($full === '') $full = '0';
    return $negative ? '-' . $full : $full;
}

/**
 * Convert a (possibly very large) decimal string (non-negative) to hex without bcmath/gmp.
 */
function decimalStringToHex(string $decimal): string {
    $decimal = ltrim($decimal, '+');
    if ($decimal === '' || $decimal === '0') return '0';
    if ($decimal[0] === '-') return '0'; // balances should not be negative
    // Repeated division by 16 algorithm on string
    $hex = '';
    $digits = $decimal;
    while ($digits !== '0') {
        $quotient = '';
        $remainder = 0;
        $len = strlen($digits);
        for ($i=0;$i<$len;$i++) {
            $num = $remainder * 10 + (int)$digits[$i];
            $q = intdiv($num,16);
            $r = $num % 16;
            if ($quotient !== '' || $q > 0) $quotient .= (string)$q;
            $remainder = $r;
        }
        $hexDigit = dechex($remainder);
        $hex = $hexDigit . $hex;
        $digits = $quotient === '' ? '0' : $quotient;
    }
    return $hex;
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
        writeLog('queueRawTransaction called for hash: ' . $txHash, 'INFO');
        $dir = dirname(__DIR__) . '/storage/raw_mempool';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            writeLog('Created raw_mempool directory: ' . $dir, 'INFO');
        }
        $path = $dir . '/' . str_replace('0x', '', $txHash) . '.json';
        writeLog('Raw transaction file path: ' . $path, 'DEBUG');
        
        $payload = [
            'hash' => $txHash,
            'raw' => $rawHex,
            'parsed' => $parsed,
            'received_at' => time()
        ];
        $ok = (bool)@file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($ok) {
            // Debug log to trace raw queueing (helps diagnose missing mempool entries)
            $toDbg = isset($parsed['to']) ? $parsed['to'] : '(none)';
            $valDbg = isset($parsed['value']) ? $parsed['value'] : '(none)';
            writeLog("Queued raw tx $txHash to=$toDbg value=$valDbg", 'INFO');
        } else {
            writeLog("Failed writing raw tx file for $txHash (path=$path)", 'ERROR');
        }
        return $ok;
    } catch (\Throwable $e) {
        writeLog('queueRawTransaction error: ' . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Recover the 'from' address from an EIP-1559 transaction signature
 */
function recoverEIP1559FromAddress($chainIdHex, $nonceHex, $maxPriorityFeePerGasHex, $maxFeePerGasHex, $gasHex, $toHex, $valueHex, $inputHex, $accessList, $vRaw, $rRaw, $sRaw): ?string {
    // NOTE: Public key recovery for EIP-1559 is not supported in this build without the secp256k1 extension.
    // This stub avoids syntax/runtime issues and returns null to indicate recovery is unavailable.
    return null;
}

/**
 * Convert integer to RLP bytes
 */
function intToRLP(int $value): string {
    if ($value === 0) {
        return '';
    }
    
    $hex = dechex($value);
    if (strlen($hex) % 2 !== 0) {
        $hex = '0' . $hex;
    }
    
    return hex2bin($hex);
}

/**
 * Simple RLP encoding
 */
function rlpEncode($data): string {
    if (is_string($data)) {
        $length = strlen($data);
        if ($length === 1 && ord($data[0]) < 0x80) {
            return $data;
        } elseif ($length <= 55) {
            return chr(0x80 + $length) . $data;
        } else {
            $lengthEncoding = '';
            $temp = $length;
            while ($temp > 0) {
                $lengthEncoding = chr($temp & 0xff) . $lengthEncoding;
                $temp >>= 8;
            }
            return chr(0xb7 + strlen($lengthEncoding)) . $lengthEncoding . $data;
        }
    } elseif (is_array($data)) {
        $output = '';
        foreach ($data as $item) {
            $output .= rlpEncode($item);
        }
        $length = strlen($output);
        if ($length <= 55) {
            return chr(0xc0 + $length) . $output;
        } else {
            $lengthEncoding = '';
            $temp = $length;
            while ($temp > 0) {
                $lengthEncoding = chr($temp & 0xff) . $lengthEncoding;
                $temp >>= 8;
            }
            return chr(0xf7 + strlen($lengthEncoding)) . $lengthEncoding . $output;
        }
    }
    
    return '';
}

/**
 * Convert hex (big) integer to decimal string safely
 */
function hexToDecStringSafe(string $hex): string {
    $hex = ltrim(strtolower($hex), '0');
    if ($hex === '') return '0';
    $dec = '0';
    // Process each hex digit: dec = dec*16 + digit
    for ($i = 0, $n = strlen($hex); $i < $n; $i++) {
        $digit = hexdec($hex[$i]);
        // dec = dec*16
        $dec = bcmul($dec, '16', 0);
        // dec = dec + digit
        $dec = bcadd($dec, (string)$digit, 0);
    }
    return $dec;
}

/**
 * Scale down big integer decimal string by decimals, return string with fixed scale
 */
function scaleDownByDecimals(string $integerStr, int $decimals, int $scale = 8): string {
    if ($decimals <= 0) {
        return bcadd($integerStr, '0', $scale);
    }
    // integerStr / (10^decimals)
    $divisor = '1' . str_repeat('0', $decimals);
    return bcdiv($integerStr, $divisor, $scale);
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

        $isTyped = false;
        $typeByte = null;
        // Typed transaction detection (EIP-2718 / EIP-1559 0x02)
        if (strlen($bin) > 0) {
            $first = ord($bin[0]);
            if ($first <= 0x7f && in_array($first, [0x01, 0x02, 0x03], true)) { // known typed prefixes (support 0x02 now)
                $isTyped = true;
                $typeByte = $first;
                $bin = substr($bin, 1); // strip the type byte; remaining is RLP list
            }
        }

        // Simplified parsing to avoid memory issues

        // Simplified parsing to avoid memory issues
        // Simple output structure with basic field extraction
        $out = [
            'type' => $isTyped ? '0x' . dechex($typeByte) : '0x0',
            'nonce' => '0x0',
            'gasPrice' => '0x0',
            'gas' => '0x0',
            'to' => '0x',
            'value' => '0x0',
            'input' => '0x'
        ];
        
        // Parse both legacy and EIP-1559 transactions
        if ($isTyped && $typeByte === 0x02) {
            // EIP-1559 transaction parsing
            // Nonce: find after chainId. Look for '840135' (chainId with RLP short-len prefix) then take next 8 hex
            $cidPos = strpos($rawHex, '840135');
            if ($cidPos !== false && strlen($rawHex) >= $cidPos + 6 + 8) {
                $nonceHex = substr($rawHex, $cidPos + 6, 8);
                if (ctype_xdigit($nonceHex)) {
                    $out['nonce'] = '0x' . $nonceHex;
                }
            }

            // To address: find RLP '94' then 40 hex chars
            $toMarker = '94';
            $toPos = strpos($rawHex, $toMarker);
            if ($toPos !== false && $toPos + 42 <= strlen($rawHex)) {
                $toHex = substr($rawHex, $toPos + 2, 40);
                if (ctype_xdigit($toHex)) {
                    $out['to'] = '0x' . $toHex;
                }
            }

            // Value: search for 0x88..0x8f length prefixes after the 'to' address
            $scanStart = $toPos !== false ? $toPos + 42 : 0;
            if ($scanStart > 0 && $scanStart + 2 <= strlen($rawHex)) {
                for ($i = $scanStart; $i + 2 <= strlen($rawHex); $i += 2) {
                    $prefix = substr($rawHex, $i, 2);
                    if (preg_match('/^8[0-9a-f]$/i', $prefix)) {
                        $lenNibble = hexdec(substr($prefix, 1, 1));
                        // handle only up to 0x8f (15 bytes) safely
                        if ($lenNibble >= 0 && $lenNibble <= 0x0f) {
                            $valLen = $lenNibble; // bytes
                            if ($i + 2 + ($valLen * 2) <= strlen($rawHex) && $valLen > 0) {
                                $valHex = substr($rawHex, $i + 2, $valLen * 2);
                                if (ctype_xdigit($valHex)) {
                                    $out['value'] = '0x' . $valHex;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        } else {
            // Legacy transaction parsing (type 0x0)
            // RLP structure: [nonce, gasPrice, gasLimit, to, value, data, v, r, s]
            
            // Skip RLP list prefix (f8xx or f9xxxx)
            $pos = 0;
            if (str_starts_with($rawHex, 'f8') || str_starts_with($rawHex, 'f9')) {
                $pos = str_starts_with($rawHex, 'f8') ? 4 : 6;
            }
            
            // Parse nonce (first field)
            if ($pos < strlen($rawHex)) {
                $prefix = substr($rawHex, $pos, 2);
                // Single byte nonce (0x01-0x7f)
                if (preg_match('/^[0-7][0-9a-f]$/i', $prefix)) {
                    $out['nonce'] = '0x' . $prefix;
                    $pos += 2;
                } elseif (preg_match('/^8[0-9a-f]$/i', $prefix)) {
                    $len = hexdec(substr($prefix, 1, 1)) * 2;
                    if ($pos + 2 + $len <= strlen($rawHex)) {
                        $out['nonce'] = '0x' . substr($rawHex, $pos + 2, $len);
                        $pos += 2 + $len;
                    }
                }
            }
            
            // Parse gasPrice (second field)
            if ($pos < strlen($rawHex)) {
                $prefix = substr($rawHex, $pos, 2);
                if ($prefix === '84') { // 4-byte gasPrice
                    if ($pos + 10 <= strlen($rawHex)) {
                        $out['gasPrice'] = '0x' . substr($rawHex, $pos + 2, 8);
                        $pos += 10;
                    }
                } elseif (preg_match('/^8[0-9a-f]$/i', $prefix)) {
                    $len = hexdec(substr($prefix, 1, 1)) * 2;
                    if ($pos + 2 + $len <= strlen($rawHex)) {
                        $out['gasPrice'] = '0x' . substr($rawHex, $pos + 2, $len);
                        $pos += 2 + $len;
                    }
                }
            }
            
            // Parse gasLimit (third field)
            if ($pos < strlen($rawHex)) {
                $prefix = substr($rawHex, $pos, 2);
                if ($prefix === '82') { // 2-byte gasLimit
                    if ($pos + 6 <= strlen($rawHex)) {
                        $out['gas'] = '0x' . substr($rawHex, $pos + 2, 4);
                        $pos += 6;
                    }
                } elseif (preg_match('/^8[0-9a-f]$/i', $prefix)) {
                    $len = hexdec(substr($prefix, 1, 1)) * 2;
                    if ($pos + 2 + $len <= strlen($rawHex)) {
                        $out['gas'] = '0x' . substr($rawHex, $pos + 2, $len);
                        $pos += 2 + $len;
                    }
                }
            }
            
            // Parse to address (fourth field)
            if ($pos < strlen($rawHex)) {
                $prefix = substr($rawHex, $pos, 2);
                if ($prefix === '94') { // 20-byte address
                    if ($pos + 42 <= strlen($rawHex)) {
                        $toHex = substr($rawHex, $pos + 2, 40);
                        if (ctype_xdigit($toHex) && $toHex !== '0000000000000000000000000000000000000000') {
                            $out['to'] = '0x' . $toHex;
                        }
                        $pos += 42;
                    }
                }
            }
            
            // Parse value (fifth field)
            if ($pos < strlen($rawHex)) {
                $prefix = substr($rawHex, $pos, 2);
                if ($prefix === '88') { // 8-byte value
                    if ($pos + 18 <= strlen($rawHex)) {
                        $out['value'] = '0x' . substr($rawHex, $pos + 2, 16);
                        $pos += 18;
                    }
                } elseif (preg_match('/^8[0-9a-f]$/i', $prefix)) {
                    $len = hexdec(substr($prefix, 1, 1)) * 2;
                    if ($pos + 2 + $len <= strlen($rawHex)) {
                        $out['value'] = '0x' . substr($rawHex, $pos + 2, $len);
                        $pos += 2 + $len;
                    }
                }
            }
        }
        
        // Use EthereumTx::recoverAddress to get the 'from' address
        try {
            $fullRawHex = '0x' . $rawHex;
            $fromAddress = \Blockchain\Core\Crypto\EthereumTx::recoverAddress($fullRawHex);
            if ($fromAddress) {
                $out['from'] = $fromAddress;
            }
        } catch (\Throwable $e) {
            // Ignore signature recovery errors
        }
        
        // Note: Do NOT fallback to a fake 'from'. If recovery failed, caller must reject the tx.
        
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
    // 0) Prefer explicitly configured address from config table
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'config'");
        if ($stmt && $stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT value FROM config WHERE key_name = 'staking.contract_address' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val && is_string($val)) {
                $v = strtolower(trim($val));
                if (str_starts_with($v, '0x') && strlen($v) === 42) {
                    return $v;
                }
            }
        }
    } catch (\Throwable $e) {
        // ignore
    }
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
    // Guarded by config flag contracts.auto_deploy.enabled to avoid unintended deployments
    try {
        $autoDeploy = false;
        $stmt = $pdo->query("SHOW TABLES LIKE 'config'");
        if ($stmt && $stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT value FROM config WHERE key_name = 'contracts.auto_deploy.enabled' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null) {
                $s = strtolower(trim((string)$val));
                $autoDeploy = in_array($s, ['1','true','yes','on'], true);
            }
        }
        if (!$autoDeploy) {
            // Skip auto-deploy unless explicitly enabled
            return '';
        }
    } catch (\Throwable $e) {
        // If config not available, be conservative and do not auto-deploy
        return '';
    }

    // Proceed with deployment only if enabled
    try {
        // Minimal logger implementation
        $logger = new class implements \Psr\Log\LoggerInterface {
            public function emergency($message, array $context = []): void {}
            public function alert($message, array $context = []): void {}
            public function critical($message, array $context = []): void {}
            public function error($message, array $context = []): void {}
            public function warning($message, array $context = []): void {}
            public function notice($message, array $context = []): void {}
            public function info($message, array $context = []): void {}
            public function debug($message, array $context = []): void {}
            public function log($level, $message, array $context = []): void {}
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

/**
 * Get network nodes from database
 */
function getNetworkNodesFromDatabase(PDO $pdo): array {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                node_id,
                ip_address,
                port,
                public_key,
                version,
                status,
                metadata,
                reputation_score,
                ping_time
            FROM nodes 
            WHERE status = 'active' 
            AND reputation_score > 50
            ORDER BY reputation_score DESC, ping_time ASC
        ");
        $stmt->execute();
        $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $networkNodes = [];
        foreach ($nodes as $node) {
            $metadata = json_decode($node['metadata'], true) ?: [];
            $protocol = $metadata['protocol'] ?? 'https';
            $domain = $metadata['domain'] ?? $node['ip_address'];
            
            // Build API URL
            $baseUrl = $protocol . '://' . $domain;
            if ($node['port'] !== 80 && $node['port'] !== 443) {
                $baseUrl .= ':' . $node['port'];
            }
            
            $networkNodes[$node['node_id']] = [
                'node_id' => $node['node_id'],
                'url' => $baseUrl,
                'ip_address' => $node['ip_address'],
                'port' => $node['port'],
                'protocol' => $protocol,
                'domain' => $domain,
                'version' => $node['version'],
                'reputation_score' => $node['reputation_score'],
                'ping_time' => $node['ping_time'],
                'metadata' => $metadata
            ];
        }
        
        return $networkNodes;
        
    } catch (Exception $e) {
        writeLog("Error getting network nodes from database: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

/**
 * Emit wallet event to peers (HMAC-signed, best-effort)
 */
function emitWalletEvent($walletManager, array $payload): array {
    try {
        $pdo = $walletManager->getDatabase();
        $config = getNetworkConfigFromDatabase($pdo);

        // Build event payload
        $event = [
            'action' => 'receive_wallet_update',
            'update_type' => $payload['update_type'] ?? 'unknown',
            'address' => $payload['address'] ?? '',
            'data' => $payload['data'] ?? [],
            'timestamp' => time()
        ];
        $txh = '';
        if (isset($event['data']['transaction']) && is_array($event['data']['transaction'])) {
            $txh = (string)($event['data']['transaction']['hash'] ?? '');
        }
        $event['event_id'] = hash('sha256', ($event['update_type'] ?? 'u') . '|' . ($event['address'] ?? '') . '|' . $txh . '|' . $event['timestamp']);

        // Resolve nodes from DB
        $nodes = getNetworkNodesFromDatabase($pdo);
        if (empty($nodes)) {
            return ['sent' => 0, 'ok' => 0, 'event_id' => $event['event_id']];
        }

        // Resolve broadcast secret
        $secret = '';
        if (!empty($config['network.broadcast_secret'])) {
            $secret = (string)$config['network.broadcast_secret'];
        } else {
            $secret = $_ENV['BROADCAST_SECRET'] ?? ($_ENV['NETWORK_BROADCAST_SECRET'] ?? (getenv('BROADCAST_SECRET') ?: getenv('NETWORK_BROADCAST_SECRET') ?: ''));
        }

        $timeout = (int)($config['broadcast.timeout'] ?? 8);
        $maxRetries = (int)($config['broadcast.max_retries'] ?? 2);
        if ($maxRetries < 0) { $maxRetries = 0; }
        $sent = 0; $ok = 0; $failures = [];
        $body = json_encode($event);

        foreach ($nodes as $node) {
            $url = rtrim($node['url'] ?? '', '/') . '/api/sync/wallet';
            if ($url === '/api/sync/wallet' || $url === '') { continue; }

            $headers = ['Content-Type: application/json'];
            if ($secret) {
                $sig = hash_hmac('sha256', $body, $secret);
                $headers[] = 'X-Broadcast-Signature: sha256=' . $sig;
            }

            $attempt = 0; $success = false; $lastErr = null; $lastCode = 0;
            do {
                $attempt++;
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout
                ]);
                $resp = curl_exec($ch);
                $lastCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($resp === false) { $lastErr = curl_error($ch); }
                curl_close($ch);

                if ($lastCode === 200) { $success = true; break; }
                // small backoff before retry
                if ($attempt <= $maxRetries) {
                    $sleepMs = (int)min(1500, 200 * pow(2, $attempt-1));
                    usleep($sleepMs * 1000);
                }
            } while ($attempt <= $maxRetries);

            $sent++;
            if ($success) {
                $ok++;
            } else {
                $failItem = [
                    'url' => $url,
                    'code' => $lastCode,
                    'error' => $lastErr
                ];
                try { writeLog('emitWalletEvent: broadcast failed: ' . json_encode($failItem), 'WARNING'); } catch (\Throwable $ignore) {}
                $failures[] = $failItem;
            }
        }

        return ['sent' => $sent, 'ok' => $ok, 'event_id' => $event['event_id'], 'failures' => $failures];
    } catch (Throwable $e) {
        // Best-effort: do not fail the main flow
        try { writeLog('emitWalletEvent error: ' . $e->getMessage(), 'WARNING'); } catch (Throwable $ignore) {}
        return ['sent' => 0, 'ok' => 0];
    }
}

/**
 * Get network configuration from database
 */
function getNetworkConfigFromDatabase(PDO $pdo): array {
    try {
        $stmt = $pdo->prepare("
            SELECT key_name, value, description 
            FROM config 
            WHERE key_name LIKE 'network.%' 
            OR key_name LIKE 'broadcast.%' 
            OR key_name LIKE 'auto_mine.%'
        ");
        $stmt->execute();
        $configRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $config = [];
        foreach ($configRows as $row) {
            $key = $row['key_name'];
            $value = $row['value'];
            
            // Convert string values to appropriate types
            if (is_numeric($value)) {
                if (strpos($value, '.') !== false) {
                    $value = (float)$value;
                } else {
                    $value = (int)$value;
                }
            } elseif ($value === 'true' || $value === 'false') {
                $value = $value === 'true';
            }
            
            $config[$key] = $value;
        }
        
        // Add default values if not in database
        $defaults = [
            'broadcast.enabled' => true,
            'broadcast.timeout' => 10,
            'broadcast.max_retries' => 3,
            'broadcast.min_success_rate' => 50,
            'auto_mine.enabled' => true,
            'auto_mine.min_transactions' => 10,
            'auto_mine.max_transactions_per_block' => 100,
            'auto_mine.max_blocks_per_minute' => 2,
            'network.max_peers' => 50,
            'network.sync_batch_size' => 100
        ];
        
        foreach ($defaults as $key => $defaultValue) {
            if (!isset($config[$key])) {
                $config[$key] = $defaultValue;
            }
        }
        
        return $config;
        
    } catch (Exception $e) {
        writeLog("Error getting network config from database: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

/**
 * Get current node ID
 */
function getCurrentNodeId(PDO $pdo): string {
    try {
        $stmt = $pdo->prepare("SELECT value FROM config WHERE key_name = 'node.id' LIMIT 1");
        $stmt->execute();
        $nodeId = $stmt->fetchColumn();
        
        if ($nodeId) {
            return $nodeId;
        }
        
        // Generate new node ID if not found
        $newNodeId = hash('sha256', uniqid() . time());
        
        $stmt = $pdo->prepare("
            INSERT INTO config (key_name, value, description, is_system) 
            VALUES ('node.id', ?, 'Current node ID', 1)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ");
        $stmt->execute([$newNodeId]);
        
        return $newNodeId;
        
    } catch (Exception $e) {
        writeLog("Error getting current node ID: " . $e->getMessage(), 'ERROR');
        return hash('sha256', 'fallback_node_' . time());
    }
}

/**
 * Update node statistics
 */
function updateNodeStats(PDO $pdo, string $nodeId, bool $success, float $responseTime): void {
    try {
        if ($success) {
            // Successful attempt - increase reputation, update ping_time
            $stmt = $pdo->prepare("
                UPDATE nodes 
                SET 
                    reputation_score = LEAST(reputation_score + 1, 100),
                    ping_time = ?,
                    last_seen = NOW(),
                    updated_at = NOW()
                WHERE node_id = ?
            ");
            $stmt->execute([(int)$responseTime, $nodeId]);
        } else {
            // Failed attempt - decrease reputation
            $stmt = $pdo->prepare("
                UPDATE nodes 
                SET 
                    reputation_score = GREATEST(reputation_score - 5, 0),
                    updated_at = NOW()
                WHERE node_id = ?
            ");
            $stmt->execute([$nodeId]);
        }
    } catch (Exception $e) {
        writeLog("Error updating node stats: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * Compute balance for a wallet address: confirmed_in - (confirmed_out + fee) - pending_out
 */
function computeWalletBalanceDecimal(PDO $pdo, string $address): float {
    try {
        $stmtIn = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE to_address = ? AND status = 'confirmed'");
        $stmtIn->execute([$address]);
        $confirmedIn = (float)($stmtIn->fetchColumn() ?: 0);

        $stmtOut = $pdo->prepare("SELECT COALESCE(SUM(amount + fee),0) FROM transactions WHERE from_address = ? AND status = 'confirmed'");
        $stmtOut->execute([$address]);
        $confirmedOut = (float)($stmtOut->fetchColumn() ?: 0);

        $stmtPend = $pdo->prepare("SELECT COALESCE(SUM(amount + fee),0) FROM mempool WHERE from_address = ? AND status IN ('pending','processing')");
        $stmtPend->execute([$address]);
        $pendingOut = (float)($stmtPend->fetchColumn() ?: 0);

        $balance = $confirmedIn - $confirmedOut - $pendingOut;
        if ($balance < 0) { $balance = 0.0; }
        return $balance;
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * Upsert cached balance into wallets table
 */
function upsertWalletBalance(PDO $pdo, string $address): void {
    try {
        $balance = computeWalletBalanceDecimal($pdo, $address);
        $upd = $pdo->prepare("UPDATE wallets SET balance = ?, updated_at = NOW() WHERE address = ?");
        $upd->execute([$balance, $address]);
        if ($upd->rowCount() === 0) {
            $ins = $pdo->prepare("INSERT INTO wallets (address, public_key, balance) VALUES (?, '', ?)");
            $ins->execute([$address, $balance]);
        }
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Simple fee oracle: suggest base fee and priority fee (in wei) using config and mempool pressure
 */
function getSuggestedFeesWei(PDO $pdo): array {
    try {
        $config = getNetworkConfigFromDatabase($pdo);
    } catch (Throwable $e) { $config = []; }

    $gwei = 1000000000; // 1e9

    // Base fee from config or default 1 gwei
    $baseGwei = isset($config['fee.base_gwei']) && is_numeric($config['fee.base_gwei'])
        ? (float)$config['fee.base_gwei']
        : 1.0;

    // Priority fee from config or default 2 gwei
    $prioGwei = isset($config['fee.priority_gwei']) && is_numeric($config['fee.priority_gwei'])
        ? (float)$config['fee.priority_gwei']
        : 2.0;

    // Bump with mempool pressure
    $mempoolCount = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM mempool");
        $mempoolCount = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}

    // Increase base slightly with log of mempool size
    $baseBump = min(10.0, log(1 + max(0, $mempoolCount)));
    $baseGwei += $baseBump; // up to +10 gwei

    // Increase priority fee for larger queues
    if ($mempoolCount > 50) { $prioGwei += 1.0; }
    if ($mempoolCount > 200) { $prioGwei += 2.0; }

    $baseWei = (int)round($baseGwei * $gwei);
    $prioWei = (int)round($prioGwei * $gwei);
    $gasPriceWei = $baseWei + $prioWei;

    return [
        'base' => $baseWei,
        'priority' => $prioWei,
        'gasPrice' => $gasPriceWei,
    ];
}

/**
 * Broadcast transaction to all network nodes via multi_curl
 */
function broadcastTransactionToNetwork(array $transaction, PDO $pdo): array {
    try {
        // Periodically clean up expired broadcast tracking records (every ~10th call)
        if (mt_rand(1, 10) === 1) {
            cleanupExpiredBroadcastTracking($pdo);
        }
        
        // Get configuration
        $config = getNetworkConfigFromDatabase($pdo);
        $batchSize = (int)($config['network.broadcast_batch_size'] ?? 10);
        $timeout = $config['broadcast.timeout'] ?? 10;
        $maxRetries = $config['broadcast.max_retries'] ?? 3;
        $currentNodeId = getCurrentNodeId($pdo);
        
        // Debug logging
        $debugLog = __DIR__ . '/../logs/debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $debugMsg = "[{$timestamp}] broadcastTransactionToNetwork: Starting broadcast for tx {$transaction['hash']}" . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        // Check if this transaction was recently broadcasted to avoid loops
        $broadcastCacheKey = 'broadcast_' . $transaction['hash'];
        $stmt = $pdo->prepare("SELECT cache_data FROM network_topology_cache WHERE cache_key = ? AND expires_at > NOW()");
        $stmt->execute([$broadcastCacheKey]);
        $existingBroadcast = $stmt->fetchColumn();
        
        if ($existingBroadcast) {
            $broadcastData = json_decode($existingBroadcast, true);
            $recentBroadcasts = $broadcastData['broadcasted_to'] ?? [];
            $debugMsg = "[{$timestamp}] Transaction {$transaction['hash']} already broadcasted to: " . implode(', ', $recentBroadcasts) . PHP_EOL;
            @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        } else {
            $recentBroadcasts = [];
        }
        
        // Get optimal nodes for broadcasting based on network topology
        $walletManager = new \Blockchain\Wallet\WalletManager($pdo);
        // Make sure topology data is fresh (TTL) before selecting nodes
        $topologyStatus = ensureFreshTopology($walletManager);
        if (($topologyStatus['success'] ?? false) === false) {
            writeLog('Topology freshness check failed: ' . ($topologyStatus['reason'] ?? 'unknown'), 'WARNING');
        } else {
            if (($topologyStatus['updated'] ?? false) === true) {
                writeLog('Topology cache refreshed just before broadcast', 'INFO');
            }
        }
        $optimalNodes = selectOptimalBroadcastNodes($walletManager, $transaction['hash'] ?? '', $batchSize);
        
        if (!$optimalNodes['success'] || empty($optimalNodes['selected_nodes'])) {
            writeLog('No optimal nodes found for broadcasting, falling back to traditional method', 'WARNING');
            return broadcastTransactionToNetworkTraditional($transaction, $pdo);
        }
        
        // Filter out nodes we already broadcasted to recently
        $filteredNodes = [];
        foreach ($optimalNodes['selected_nodes'] as $node) {
            if (!in_array($node['id'], $recentBroadcasts)) {
                $filteredNodes[] = $node;
            } else {
                $debugMsg = "[{$timestamp}] Skipping node {$node['id']} - already broadcasted recently" . PHP_EOL;
                @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
            }
        }
        
        if (empty($filteredNodes)) {
            $debugMsg = "[{$timestamp}] All optimal nodes already received broadcast for {$transaction['hash']}" . PHP_EOL;
            @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
            return [
                'success' => true,
                'message' => 'Transaction already broadcasted to all optimal nodes',
                'skipped_duplicates' => count($optimalNodes['selected_nodes'])
            ];
        }
        
        writeLog('Selected ' . count($filteredNodes) . ' optimal nodes for broadcasting (after duplicate filtering)', 'INFO');
        $debugMsg = "[{$timestamp}] Broadcasting to " . count($filteredNodes) . " nodes (filtered from " . count($optimalNodes['selected_nodes']) . ")" . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        // Create MultiCurl for parallel broadcasting
        $multiCurl = new \Blockchain\Core\Network\MultiCurl(50, $timeout, 3);
        
        // Prepare requests for filtered optimal nodes with specific broadcast instructions
        $requests = [];
        $broadcastingTo = [];
        foreach ($filteredNodes as $node) {
            $nodeId = $node['id'];
            $broadcastInstructions = $optimalNodes['broadcast_instructions'][$nodeId] ?? [];
            $broadcastingTo[] = $nodeId;
            
            // Add anti-loop instructions
            $broadcastInstructions['source_node'] = $currentNodeId;
            $broadcastInstructions['broadcast_chain'] = array_merge($recentBroadcasts, [$currentNodeId]);
            $broadcastInstructions['max_hops'] = 2; // Limit broadcast chain length
            
            $requests[$nodeId] = [
                'url' => rtrim($node['url'], '/') . '/wallet/wallet_api.php',
                'method' => 'POST',
                'data' => json_encode([
                    'action' => 'broadcast_transaction',
                    'transaction' => $transaction,
                    'source_node' => $currentNodeId,
                    'timestamp' => time(),
                    'broadcast_instructions' => $broadcastInstructions,
                    'network_topology' => true,
                    'hop_count' => ($transaction['hop_count'] ?? 0) + 1
                ]),
                'headers' => [
                    'Content-Type: application/json',
                    'User-Agent: BlockchainNode/2.0',
                    'X-Node-Id: ' . $currentNodeId
                ],
                'timeout' => $timeout,
                'connect_timeout' => 3
            ];
            
            $debugMsg = "[{$timestamp}] Preparing broadcast to node {$nodeId} at {$node['url']}" . PHP_EOL;
            @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        }
        
        // Cache broadcast information to prevent loops
        $broadcastCacheData = [
            'transaction_hash' => $transaction['hash'],
            'broadcasted_to' => array_merge($recentBroadcasts, $broadcastingTo),
            'source_node' => $currentNodeId,
            'timestamp' => time()
        ];
        
        $stmt = $pdo->prepare("
            INSERT INTO network_topology_cache (cache_key, cache_data, expires_at) 
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 SECOND))
            ON DUPLICATE KEY UPDATE cache_data = VALUES(cache_data), expires_at = VALUES(expires_at)
        ");
        $stmt->execute([$broadcastCacheKey, json_encode($broadcastCacheData)]);
        
        $debugMsg = "[{$timestamp}] Cached broadcast info for {$transaction['hash']}" . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        // Execute parallel broadcasting
        $results = $multiCurl->executeRequests($requests);
        
        // Analyze results
        $successful = 0;
        $failed = 0;
        $nodeResults = [];
        
        foreach ($results as $nodeId => $result) {
            if ($result['success'] && $result['http_code'] === 200) {
                $successful++;
                $debugMsg = "[{$timestamp}] Broadcast to node {$nodeId} SUCCESS" . PHP_EOL;
                @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
                $nodeResults[$nodeId] = [
                    'status' => 'success',
                    'response_time' => $result['time'],
                    'response' => $result['data'] ?? null,
                    'node_info' => $filteredNodes[array_search($nodeId, array_column($filteredNodes, 'id'))] ?? null,
                    'broadcast_instructions' => $optimalNodes['broadcast_instructions'][$nodeId] ?? []
                ];
            } else {
                $failed++;
                $debugMsg = "[{$timestamp}] Broadcast to node {$nodeId} FAILED: HTTP {$result['http_code']}" . PHP_EOL;
                @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
                $nodeResults[$nodeId] = [
                    'status' => 'failed',
                    'error' => $result['error'] ?? 'Unknown error',
                    'http_code' => $result['http_code'] ?? 0
                ];
            }
        }
        
        $debugMsg = "[{$timestamp}] Broadcast completed: {$successful} success, {$failed} failed" . PHP_EOL;
        
        foreach ($results as $nodeId => $result) {
            if ($result['success'] && $result['http_code'] === 200) {
                $successful++;
                $debugMsg = "[{$timestamp}] Broadcast to node {$nodeId} SUCCESS" . PHP_EOL;
                @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
                $nodeResults[$nodeId] = [
                    'status' => 'success',
                    'response_time' => $result['time'],
                    'response' => $result['data'] ?? null,
                    'node_info' => $filteredNodes[array_search($nodeId, array_column($filteredNodes, 'id'))] ?? null,
                    'broadcast_instructions' => $optimalNodes['broadcast_instructions'][$nodeId] ?? []
                ];
                
                // Update node statistics
                updateNodeStats($pdo, $nodeId, true, $result['time']);
                
            } else {
                $failed++;
                $debugMsg = "[{$timestamp}] Broadcast to node {$nodeId} FAILED: HTTP {$result['http_code']}" . PHP_EOL;
                @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
                $nodeResults[$nodeId] = [
                    'status' => 'failed',
                    'error' => $result['error'] ?? 'Unknown error',
                    'http_code' => $result['http_code'] ?? 0
                ];
                
                // Update node statistics (failed attempt)
                updateNodeStats($pdo, $nodeId, false, $result['time']);
            }
        }
        
        // Check minimum success rate
        $minSuccessRate = $config['broadcast.min_success_rate'] ?? 50;
        $successRate = count($optimalNodes['selected_nodes']) > 0 ? round(($successful / count($optimalNodes['selected_nodes'])) * 100, 2) : 0;
        
        // Record broadcast statistics
        updateBroadcastStats($pdo, 'transaction_sent', $currentNodeId);
        if ($successful > 0) {
            updateBroadcastStats($pdo, 'broadcast_successful', $currentNodeId);
        }
        if ($failed > 0) {
            updateBroadcastStats($pdo, 'broadcast_failed', $currentNodeId);
        }
        
        return [
            'success' => $successful > 0 && $successRate >= $minSuccessRate,
            'method' => 'smart_topology',
            'nodes_contacted' => count($optimalNodes['selected_nodes']),
            'successful_broadcasts' => $successful,
            'failed_broadcasts' => $failed,
            'success_rate' => $successRate,
            'min_success_rate' => $minSuccessRate,
            'total_network_coverage' => $optimalNodes['total_coverage'],
            'node_results' => $nodeResults,
            'stats' => $multiCurl->getStats()
        ];
        
    } catch (Exception $e) {
        writeLog("Error broadcasting transaction to network: " . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'nodes_contacted' => 0,
            'successful_broadcasts' => 0
        ];
    }
}

/**
 * Traditional broadcasting method as fallback
 */
function broadcastTransactionToNetworkTraditional(array $transaction, PDO $pdo): array {
    try {
        // Get nodes from database
        $networkNodes = getNetworkNodesFromDatabase($pdo);
        
        if (empty($networkNodes)) {
            return [
                'success' => false,
                'message' => 'No active network nodes found',
                'nodes_contacted' => 0,
                'successful_broadcasts' => 0
            ];
        }
        
        // Get configuration
        $config = getNetworkConfigFromDatabase($pdo);
        $timeout = $config['broadcast.timeout'] ?? 10;
        $maxRetries = $config['broadcast.max_retries'] ?? 3;
        
        // Create MultiCurl for parallel broadcasting
        $multiCurl = new \Blockchain\Core\Network\MultiCurl(50, $timeout, 3);
        
        // Prepare requests for all nodes
        $requests = [];
        foreach ($networkNodes as $nodeId => $nodeInfo) {
            $requests[$nodeId] = [
                'url' => rtrim($nodeInfo['url'], '/') . '/wallet/wallet_api.php',
                'method' => 'POST',
                'data' => json_encode([
                    'action' => 'broadcast_transaction',
                    'transaction' => $transaction,
                    'source_node' => getCurrentNodeId($pdo),
                    'timestamp' => time()
                ]),
                'headers' => [
                    'Content-Type: application/json',
                    'User-Agent: BlockchainNode/2.0',
                    'X-Node-Id: ' . getCurrentNodeId($pdo)
                ],
                'timeout' => $timeout,
                'connect_timeout' => 3
            ];
        }
        
        // Execute parallel broadcasting
        $results = $multiCurl->executeRequests($requests);
        
        // Analyze results
        $successful = 0;
        $failed = 0;
        $nodeResults = [];
        
        foreach ($results as $nodeId => $result) {
            if ($result['success'] && $result['http_code'] === 200) {
                $successful++;
                $nodeResults[$nodeId] = [
                    'status' => 'success',
                    'response_time' => $result['time'],
                    'response' => $result['data'] ?? null,
                    'node_info' => $networkNodes[$nodeId] ?? null
                ];
                
                // Update node statistics
                updateNodeStats($pdo, $nodeId, true, $result['time']);
                
            } else {
                $failed++;
                $nodeResults[$nodeId] = [
                    'status' => 'failed',
                    'error' => $result['error'] ?? 'HTTP ' . $result['http_code'],
                    'response_time' => $result['time'],
                    'node_info' => $networkNodes[$nodeId] ?? null
                ];
                
                // Update node statistics (failed attempt)
                updateNodeStats($pdo, $nodeId, false, $result['time']);
            }
        }
        
        // Check minimum success rate
        $minSuccessRate = $config['broadcast.min_success_rate'] ?? 50;
        $successRate = count($networkNodes) > 0 ? round(($successful / count($networkNodes)) * 100, 2) : 0;
        
        return [
            'success' => $successful > 0 && $successRate >= $minSuccessRate,
            'method' => 'traditional',
            'nodes_contacted' => count($networkNodes),
            'successful_broadcasts' => $successful,
            'failed_broadcasts' => $failed,
            'success_rate' => $successRate,
            'min_success_rate' => $minSuccessRate,
            'node_results' => $nodeResults,
            'stats' => $multiCurl->getStats()
        ];
        
    } catch (Exception $e) {
        writeLog("Error in traditional broadcasting: " . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'method' => 'traditional'
        ];
    }
}

/**
 * Receive transaction broadcasted from another node
 */
function receiveBroadcastedTransaction($walletManager, array $transaction, string $sourceNode, int $timestamp): array {
    try {
        // CRITICAL FIX: Don't use broadcast hash! Create Transaction object to get REAL hash
        
        // Normalize incoming structure first 
        $from = $transaction['from_address'] ?? $transaction['from'] ?? '';
        $to = $transaction['to_address'] ?? $transaction['to'] ?? '';
        $amount = (float)($transaction['amount'] ?? 0);
        $fee = (float)($transaction['fee'] ?? 0);
        $nonce = (int)($transaction['nonce'] ?? 0);
        $gasLimit = (int)($transaction['gas_limit'] ?? ($transaction['gas'] ?? 21000));
        $gasPrice = (float)($transaction['gas_price'] ?? ($transaction['gasPrice'] ?? 0));
        $dataHex = $transaction['data'] ?? ($transaction['input'] ?? null);
        if ($dataHex === '') { $dataHex = null; }
        
        // Create Transaction object to get the REAL transaction hash
        $tx = new \Blockchain\Core\Transaction\Transaction(
            $from,
            $to,
            $amount,
            $fee,
            $nonce,
            is_string($dataHex) ? $dataHex : null,
            $gasLimit,
            $gasPrice
        );
        
    // Base hash from Transaction object (may be overridden for local source below)
    $txHash = $tx->getHash();
        
        $hopCount = (int)($transaction['hop_count'] ?? 0);
        $maxHops = 3; // Maximum hops to prevent infinite loops
        $pdo = $walletManager->getDatabase();
        $currentNodeId = getCurrentNodeId($pdo);
        
        // ALWAYS use provided hash if valid (CRITICAL: preserves eth_sendRawTransaction hashes)
        $isLocalSource = ($sourceNode === 'self');
        if (!empty($transaction['hash']) && is_string($transaction['hash'])) {
            $ext = strtolower(trim($transaction['hash']));
            if (!str_starts_with($ext, '0x')) { $ext = '0x' . $ext; }
            if (preg_match('/^0x[a-f0-9]{64}$/', $ext)) {
                writeLog("HASH PRESERVATION: Using provided hash {$ext} (source: {$sourceNode}) instead of computed hash", 'INFO');
                if (method_exists($tx, 'forceHash')) { $tx->forceHash($ext); }
                $txHash = $ext;
                
                // Log the hash difference for debugging
                $computedHash = $tx->getHash();
                if ($computedHash !== $ext) {
                    writeLog("HASH MISMATCH DETECTED: provided={$ext}, computed={$computedHash} - using provided", 'WARNING');
                } else {
                    writeLog("HASH MATCH: provided and computed hashes are identical", 'DEBUG');
                }
            } else {
                writeLog("Invalid hash format provided: {$ext}, using computed hash", 'WARNING');
            }
        }

        // Debug logging
        $debugLog = __DIR__ . '/../logs/debug.log';
        $logTimestamp = date('Y-m-d H:i:s');
        $debugMsg = "[{$logTimestamp}] receiveBroadcastedTransaction: USING_hash={$txHash}, provided_hash=" . ($transaction['hash'] ?? 'none') . ", source={$sourceNode}, hops={$hopCount}" . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        writeLog('receiveBroadcastedTransaction: Using hash ' . $txHash . ' (provided hash: ' . ($transaction['hash'] ?? 'none') . ')', 'INFO');
        writeLog('Transaction data: ' . json_encode($transaction), 'DEBUG');
        
        // Check broadcast tracking table to prevent duplicate processing
        try {
            $stmt = $pdo->prepare("SELECT source_node_id, hop_count, created_at FROM broadcast_tracking WHERE transaction_hash = ? AND source_node_id = ? LIMIT 1");
            $stmt->execute([$txHash, $sourceNode]);
            $existingBroadcast = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingBroadcast) {
                $debugMsg = "[{$logTimestamp}] Transaction {$txHash} already tracked from source {$sourceNode}" . PHP_EOL;
                @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
                
                // Update broadcast stats
                updateBroadcastStats($pdo, 'duplicate_prevented', $currentNodeId);
                
                return [
                    'success' => true,
                    'message' => 'Transaction already processed from this source',
                    'status' => 'duplicate_source',
                    'previous_hop_count' => $existingBroadcast['hop_count']
                ];
            }
        } catch (Exception $e) {
            writeLog('Error checking broadcast tracking: ' . $e->getMessage(), 'ERROR');
        }
        
        // Check hop count to prevent loops
        if ($hopCount >= $maxHops) {
            $debugMsg = "[{$logTimestamp}] Transaction {$txHash} exceeded max hops ({$hopCount} >= {$maxHops})" . PHP_EOL;
            @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
            writeLog('Transaction exceeded maximum hop count: ' . $hopCount, 'WARNING');
            
            // Update broadcast stats
            updateBroadcastStats($pdo, 'hop_limit_exceeded', $currentNodeId);
            
            return [
                'success' => false,
                'error' => 'Maximum hop count exceeded',
                'hop_count' => $hopCount,
                'max_hops' => $maxHops
            ];
        }
        
        // Check if transaction is not too old (e.g., not older than 5 minutes)
        if (time() - $timestamp > 300) {
            writeLog('Transaction too old: ' . (time() - $timestamp) . ' seconds', 'WARNING');
            return [
                'success' => false,
                'error' => 'Transaction too old',
                'age_seconds' => time() - $timestamp
            ];
        }
        
        // Check broadcast chain to prevent loops
        $broadcastInstructions = $transaction['broadcast_instructions'] ?? [];
        $broadcastChain = $broadcastInstructions['broadcast_chain'] ?? [];
        $currentNodeId = getCurrentNodeId($walletManager->getDatabase());
        
        if (in_array($currentNodeId, $broadcastChain)) {
            $debugMsg = "[{$logTimestamp}] Transaction {$txHash} already seen by this node (in broadcast chain)" . PHP_EOL;
            @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
            return [
                'success' => true,
                'message' => 'Transaction already processed by this node',
                'status' => 'loop_detected'
            ];
        }
        
        // Check if transaction doesn't already exist in our database
        $existingTx = $walletManager->getTransactionByHash($txHash);
        if ($existingTx) {
            $debugMsg = "[{$logTimestamp}] Transaction {$txHash} already exists in database" . PHP_EOL;
            @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
            writeLog('Transaction already exists in database: ' . $txHash, 'INFO');
            return [
                'success' => true,
                'message' => 'Transaction already exists',
                'status' => 'duplicate'
            ];
        }
        
    // Check mempool for duplicates (handle 0x and non-0x variants)
    $pdo = $walletManager->getDatabase();
    $h = strtolower(trim((string)$txHash));
    $h0 = str_starts_with($h,'0x') ? $h : ('0x'.$h);
    $h1 = str_starts_with($h,'0x') ? substr($h,2) : $h;
    $stmt = $pdo->prepare("SELECT 1 FROM mempool WHERE tx_hash = ? OR tx_hash = ? LIMIT 1");
    $stmt->execute([$h0, $h1]);
        if ($stmt->fetchColumn()) {
            $debugMsg = "[{$logTimestamp}] Transaction {$txHash} already in mempool" . PHP_EOL;
            @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
            writeLog('Transaction already in mempool: ' . $txHash, 'INFO');
            return [
                'success' => true,
                'message' => 'Transaction already in mempool',
                'status' => 'duplicate'
            ];
        }
        
        // Re-broadcast to other nodes (with hop count increment)
        if ($hopCount < $maxHops - 1) {
            try {
                $newHopCount = $hopCount + 1;
                $debugMsg = "[{$logTimestamp}] Re-broadcasting {$txHash} with hop_count={$newHopCount}" . PHP_EOL;
                @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
                
                // Update broadcast chain
                $newBroadcastChain = $broadcastChain;
                $newBroadcastChain[] = $currentNodeId;
                
                // Prepare transaction for re-broadcast
                $rebroadcastTx = $transaction;
                $rebroadcastTx['hop_count'] = $newHopCount;
                $rebroadcastTx['broadcast_instructions'] = [
                    'broadcast_chain' => $newBroadcastChain,
                    'max_hops' => $maxHops,
                    'origin_timestamp' => $timestamp
                ];
                
            // Get fresh topology and re-broadcast (fix wrong arguments)
            ensureFreshTopology($walletManager);
            $result = broadcastTransactionToNetwork($rebroadcastTx, $pdo);
                
                $debugMsg = "[{$logTimestamp}] Re-broadcast result for {$txHash}: " . json_encode($result) . PHP_EOL;
                @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
                
            } catch (Exception $e) {
                $debugMsg = "[{$logTimestamp}] Re-broadcast failed for {$txHash}: " . $e->getMessage() . PHP_EOL;
                @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
                writeLog('Re-broadcast failed: ' . $e->getMessage(), 'ERROR');
            }
        } else {
            $debugMsg = "[{$logTimestamp}] Skip re-broadcast for {$txHash} - max hops reached" . PHP_EOL;
            @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        }
        
        // Check if this is a smart topology broadcast with instructions
        $broadcastInstructions = $transaction['broadcast_instructions'] ?? null;
        $isNetworkTopology = $transaction['network_topology'] ?? false;
        
        if ($isNetworkTopology && $broadcastInstructions) {
            writeLog('Received smart topology broadcast with instructions for: ' . $transaction['hash'], 'INFO');
            
            // Process broadcast instructions to forward to other nodes
            $forwardResult = processBroadcastInstructions($pdo, $transaction, $broadcastInstructions);
            if ($forwardResult['success']) {
                writeLog('Successfully forwarded transaction to ' . $forwardResult['nodes_contacted'] . ' additional nodes', 'INFO');
            }
        }
        
        // Normalize incoming structure (support both from/to and from_address/to_address)
        // NOTE: We already normalized these values above and created Transaction object
        // Ensure minimal fee to satisfy mempool policy
        if ($fee < 0.001) { 
            $fee = 0.001; 
            // Need to recreate Transaction with updated fee
            $tx = new \Blockchain\Core\Transaction\Transaction(
                $from,
                $to,
                $amount,
                $fee,
                $nonce,
                is_string($dataHex) ? $dataHex : null,
                $gasLimit,
                $gasPrice
            );
            // CRITICAL: Always preserve provided hash after fee recalculation (maintains eth_sendRawTransaction consistency)
            if (!empty($transaction['hash']) && preg_match('/^0x[a-f0-9]{64}$/', strtolower($transaction['hash'])) && method_exists($tx, 'forceHash')) {
                $forced = strtolower($transaction['hash']);
                $tx->forceHash($forced);
                $txHash = str_starts_with($forced, '0x') ? $forced : ('0x' . $forced);
                writeLog("HASH PRESERVATION (post-fee): Re-applied provided hash {$txHash}", 'INFO');
                
                // Debug: check if hash would have changed
                $wouldBeHash = $tx->getHash();
                if ($wouldBeHash !== $txHash) {
                    writeLog("HASH PRESERVATION CRITICAL: Without forcing, hash would be {$wouldBeHash} instead of {$txHash}", 'WARNING');
                }
            } else {
                $txHash = $tx->getHash();
                writeLog("Using computed hash after fee adjustment: {$txHash}", 'INFO');
            }
        }

        // Transaction object already created above - use existing $tx
        writeLog('Using Transaction object with hash: ' . $tx->getHash() . ', from=' . $from . ', to=' . $to . ', amount=' . $amount . ', nonce=' . $nonce, 'DEBUG');
        
        // CRITICAL FIX: DO NOT force hash from broadcast! Let Transaction compute its own hash
        // The Transaction constructor will compute the correct hash from the actual transaction data
        // $tx->forceHash($transaction['hash'] ?? ''); // REMOVED - this was corrupting with node hash!
        
        // Mark as externally validated raw/broadcasted tx to bypass local hash/signature checks
        $tx->setSignature('external_raw');
        
        writeLog('Attempting to add transaction to mempool via MempoolManager', 'INFO');
        
        // Debug: log transaction object details with REAL hash
        $debugLog = __DIR__ . '/../logs/debug.log';
        $timestamp = date('Y-m-d H:i:s');
    $debugMsg = "[{$timestamp}] Transaction object details: REAL_hash=" . $tx->getHash() . ", chosen_hash={$txHash}, from=" . $tx->getFrom() . ", to=" . $tx->getTo() . ", amount=" . $tx->getAmount() . ", nonce=" . $tx->getNonce() . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        // MASSIVE DEBUG - ensure this runs
        writeLog("🔥 ABOUT TO START RBF CHECK FOR TX {$txHash} 🔥", 'INFO');
        @file_put_contents($debugLog, "[{$timestamp}] 🔥 ABOUT TO START RBF CHECK FOR TX {$txHash} 🔥" . PHP_EOL, FILE_APPEND);
        
        // Check for transaction replacement (RBF - Replace By Fee) BEFORE trying to add
        writeLog("🔥 Starting RBF check for tx {$txHash} with nonce {$tx->getNonce()}", 'INFO');
        @file_put_contents($debugLog, "[{$timestamp}] 🔥 Starting RBF check for tx {$txHash} with nonce {$tx->getNonce()}" . PHP_EOL, FILE_APPEND);
        
        $replacementResult = checkAndHandleTransactionReplacement($pdo, $tx, $txHash);
        
        writeLog("🔥 RBF check completed, action: {$replacementResult['action']}", 'INFO');
        @file_put_contents($debugLog, "[{$timestamp}] 🔥 RBF check completed, action: {$replacementResult['action']}" . PHP_EOL, FILE_APPEND);
        
        if ($replacementResult['action'] === 'replaced') {
            writeLog("RBF SUCCESS: old tx {$replacementResult['old_hash']} replaced by new tx {$txHash} (higher gas price)", 'INFO');
            $added = true; // Replacement counts as successful addition
        } elseif ($replacementResult['action'] === 'rejected') {
            writeLog("RBF REJECTED: new tx {$txHash} rejected (reason: {$replacementResult['reason']})", 'INFO');
            $added = false;
        } elseif ($replacementResult['action'] === 'duplicate') {
            writeLog("RBF DUPLICATE: identical transaction already exists {$replacementResult['existing_hash']}", 'DEBUG');
            $added = true; // Treat duplicate as success (idempotent)
        } else {
            // No replacement needed, add normally via MempoolManager
            $mempool = new \Blockchain\Core\Transaction\MempoolManager($pdo, ['min_fee' => 0.001]);
            $added = $mempool->addTransaction($tx);
        }
        
        writeLog('MempoolManager transaction processing result: ' . ($added ? 'true' : 'false') . " (action: {$replacementResult['action']})", 'INFO');
        
        // Debug: log mempool result
        $debugMsg = "[{$timestamp}] MempoolManager.addTransaction result: " . ($added ? 'true' : 'false') . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        if ($added) {
            // Record broadcast tracking
            $broadcastPath = implode('->', $broadcastChain ?: []);
            recordBroadcastTracking($pdo, $txHash, $sourceNode, $hopCount, $broadcastPath);
            
            // Update broadcast stats
            updateBroadcastStats($pdo, 'transaction_received', $currentNodeId);
            
            // Update cached balances for involved addresses
            try { upsertWalletBalance($pdo, strtolower($from)); } catch (Throwable $e) {}
            try { if (!empty($to)) upsertWalletBalance($pdo, strtolower($to)); } catch (Throwable $e) {}
            // Ensure a raw_mempool placeholder exists so pending state is visible via RPC
            try {
                $dir = dirname(__DIR__) . '/storage/raw_mempool';
                if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $path = $dir . '/' . str_replace('0x','', strtolower($txHash)) . '.json';
                if (!is_file($path)) {
                    $parsed = [
                        'from' => strtolower($from),
                        'to' => strtolower($to),
                        'value' => isset($amount) ? ('0x' . dechex((int)round($amount * pow(10, (int)($config['decimals'] ?? 8))))) : '0x0',
                        'gas' => '0x' . dechex((int)$gasLimit),
                        'gasPrice' => '0x' . dechex((int)$gasPrice),
                        'nonce' => '0x' . dechex((int)$nonce),
                        'input' => is_string($dataHex) ? $dataHex : '0x'
                    ];
                    @file_put_contents($path, json_encode([
            'hash' => $txHash,
                        'raw' => null,
                        'parsed' => $parsed,
                        'received_at' => time()
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
            } catch (Throwable $e) { /* ignore */ }
            return [
                'success' => true,
                'message' => 'Transaction added to mempool',
                'status' => 'added',
        'transaction_hash' => $txHash
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Failed to add transaction to mempool',
                'status' => 'failed'
            ];
        }
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'status' => 'error'
        ];
    }
}

/**
 * Automatically mine blocks if there are enough transactions in mempool
 */
function autoMineBlocks($walletManager, array $config): array {
    try {
        $pdo = $walletManager->getDatabase();
        
        writeLog("autoMineBlocks: Starting auto-mine check", 'INFO');
        
        // Check number of transactions in mempool
        $stmt = $pdo->query("SELECT COUNT(*) FROM mempool WHERE status='pending'");
        $mempoolCount = $stmt->fetchColumn();
        
        writeLog("autoMineBlocks: Found {$mempoolCount} pending transactions in mempool", 'INFO');
        
        // If transactions are less than threshold, don't mine
        $minTransactions = $config['auto_mine.min_transactions'] ?? 10;
        if ($mempoolCount < $minTransactions) {
            writeLog("autoMineBlocks: Not enough transactions ({$mempoolCount} < {$minTransactions})", 'INFO');
            return [
                'mined' => false,
                'reason' => 'Not enough transactions',
                'mempool_count' => $mempoolCount,
                'min_required' => $minTransactions
            ];
        }
        
        // Get transactions for block
        $maxTransactions = $config['auto_mine.max_transactions_per_block'] ?? 100;
        $mempool = new \Blockchain\Core\Transaction\MempoolManager($pdo, ['min_fee' => 0.001]);
        $transactions = $mempool->getTransactionsForBlock($maxTransactions);
        
        writeLog("autoMineBlocks: Retrieved " . count($transactions) . " transactions for mining", 'INFO');
        
        if (empty($transactions)) {
            writeLog("autoMineBlocks: No valid transactions to mine", 'WARNING');
            return [
                'mined' => false,
                'reason' => 'No valid transactions to mine'
            ];
        }
        
        writeLog("autoMineBlocks: Starting block mining with " . count($transactions) . " transactions", 'INFO');
        
        // Mine block
        $blockResult = mineBlock($transactions, $pdo, $config);
        
        writeLog("autoMineBlocks: Block mined successfully - height: " . $blockResult['height'] . ", hash: " . $blockResult['hash'], 'INFO');
        // Update cached balances for affected addresses after mining
        try {
            $addresses = [];
            foreach ($transactions as $t) {
                $aFrom = method_exists($t, 'getFrom') ? strtolower($t->getFrom()) : null;
                $aTo = method_exists($t, 'getTo') ? strtolower($t->getTo()) : null;
                if ($aFrom) { $addresses[$aFrom] = true; }
                if ($aTo) { $addresses[$aTo] = true; }
            }
            foreach (array_keys($addresses) as $addr) {
                upsertWalletBalance($pdo, $addr);
            }
        } catch (Throwable $e) { /* ignore */ }
        
        return [
            'mined' => true,
            'block_height' => $blockResult['height'],
            'block_hash' => $blockResult['hash'],
            'transactions_processed' => count($transactions),
            'mempool_remaining' => $mempoolCount - count($transactions)
        ];
        
    } catch (Exception $e) {
        writeLog("autoMineBlocks: Error occurred - " . $e->getMessage(), 'ERROR');
        return [
            'mined' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Mine a single block with given transactions
 */
function mineBlock(array $transactions, PDO $pdo, array $config): array {
    try {
        // Determine next block height
        $prevHash = 'GENESIS';
        $height = 0;
        $stmt = $pdo->query("SELECT hash, height FROM blocks ORDER BY height DESC LIMIT 1");
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $prevHash = $row['hash'];
            $height = (int)$row['height'] + 1;
        }
        
        writeLog("Mining block height $height with " . count($transactions) . " transactions", 'INFO');
        
        // Get original hashes for cleanup
        $maxCount = count($transactions);
        $stmt = $pdo->prepare("
            SELECT tx_hash FROM mempool 
            ORDER BY priority_score DESC, created_at ASC 
            LIMIT :max_count
        ");
        $stmt->bindParam(':max_count', $maxCount, PDO::PARAM_INT);
        $stmt->execute();
        $originalHashes = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $originalHashes[] = $row['tx_hash'];
        }
        
        // Initialize mining components
        require_once dirname(__DIR__) . '/core/Consensus/ValidatorManager.php';
        require_once dirname(__DIR__) . '/core/Consensus/ProofOfStake.php';
        require_once dirname(__DIR__) . '/core/Blockchain/Block.php';
        require_once dirname(__DIR__) . '/core/Storage/BlockStorage.php';
        
        $validatorManager = new \Blockchain\Core\Consensus\ValidatorManager($pdo, $config);
        
        require_once dirname(__DIR__) . '/core/Logging/NullLogger.php';
        $logger = new \Blockchain\Core\Logging\NullLogger();
        
        $pos = new \Blockchain\Core\Consensus\ProofOfStake($logger);
        $pos->setValidatorManager($validatorManager);
        
        // Create block with PoS-specific metadata
        $block = new \Blockchain\Core\Blockchain\Block($height, $transactions, $prevHash, [], []);
        
        // Add PoS-specific metadata
        $block->addMetadata('consensus', 'pos');
        $block->addMetadata('staking_required', true);
        $block->addMetadata('validator_block', true);
        $block->addMetadata('difficulty', '0'); // PoS has no difficulty
        
        // Sign block
        try { 
            $pos->signBlock($block); 
        } catch (Throwable $e) { 
            writeLog('Block signing failed: ' . $e->getMessage(), 'ERROR'); 
        }
        
        // Save block
        $storage = new \Blockchain\Core\Storage\BlockStorage(dirname(__DIR__) . '/storage/blockchain_runtime.json', $pdo, $validatorManager);
        $ok = $storage->saveBlock($block);
        
        if (!$ok) {
            throw new Exception('Failed to save block');
        }
        
        // Clean up mempool
        $removed = 0;
        $del = $pdo->prepare('DELETE FROM mempool WHERE tx_hash = ? OR tx_hash = ?');
        foreach ($originalHashes as $originalHash) {
            $dh = strtolower(trim((string)$originalHash));
            $dh0 = str_starts_with($dh,'0x') ? $dh : ('0x'.$dh);
            $dh1 = str_starts_with($dh,'0x') ? substr($dh,2) : $dh;
            $del->execute([$dh0, $dh1]);
            $removed += $del->rowCount();
        }
        
        return [
            'height' => $height,
            'hash' => $block->getHash(),
            'transactions_count' => count($transactions),
            'mempool_cleaned' => $removed
        ];
        
    } catch (Exception $e) {
        writeLog("Error mining block: " . $e->getMessage(), 'ERROR');
        throw $e;
    }
}

/**
 * Auto-sync blockchain with network when needed
 * Event-driven synchronization to replace cron-based sync
 */
function autoSyncBlockchain($walletManager, array $config): array {
    try {
        $pdo = $walletManager->getDatabase();
        
        writeLog("autoSyncBlockchain: Starting sync check", 'INFO');
        
        // Check if auto-sync is enabled
        $autoSyncEnabled = $config['auto_sync.enabled'] ?? true;
        if (!$autoSyncEnabled) {
            writeLog("autoSyncBlockchain: Auto-sync disabled", 'INFO');
            return [
                'synced' => false,
                'reason' => 'Auto-sync disabled'
            ];
        }
        
        // Get current local blockchain height
        $stmt = $pdo->query("SELECT MAX(height) FROM blocks");
        $localHeight = $stmt->fetchColumn() ?: 0;
        
        writeLog("autoSyncBlockchain: Local blockchain height: {$localHeight}", 'INFO');
        
        // Get active nodes for comparison
        $stmt = $pdo->prepare("SELECT metadata FROM nodes WHERE status = 'active' LIMIT 3");
        $stmt->execute();
        $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($nodes)) {
            writeLog("autoSyncBlockchain: No active nodes found", 'INFO');
            return [
                'synced' => false,
                'reason' => 'No active nodes',
                'local_height' => $localHeight
            ];
        }
        
        // Check if sync is needed by comparing heights with other nodes
        $maxRemoteHeight = $localHeight;
        $heightDifferences = [];
        
        foreach ($nodes as $node) {
            $metadata = json_decode($node['metadata'], true) ?: [];
            $protocol = $metadata['protocol'] ?? 'https';
            $domain = $metadata['domain'] ?? null;
            
            if (!$domain) continue;
            
            $nodeUrl = $protocol . '://' . $domain;
            
            try {
                // Quick height check via API
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 5,
                        'ignore_errors' => true
                    ],
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false
                    ]
                ]);
                
                $response = @file_get_contents($nodeUrl . '/api/?action=get_blockchain_stats', false, $context);
                if ($response) {
                    $data = json_decode($response, true);
                    if (isset($data['stats']['height'])) {
                        $remoteHeight = (int)$data['stats']['height'];
                        $heightDifferences[$domain] = $remoteHeight;
                        $maxRemoteHeight = max($maxRemoteHeight, $remoteHeight);
                        
                        writeLog("autoSyncBlockchain: Node {$domain} height: {$remoteHeight}", 'INFO');
                    }
                }
            } catch (Exception $e) {
                writeLog("autoSyncBlockchain: Failed to check height for {$domain}: " . $e->getMessage(), 'WARNING');
            }
        }
        
        // Determine if sync is needed
        $heightThreshold = (int)($config['auto_sync.height_threshold'] ?? 5);
        $heightDiff = $maxRemoteHeight - $localHeight;
        
        writeLog("autoSyncBlockchain: Max remote height: {$maxRemoteHeight}, difference: {$heightDiff}", 'INFO');
        
        if ($heightDiff < $heightThreshold) {
            writeLog("autoSyncBlockchain: Sync not needed (diff: {$heightDiff} < threshold: {$heightThreshold})", 'INFO');
            return [
                'synced' => false,
                'reason' => 'Heights in sync',
                'local_height' => $localHeight,
                'max_remote_height' => $maxRemoteHeight,
                'height_difference' => $heightDiff,
                'threshold' => $heightThreshold
            ];
        }
        
        // Trigger sync using NetworkSyncManager
        writeLog("autoSyncBlockchain: Starting blockchain sync (height diff: {$heightDiff})", 'INFO');
        
        // Include NetworkSyncManager
        $baseDir = dirname(__DIR__);
        require_once $baseDir . '/network_sync.php';
        
        $syncManager = new NetworkSyncManager(false);
        $syncResult = $syncManager->syncAll();
        
        writeLog("autoSyncBlockchain: Sync completed with result: " . json_encode($syncResult), 'INFO');
        
        return [
            'synced' => true,
            'local_height_before' => $localHeight,
            'max_remote_height' => $maxRemoteHeight,
            'height_difference' => $heightDiff,
            'sync_result' => $syncResult,
            'nodes_checked' => array_keys($heightDifferences)
        ];
        
    } catch (Exception $e) {
        writeLog("autoSyncBlockchain error: " . $e->getMessage(), 'ERROR');
        return [
            'synced' => false,
            'reason' => 'Sync failed',
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Monitor blockchain height and emit alerts when desync detected
 */
function monitorBlockchainHeight($walletManager, array $config): array {
    try {
        $pdo = $walletManager->getDatabase();
        
        writeLog("monitorBlockchainHeight: Starting height monitoring", 'INFO');
        
        // Get current local height
        $stmt = $pdo->query("SELECT MAX(height) FROM blocks");
        $localHeight = $stmt->fetchColumn() ?: 0;
        
        // Get active nodes
        $stmt = $pdo->prepare("SELECT node_id, metadata FROM nodes WHERE status = 'active'");
        $stmt->execute();
        $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $nodeHeights = [];
        $totalNodes = 0;
        $responsiveNodes = 0;
        
        foreach ($nodes as $node) {
            $totalNodes++;
            $metadata = json_decode($node['metadata'], true) ?: [];
            $protocol = $metadata['protocol'] ?? 'https';
            $domain = $metadata['domain'] ?? null;
            
            if (!$domain) continue;
            
            $nodeUrl = $protocol . '://' . $domain;
            
            try {
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 3,
                        'ignore_errors' => true
                    ],
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false
                    ]
                ]);
                
                $response = @file_get_contents($nodeUrl . '/api/?action=get_blockchain_stats', false, $context);
                if ($response) {
                    $data = json_decode($response, true);
                    if (isset($data['stats']['height'])) {
                        $remoteHeight = (int)$data['stats']['height'];
                        $nodeHeights[$domain] = $remoteHeight;
                        $responsiveNodes++;
                        
                        writeLog("monitorBlockchainHeight: Node {$domain} height: {$remoteHeight}", 'DEBUG');
                    }
                }
            } catch (Exception $e) {
                writeLog("monitorBlockchainHeight: Failed to check {$domain}: " . $e->getMessage(), 'WARNING');
            }
        }
        
        if (empty($nodeHeights)) {
            writeLog("monitorBlockchainHeight: No responsive nodes found", 'WARNING');
            return [
                'status' => 'warning',
                'reason' => 'No responsive nodes',
                'local_height' => $localHeight,
                'responsive_nodes' => 0,
                'total_nodes' => $totalNodes
            ];
        }
        
        // Calculate statistics
        $heights = array_values($nodeHeights);
        $minHeight = min($heights);
        $maxHeight = max($heights);
        $avgHeight = round(array_sum($heights) / count($heights), 2);
        
        // Check for desync
        $desyncThreshold = (int)($config['monitor.desync_threshold'] ?? 10);
        $heightSpread = $maxHeight - $minHeight;
        $localDesync = max(abs($localHeight - $minHeight), abs($localHeight - $maxHeight));
        
        $status = 'healthy';
        $alerts = [];
        
        if ($heightSpread > $desyncThreshold) {
            $status = 'desync_detected';
            $alerts[] = "Network desync detected: height spread {$heightSpread} > threshold {$desyncThreshold}";
        }
        
        if ($localDesync > $desyncThreshold) {
            $status = 'local_desync';
            $alerts[] = "Local node desync: {$localDesync} blocks difference";
        }
        
        if ($responsiveNodes < $totalNodes * 0.5) {
            $status = 'network_issues';
            $alerts[] = "Only {$responsiveNodes}/{$totalNodes} nodes responsive";
        }
        
        // Log alerts
        foreach ($alerts as $alert) {
            writeLog("monitorBlockchainHeight ALERT: {$alert}", 'WARNING');
        }
        
        return [
            'status' => $status,
            'local_height' => $localHeight,
            'network_heights' => $nodeHeights,
            'statistics' => [
                'min_height' => $minHeight,
                'max_height' => $maxHeight,
                'avg_height' => $avgHeight,
                'height_spread' => $heightSpread
            ],
            'responsive_nodes' => $responsiveNodes,
            'total_nodes' => $totalNodes,
            'alerts' => $alerts,
            'desync_threshold' => $desyncThreshold,
            'local_desync' => $localDesync
        ];
        
    } catch (Exception $e) {
        writeLog("monitorBlockchainHeight error: " . $e->getMessage(), 'ERROR');
        return [
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get network topology information
 */
function getNetworkTopology($walletManager): array {
    try {
        $pdo = $walletManager->getDatabase();
        
        // Get current node ID
        $currentNodeId = getCurrentNodeId($pdo);
        
        // Get all active nodes from existing nodes table
        $stmt = $pdo->prepare("SELECT node_id as id, ip_address, port, status, last_seen, metadata FROM nodes WHERE status = 'active'");
        $stmt->execute();
        $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process nodes to build URLs from metadata
        foreach ($nodes as &$node) {
            $metadata = json_decode($node['metadata'], true) ?: [];
            $protocol = $metadata['protocol'] ?? 'https';
            $domain = $metadata['domain'] ?? $node['ip_address'];
            
            $node['url'] = $protocol . '://' . $domain;
            if ($node['port'] !== 80 && $node['port'] !== 443) {
                $node['url'] .= ':' . $node['port'];
            }
        }
        
        // Get topology connections
        $stmt = $pdo->prepare("
            SELECT source_node_id, target_node_id, connection_strength 
            FROM network_topology 
            WHERE ttl_expires_at IS NULL OR ttl_expires_at > NOW()
        ");
        $stmt->execute();
        $connections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'current_node_id' => $currentNodeId,
            'nodes' => $nodes,
            'connections' => $connections,
            'timestamp' => time()
        ];
        
    } catch (Exception $e) {
        writeLog('getNetworkTopology error: ' . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Update network topology by querying other nodes
 */
function updateNetworkTopology($walletManager): array {
    try {
        // Debug logging start
        $debugLog = __DIR__ . '/../logs/debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $debugMsg = "[{$timestamp}] === TOPOLOGY UPDATE STARTED ===" . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        $pdo = $walletManager->getDatabase();
        
        // Check if topology update is needed
        $config = getNetworkConfigFromDatabase($pdo);
        $updateInterval = (int)($config['network.topology_update_interval'] ?? 60);
        $ttl = (int)($config['network.topology_ttl'] ?? 300);
        
        $debugMsg = "[{$timestamp}] Topology config: update_interval={$updateInterval}s, ttl={$ttl}s" . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        // Check last update time
        $stmt = $pdo->prepare("SELECT MAX(created_at) as last_update FROM network_topology_cache WHERE cache_key = 'topology_update'");
        $stmt->execute();
        $lastUpdate = $stmt->fetchColumn();
        
        $debugMsg = "[{$timestamp}] Last topology update: {$lastUpdate}" . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        if ($lastUpdate && (time() - strtotime($lastUpdate)) < $updateInterval) {
            $debugMsg = "[{$timestamp}] Topology update not needed yet (last update: {$lastUpdate})" . PHP_EOL;
            @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
            return [
                'success' => true,
                'message' => 'Topology update not needed yet',
                'last_update' => $lastUpdate
            ];
        }
        
        $debugMsg = "[{$timestamp}] Topology update needed, proceeding..." . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        // Get active nodes from existing nodes table
        $stmt = $pdo->prepare("SELECT node_id as id, ip_address, port, metadata FROM nodes WHERE status = 'active' AND node_id != ?");
        $currentNodeId = getCurrentNodeId($pdo);
        $stmt->execute([$currentNodeId]);
        $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $debugMsg = "[{$timestamp}] Found " . count($nodes) . " active nodes to query (excluding current node: {$currentNodeId})" . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        // Process nodes to build URLs from metadata
        foreach ($nodes as &$node) {
            $metadata = json_decode($node['metadata'], true) ?: [];
            $protocol = $metadata['protocol'] ?? 'https';
            $domain = $metadata['domain'] ?? $node['ip_address'];
            
            $node['url'] = $protocol . '://' . $domain;
            if ($node['port'] !== 80 && $node['port'] !== 443) {
                $node['url'] .= ':' . $node['port'];
            }
            
            $debugMsg = "[{$timestamp}] Node {$node['id']}: URL={$node['url']}" . PHP_EOL;
            @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        }
        
        if (empty($nodes)) {
            $debugMsg = "[{$timestamp}] No active nodes to query, returning success" . PHP_EOL;
            @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
            return [
                'success' => true,
                'message' => 'No active nodes to query',
                'nodes_queried' => 0
            ];
        }
        
        // Query each node for their topology
        $topologyData = [];
        $successfulQueries = 0;
        
        $debugMsg = "[{$timestamp}] Starting topology queries for " . count($nodes) . " nodes..." . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        foreach ($nodes as $node) {
            try {
                $debugMsg = "[{$timestamp}] Querying node {$node['id']} at {$node['url']}..." . PHP_EOL;
                @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
                
                $response = queryNodeForTopology($node['url'], $node['id']);
                if ($response['success']) {
                    $topologyData[$node['id']] = $response['data'];
                    $successfulQueries++;
                    $debugMsg = "[{$timestamp}] Node {$node['id']} query SUCCESS" . PHP_EOL;
                    @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
                } else {
                    $debugMsg = "[{$timestamp}] Node {$node['id']} query FAILED: " . ($response['error'] ?? 'unknown error') . PHP_EOL;
                    @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
                }
            } catch (Exception $e) {
                $debugMsg = "[{$timestamp}] Node {$node['id']} query EXCEPTION: " . $e->getMessage() . PHP_EOL;
                @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
                writeLog("Failed to query node {$node['id']}: " . $e->getMessage(), 'WARNING');
            }
        }
        
        // Update topology database
        if (!empty($topologyData)) {
            $debugMsg = "[{$timestamp}] Updating topology database with data from {$successfulQueries} nodes..." . PHP_EOL;
            @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
            updateTopologyDatabase($pdo, $topologyData, $ttl);
        } else {
            $debugMsg = "[{$timestamp}] No topology data to update database" . PHP_EOL;
            @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        }
        
        // Cache the update
        $cacheData = [
            'nodes_queried' => count($nodes),
            'successful_queries' => $successfulQueries,
            'topology_data' => $topologyData,
            'timestamp' => time()
        ];
        
        $debugMsg = "[{$timestamp}] Caching topology update result: " . json_encode($cacheData) . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        $stmt = $pdo->prepare("
            INSERT INTO network_topology_cache (cache_key, cache_data, expires_at) 
            VALUES ('topology_update', ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
            ON DUPLICATE KEY UPDATE cache_data = VALUES(cache_data), expires_at = VALUES(expires_at)
        ");
        $stmt->execute([json_encode($cacheData), $ttl]);
        
        $debugMsg = "[{$timestamp}] === TOPOLOGY UPDATE COMPLETED === (queried: " . count($nodes) . ", successful: {$successfulQueries})" . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        return [
            'success' => true,
            'nodes_queried' => count($nodes),
            'successful_queries' => $successfulQueries,
            'topology_updated' => true
        ];
        
    } catch (Exception $e) {
        writeLog('updateNetworkTopology error: ' . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Ensure network topology cache is fresh (TTL not expired); if expired triggers update.
 * Returns array with keys: fresh(bool), updated(bool), last_update(?string), reason(string)
 */
function ensureFreshTopology($walletManager): array {
    try {
        // Debug logging
        $debugLog = __DIR__ . '/../logs/debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $debugMsg = "[{$timestamp}] ensureFreshTopology: CHECKING topology freshness..." . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        $pdo = $walletManager->getDatabase();
        $config = getNetworkConfigFromDatabase($pdo);
        $ttl = (int)($config['network.topology_ttl'] ?? 300); // seconds
        $now = time();

        $debugMsg = "[{$timestamp}] ensureFreshTopology: TTL={$ttl}s, current_time={$now}" . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);

        // Read cache entry
        $stmt = $pdo->prepare("SELECT cache_data, expires_at, created_at FROM network_topology_cache WHERE cache_key = 'topology_update' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $expiresAt = strtotime($row['expires_at'] ?? '') ?: 0;
            $debugMsg = "[{$timestamp}] ensureFreshTopology: Found cache entry - expires_at={$row['expires_at']}, expires_unix={$expiresAt}, now={$now}" . PHP_EOL;
            @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
            
            if ($expiresAt > $now) {
                // Still valid
                $debugMsg = "[{$timestamp}] ensureFreshTopology: Cache is VALID (expires in " . ($expiresAt - $now) . "s)" . PHP_EOL;
                @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
                return [
                    'success' => true,
                    'fresh' => true,
                    'updated' => false,
                    'last_update' => $row['created_at'] ?? null,
                    'reason' => 'cache_valid'
                ];
            } else {
                $debugMsg = "[{$timestamp}] ensureFreshTopology: Cache is EXPIRED (expired " . ($now - $expiresAt) . "s ago)" . PHP_EOL;
                @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
            }
        } else {
            $debugMsg = "[{$timestamp}] ensureFreshTopology: No cache entry found" . PHP_EOL;
            @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        }

        // Expired or missing -> update
        $debugMsg = "[{$timestamp}] ensureFreshTopology: Triggering topology update..." . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        writeLog('Topology cache stale or missing, performing update before broadcast', 'INFO');
        $res = updateNetworkTopology($walletManager);
        
        $debugMsg = "[{$timestamp}] ensureFreshTopology: Update result: " . json_encode($res) . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        
        return [
            'success' => $res['success'] ?? false,
            'fresh' => true,
            'updated' => true,
            'last_update' => date('Y-m-d H:i:s'),
            'reason' => 'cache_refreshed'
        ];
    } catch (Throwable $e) {
        $debugMsg = "[{$timestamp}] ensureFreshTopology: ERROR - " . $e->getMessage() . PHP_EOL;
        @file_put_contents($debugLog, $debugMsg, FILE_APPEND);
        writeLog('ensureFreshTopology error: ' . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'fresh' => false,
            'updated' => false,
            'reason' => 'exception:' . $e->getMessage()
        ];
    }
}

/**
 * Select optimal nodes for broadcasting based on network topology
 */
function selectOptimalBroadcastNodes($walletManager, string $transactionHash, int $batchSize = 10): array {
    try {
        $pdo = $walletManager->getDatabase();
        
        // Get configuration
        $config = getNetworkConfigFromDatabase($pdo);
        $maxConnections = (int)($config['network.max_connections_per_node'] ?? 20);
        
        // Get current node ID
        $currentNodeId = getCurrentNodeId($pdo);
        
        // Get nodes with best connectivity (most connections to other nodes)
        $stmt = $pdo->prepare("
            SELECT 
                n.node_id as id,
                n.ip_address,
                n.port,
                n.status,
                n.metadata,
                COUNT(t.target_node_id) as connection_count,
                AVG(t.connection_strength) as avg_strength
            FROM nodes n
            LEFT JOIN network_topology t ON n.node_id = t.source_node_id
            WHERE n.status = 'active' 
            AND n.node_id != ?
            AND (t.ttl_expires_at IS NULL OR t.ttl_expires_at > NOW())
            GROUP BY n.node_id, n.ip_address, n.port, n.status, n.metadata
            ORDER BY connection_count DESC, avg_strength DESC
            LIMIT ?
        ");
        $stmt->execute([$currentNodeId, $batchSize]);
        $optimalNodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process nodes to build URLs from metadata
        foreach ($optimalNodes as &$node) {
            $metadata = json_decode($node['metadata'], true) ?: [];
            $protocol = $metadata['protocol'] ?? 'https';
            $domain = $metadata['domain'] ?? $node['ip_address'];
            
            $node['url'] = $protocol . '://' . $domain;
            if ($node['port'] !== 80 && $node['port'] !== 443) {
                $node['url'] .= ':' . $node['port'];
            }
        }
        
        // If not enough nodes with connections, add random active nodes
        if (count($optimalNodes) < $batchSize) {
            $additionalCount = $batchSize - count($optimalNodes);
            
            // Build SQL query dynamically based on existing nodes
            if (count($optimalNodes) > 0) {
                // Build NOT IN clause for existing nodes
                $placeholders = str_repeat('?,', max(0, count($optimalNodes) - 1)) . '?';
                $sql = "SELECT node_id as id, ip_address, port, status, metadata FROM nodes WHERE status = 'active' AND node_id != ? AND node_id NOT IN ($placeholders) ORDER BY RAND() LIMIT $additionalCount";
                $params = [$currentNodeId];
                foreach ($optimalNodes as $node) {
                    $params[] = $node['id'];
                }
            } else {
                // No existing nodes, just exclude current node
                $sql = "SELECT node_id as id, ip_address, port, status, metadata FROM nodes WHERE status = 'active' AND node_id != ? ORDER BY RAND() LIMIT $additionalCount";
                $params = [$currentNodeId];
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $additionalNodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process additional nodes to build URLs
            foreach ($additionalNodes as &$node) {
                $metadata = json_decode($node['metadata'], true) ?: [];
                $protocol = $metadata['protocol'] ?? 'https';
                $domain = $metadata['domain'] ?? $node['ip_address'];
                
                $node['url'] = $protocol . '://' . $domain;
                if ($node['port'] !== 80 && $node['port'] !== 443) {
                    $node['url'] .= ':' . $node['port'];
                }
            }
            
            $optimalNodes = array_merge($optimalNodes, $additionalNodes);
        }
        
        // Prepare broadcast instructions for each node
        $broadcastInstructions = [];
        foreach ($optimalNodes as $node) {
            // Get nodes that this node can reach (excluding already selected ones)
            if (count($optimalNodes) > 1) {
                // Build NOT IN clause for other selected nodes
                $otherNodes = [];
                foreach ($optimalNodes as $selectedNode) {
                    if ($selectedNode['id'] !== $node['id']) {
                        $otherNodes[] = $selectedNode['id'];
                    }
                }
                
                if (!empty($otherNodes)) {
                    $placeholders = str_repeat('?,', max(0, count($otherNodes) - 1)) . '?';
                    $sql = "SELECT target_node_id FROM network_topology WHERE source_node_id = ? AND target_node_id NOT IN ($placeholders) AND (ttl_expires_at IS NULL OR ttl_expires_at > NOW()) LIMIT $maxConnections";
                    $params = [$node['id']];
                    foreach ($otherNodes as $otherNodeId) {
                        $params[] = $otherNodeId;
                    }
                } else {
                    $sql = "SELECT target_node_id FROM network_topology WHERE source_node_id = ? AND (ttl_expires_at IS NULL OR ttl_expires_at > NOW()) LIMIT $maxConnections";
                    $params = [$node['id']];
                }
            } else {
                $sql = "SELECT target_node_id FROM network_topology WHERE source_node_id = ? AND (ttl_expires_at IS NULL OR ttl_expires_at > NOW()) LIMIT $maxConnections";
                $params = [$node['id']];
            }
            
            $stmt = $pdo->prepare($sql);
            
            $stmt->execute($params);
            $reachableNodes = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $broadcastInstructions[$node['id']] = [
                'url' => $node['url'],
                'status' => $node['status'],
                'connection_count' => $node['connection_count'] ?? 0,
                'reachable_nodes' => $reachableNodes,
                'broadcast_to' => array_slice($reachableNodes, 0, $maxConnections)
            ];
        }
        
        return [
            'success' => true,
            'transaction_hash' => $transactionHash,
            'selected_nodes' => $optimalNodes,
            'broadcast_instructions' => $broadcastInstructions,
            'total_coverage' => count(array_unique(array_merge(...array_column($broadcastInstructions, 'reachable_nodes'))))
        ];
        
    } catch (Exception $e) {
        writeLog('selectOptimalBroadcastNodes error: ' . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Query a specific node for its topology information
 */
function queryNodeForTopology(string $nodeUrl, string $nodeId): array {
    try {
        $url = rtrim($nodeUrl, '/') . '/wallet/wallet_api.php';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'action' => 'listnodes'
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: BlockchainNode/2.0'
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            throw new Exception("HTTP $httpCode or empty response from $nodeId");
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from $nodeId");
        }
        
        // Extract node information and create connections
        $nodeInfo = $data['data'] ?? $data;
        $connections = [];
        
        // Create connections to other active nodes
        if (isset($nodeInfo['nodes']) && is_array($nodeInfo['nodes'])) {
            foreach ($nodeInfo['nodes'] as $otherNode) {
                if (isset($otherNode['id']) && $otherNode['id'] !== $nodeId) {
                    $connections[] = [
                        'target_node_id' => $otherNode['id'],
                        'connection_strength' => 1, // Base strength for PoS network
                        'connection_type' => 'pos_peer',
                        'last_seen' => $otherNode['last_seen'] ?? date('Y-m-d H:i:s')
                    ];
                }
            }
        }
        
        return [
            'success' => true,
            'data' => [
                'current_node_id' => $nodeId,
                'nodes' => $nodeInfo['nodes'] ?? [],
                'connections' => $connections,
                'timestamp' => time()
            ]
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Update topology database with collected information
 */
function updateTopologyDatabase(PDO $pdo, array $topologyData, int $ttl): void {
    try {
        $pdo->beginTransaction();
        
        // Clear old expired entries
        $stmt = $pdo->prepare("DELETE FROM network_topology WHERE ttl_expires_at < NOW()");
        $stmt->execute();
        
        // Insert new topology data for PoS network
        $stmt = $pdo->prepare("
            INSERT INTO network_topology (source_node_id, target_node_id, connection_strength, connection_type, ttl_expires_at)
            VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
            ON DUPLICATE KEY UPDATE 
                connection_strength = VALUES(connection_strength),
                connection_type = VALUES(connection_type),
                last_updated = NOW(),
                ttl_expires_at = VALUES(ttl_expires_at)
        ");
        
        foreach ($topologyData as $sourceNodeId => $nodeData) {
            if (isset($nodeData['connections']) && is_array($nodeData['connections'])) {
                foreach ($nodeData['connections'] as $connection) {
                    if (isset($connection['target_node_id'])) {
                        $stmt->execute([
                            $sourceNodeId,
                            $connection['target_node_id'],
                            $connection['connection_strength'] ?? 1,
                            $connection['connection_type'] ?? 'pos_peer',
                            $ttl
                        ]);
                    }
                }
            }
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Process broadcast instructions to forward transactions to other nodes
 */
function processBroadcastInstructions(PDO $pdo, array $transaction, array $broadcastInstructions): array {
    try {
        $config = getNetworkConfigFromDatabase($pdo);
        $timeout = $config['broadcast.timeout'] ?? 10;
        $maxConnections = (int)($config['network.max_connections_per_node'] ?? 20);
        
        if (empty($broadcastInstructions['reachable_nodes'])) {
            return [
                'success' => true,
                'message' => 'No additional nodes to forward to',
                'nodes_contacted' => 0
            ];
        }
        
        // Get node information for reachable nodes
        $reachableNodeIds = array_slice($broadcastInstructions['reachable_nodes'], 0, $maxConnections);
        $placeholders = str_repeat('?,', count($reachableNodeIds) - 1) . '?';
        
        $stmt = $pdo->prepare("SELECT node_id as id, ip_address, port, metadata FROM nodes WHERE node_id IN ($placeholders) AND status = 'active'");
        $stmt->execute($reachableNodeIds);
        $nodesToContact = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process nodes to build URLs from metadata
        foreach ($nodesToContact as &$node) {
            $metadata = json_decode($node['metadata'], true) ?: [];
            $protocol = $metadata['protocol'] ?? 'https';
            $domain = $metadata['domain'] ?? $node['ip_address'];
            
            $node['url'] = $protocol . '://' . $domain;
            if ($node['port'] !== 80 && $node['port'] !== 443) {
                $node['url'] .= ':' . $node['port'];
            }
        }
        
        if (empty($nodesToContact)) {
            return [
                'success' => true,
                'message' => 'No active nodes found for forwarding',
                'nodes_contacted' => 0
            ];
        }
        
        // Forward transaction to additional nodes
        $successful = 0;
        $failed = 0;
        
        foreach ($nodesToContact as $node) {
            try {
                $response = forwardTransactionToNode($node['url'], $transaction, $timeout);
                if ($response['success']) {
                    $successful++;
                    writeLog("Successfully forwarded transaction to node {$node['id']}", 'INFO');
                } else {
                    $failed++;
                    writeLog("Failed to forward transaction to node {$node['id']}: " . ($response['error'] ?? 'Unknown error'), 'WARNING');
                }
            } catch (Exception $e) {
                $failed++;
                writeLog("Exception forwarding to node {$node['id']}: " . $e->getMessage(), 'ERROR');
            }
        }
        
        return [
            'success' => $successful > 0,
            'nodes_contacted' => count($nodesToContact),
            'successful_forwards' => $successful,
            'failed_forwards' => $failed,
            'total_reachable' => count($broadcastInstructions['reachable_nodes'])
        ];
        
    } catch (Exception $e) {
        writeLog('processBroadcastInstructions error: ' . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'nodes_contacted' => 0
        ];
    }
}

/**
 * Update broadcast statistics
 */
function updateBroadcastStats(PDO $pdo, string $metricType, string $nodeId): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO broadcast_stats (node_id, metric_type, metric_value, recorded_at)
            VALUES (?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                metric_value = metric_value + 1,
                recorded_at = NOW()
        ");
        $stmt->execute([$nodeId, $metricType]);
    } catch (Exception $e) {
        writeLog('Error updating broadcast stats: ' . $e->getMessage(), 'ERROR');
    }
}

/**
 * Record broadcast tracking
 */
function recordBroadcastTracking(PDO $pdo, string $txHash, string $sourceNodeId, int $hopCount, string $broadcastPath): void {
    try {
        $currentNodeId = getCurrentNodeId($pdo);
        $stmt = $pdo->prepare("
            INSERT INTO broadcast_tracking 
            (transaction_hash, source_node_id, current_node_id, hop_count, broadcast_path, created_at, expires_at)
            VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))
            ON DUPLICATE KEY UPDATE
                hop_count = VALUES(hop_count),
                broadcast_path = VALUES(broadcast_path),
                created_at = NOW(),
                expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$txHash, $sourceNodeId, $currentNodeId, $hopCount, $broadcastPath]);
    } catch (Exception $e) {
        writeLog('Error recording broadcast tracking: ' . $e->getMessage(), 'ERROR');
    }
}

/**
 * Clean up expired broadcast tracking records
 */
function cleanupExpiredBroadcastTracking(PDO $pdo): int {
    try {
        $stmt = $pdo->prepare("DELETE FROM broadcast_tracking WHERE expires_at < NOW()");
        $stmt->execute();
        $deletedCount = $stmt->rowCount();
        
        if ($deletedCount > 0) {
            writeLog("Cleaned up $deletedCount expired broadcast tracking records", 'DEBUG');
        }
        
        return $deletedCount;
    } catch (Exception $e) {
        writeLog('Error cleaning up expired broadcast tracking: ' . $e->getMessage(), 'ERROR');
        return 0;
    }
}

/**
 * Forward transaction to a specific node
 */
function forwardTransactionToNode(string $nodeUrl, array $transaction, int $timeout): array {
    try {
        $url = rtrim($nodeUrl, '/') . '/wallet/wallet_api.php';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'action' => 'broadcast_transaction',
                'transaction' => $transaction,
                'source_node' => getCurrentNodeId(new PDO('mysql:host=localhost;dbname=blockchain', 'blockchain', 'blockchain123')),
                'timestamp' => time(),
                'forwarded' => true
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: BlockchainNode/2.0'
            ],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            return [
                'success' => false,
                'error' => "HTTP $httpCode or empty response"
            ];
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response'
            ];
        }
        
        return [
            'success' => true,
            'response' => $data
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Validate private key format and strength
 */
function isValidPrivateKey(string $privateKey): bool {
    try {
        // Remove 0x prefix if present
        $cleanKey = str_replace('0x', '', trim($privateKey));
        
        // Check basic format
        if (!ctype_xdigit($cleanKey)) {
            return false;
        }
        
        // Check length (64 characters for 256-bit key)
        if (strlen($cleanKey) !== 64) {
            return false;
        }
        
        // Check if it's not all zeros
        if (hexdec($cleanKey) === 0) {
            return false;
        }
        
        // Try to create key pair to validate the key
        try {
            $kp = \Blockchain\Core\Cryptography\KeyPair::fromPrivateKey($cleanKey);
            $publicKey = $kp->getPublicKey();
            
            // Validate public key format
            if (empty($publicKey) || !str_starts_with($publicKey, '0x')) {
                return false;
            }
            
            return true;
        } catch (\Throwable $e) {
            // If key pair creation fails, it's invalid
            return false;
        }
        
    } catch (\Throwable $e) {
        // Any exception means invalid key
        return false;
    }
}

/**
 * Check and handle transaction replacement (RBF - Replace By Fee)
 * Implements EIP-1559 style transaction replacement for cancel operations
 */
function checkAndHandleTransactionReplacement(PDO $pdo, $newTransaction, string $newTxHash): array {
    try {
        $fromAddress = $newTransaction->getFrom();
        $nonce = $newTransaction->getNonce();
        $newGasPrice = $newTransaction->getGasPrice();
        
        // Force logging for debugging
        writeLog("🔥 RBF Check: from={$fromAddress}, nonce={$nonce}, new_gas_price={$newGasPrice}", 'INFO');
        
        // Debug log the SQL query
        $debugLog = __DIR__ . '/../logs/debug.log';
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($debugLog, "[{$timestamp}] 🔥 RBF: Searching for conflicts with from_address={$fromAddress}, nonce={$nonce}" . PHP_EOL, FILE_APPEND);
        
        // Check for existing transactions with same from_address and nonce
        $stmt = $pdo->prepare("
            SELECT tx_hash, gas_price, amount, to_address, created_at 
            FROM mempool 
            WHERE from_address = ? AND nonce = ? 
            ORDER BY gas_price DESC
        ");
        $stmt->execute([$fromAddress, $nonce]);
        $existingTxs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Debug log what we found
        @file_put_contents($debugLog, "[{$timestamp}] 🔥 RBF: Found " . count($existingTxs) . " existing transactions with same nonce" . PHP_EOL, FILE_APPEND);
        if (!empty($existingTxs)) {
            foreach ($existingTxs as $idx => $tx) {
                @file_put_contents($debugLog, "[{$timestamp}] 🔥 RBF: Existing tx {$idx}: hash={$tx['tx_hash']}, gas_price={$tx['gas_price']}" . PHP_EOL, FILE_APPEND);
            }
        }
        
        if (empty($existingTxs)) {
            // No conflict, normal addition
            writeLog("🔥 RBF: No conflicts found, allowing normal addition", 'INFO');
            return ['action' => 'add', 'reason' => 'no_conflict'];
        }
        
        // Find transaction with highest gas price
        $highestGasTx = $existingTxs[0];
        $existingGasPrice = (float)$highestGasTx['gas_price'];
        $existingHash = $highestGasTx['tx_hash'];
        
        writeLog("RBF: Found existing tx {$existingHash} with gas_price={$existingGasPrice}", 'DEBUG');
        
        // Minimum gas price increase (10% by default, EIP-1559 standard)
        $minGasPriceIncrease = $existingGasPrice * 1.10;
        
        if ($newGasPrice > $minGasPriceIncrease) {
            // New transaction has sufficiently higher gas price - replace all existing ones
            writeLog("RBF: Replacing transactions with lower gas price (new: {$newGasPrice} > required: {$minGasPriceIncrease})", 'INFO');
            
            // Remove all existing transactions with same nonce
            $deleteStmt = $pdo->prepare("DELETE FROM mempool WHERE from_address = ? AND nonce = ?");
            $deleteStmt->execute([$fromAddress, $nonce]);
            $deletedCount = $deleteStmt->rowCount();
            
            // Add the new transaction manually to ensure it gets added
            $insertStmt = $pdo->prepare("
                INSERT INTO mempool (
                    tx_hash, from_address, to_address, amount, fee, 
                    gas_price, gas_limit, nonce, data, signature, 
                    priority_score, created_at, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')
            ");
            
            $priorityScore = $newGasPrice * 1000; // Simple priority calculation
            $result = $insertStmt->execute([
                $newTxHash,
                $newTransaction->getFrom(),
                $newTransaction->getTo(),
                $newTransaction->getAmount(),
                $newTransaction->getFee(),
                $newGasPrice,
                $newTransaction->getGasLimit(),
                $nonce,
                $newTransaction->getData() ?? '',
                $newTransaction->getSignature() ?? '',
                $priorityScore
            ]);
            
            if ($result) {
                writeLog("RBF SUCCESS: Replaced {$deletedCount} transactions, added new tx {$newTxHash}", 'INFO');
                return [
                    'action' => 'replaced',
                    'old_hash' => $existingHash,
                    'new_hash' => $newTxHash,
                    'replaced_count' => $deletedCount,
                    'reason' => 'higher_gas_price'
                ];
            } else {
                writeLog("RBF FAILED: Could not insert replacement transaction", 'ERROR');
                return ['action' => 'error', 'reason' => 'insert_failed'];
            }
            
        } elseif ($newGasPrice === $existingGasPrice) {
            // Same gas price - check if it's an identical transaction (allow idempotent re-submission)
            $newAmount = $newTransaction->getAmount();
            $newToAddress = $newTransaction->getTo();
            
            foreach ($existingTxs as $existing) {
                if ((float)$existing['amount'] === $newAmount && $existing['to_address'] === $newToAddress) {
                    writeLog("RBF: Identical transaction already exists - ignoring duplicate", 'DEBUG');
                    return [
                        'action' => 'duplicate',
                        'existing_hash' => $existing['tx_hash'],
                        'reason' => 'identical_transaction'
                    ];
                }
            }
            
            // Same gas price but different transaction - reject
            writeLog("RBF REJECTED: Same gas price but different transaction content", 'INFO');
            return [
                'action' => 'rejected',
                'existing_hash' => $existingHash,
                'reason' => 'insufficient_gas_price_same_nonce'
            ];
            
        } else {
            // Lower gas price - reject
            writeLog("RBF REJECTED: New gas price {$newGasPrice} is lower than existing {$existingGasPrice} (required: {$minGasPriceIncrease})", 'INFO');
            return [
                'action' => 'rejected',
                'existing_hash' => $existingHash,
                'existing_gas_price' => $existingGasPrice,
                'new_gas_price' => $newGasPrice,
                'required_gas_price' => $minGasPriceIncrease,
                'reason' => 'insufficient_gas_price'
            ];
        }
        
    } catch (Exception $e) {
        writeLog("RBF ERROR: " . $e->getMessage(), 'ERROR');
        return [
            'action' => 'error',
            'error' => $e->getMessage(),
            'reason' => 'exception'
        ];
    }
}

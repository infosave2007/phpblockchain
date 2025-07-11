<?php
// Main API Router

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Get the request path
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);
$path = str_replace('/api', '', $path);
$path = trim($path, '/');

// Parse request method and data
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$params = array_merge($_GET, $input);

try {
    // Route the request
    $pathParts = explode('/', $path);
    $module = $pathParts[0] ?? '';
    
    switch ($module) {
        case 'explorer':
            // Forward to explorer API
            include_once 'explorer/index.php';
            break;
            
        case 'blockchain':
            // Handle blockchain API requests
            echo json_encode(handleBlockchainAPI($method, $pathParts, $params));
            break;
            
        case 'wallet':
            // Handle wallet API requests
            echo json_encode(handleWalletAPI($method, $pathParts, $params));
            break;
            
        case 'nodes':
            // Handle node API requests
            echo json_encode(handleNodesAPI($method, $pathParts, $params));
            break;
            
        case 'status':
            // System status
            echo json_encode(getSystemStatus());
            break;
            
        case '':
            // API info
            echo json_encode(getAPIInfo());
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'API module not found: ' . $module]);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Error $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

function handleBlockchainAPI(string $method, array $pathParts, array $params): array
{
    $action = $pathParts[1] ?? '';
    
    switch ($action) {
        case 'info':
            return getBlockchainInfo();
            
        case 'blocks':
            return getBlockchainBlocks($params);
            
        case 'transactions':
            return getBlockchainTransactions($params);
            
        case 'submit':
            if ($method !== 'POST') {
                throw new Exception('POST method required');
            }
            return submitTransaction($params);
            
        default:
            throw new Exception('Unknown blockchain action: ' . $action);
    }
}

function handleWalletAPI(string $method, array $pathParts, array $params): array
{
    $action = $pathParts[1] ?? '';
    
    switch ($action) {
        case 'create':
            if ($method !== 'POST') {
                throw new Exception('POST method required');
            }
            return createWallet($params);
            
        case 'balance':
            $address = $pathParts[2] ?? $params['address'] ?? '';
            if (empty($address)) {
                throw new Exception('Wallet address required');
            }
            return getWalletBalance($address);
            
        case 'transactions':
            $address = $pathParts[2] ?? $params['address'] ?? '';
            if (empty($address)) {
                throw new Exception('Wallet address required');
            }
            return getWalletTransactions($address, $params);
            
        default:
            throw new Exception('Unknown wallet action: ' . $action);
    }
}

function handleNodesAPI(string $method, array $pathParts, array $params): array
{
    $action = $pathParts[1] ?? '';
    
    switch ($action) {
        case 'list':
            return getNodesList();
            
        case 'status':
            return getNodesStatus();
            
        case 'connect':
            if ($method !== 'POST') {
                throw new Exception('POST method required');
            }
            return connectToNode($params);
            
        default:
            throw new Exception('Unknown nodes action: ' . $action);
    }
}

function getAPIInfo(): array
{
    return [
        'name' => 'Blockchain API',
        'version' => '1.0.0',
        'endpoints' => [
            'GET /api/status' => 'System status',
            'GET /api/explorer/stats' => 'Network statistics',
            'GET /api/explorer/blocks' => 'Latest blocks',
            'GET /api/explorer/transactions' => 'Latest transactions',
            'GET /api/blockchain/info' => 'Blockchain information',
            'GET /api/wallet/balance/{address}' => 'Wallet balance',
            'POST /api/wallet/create' => 'Create new wallet',
            'GET /api/nodes/list' => 'Connected nodes'
        ],
        'timestamp' => time()
    ];
}

function getSystemStatus(): array
{
    // Load environment variables
    require_once '../core/Environment/EnvironmentLoader.php';
    \Blockchain\Core\Environment\EnvironmentLoader::load(dirname(__DIR__));
    
    // Load configuration
    $configExists = file_exists('../config/config.php');
    $dbStatus = 'unknown';
    
    if ($configExists) {
        try {
            require_once '../core/Database/DatabaseManager.php';
            $pdo = \Blockchain\Core\Database\DatabaseManager::getConnection();
            $dbStatus = 'connected';
        } catch (Exception $e) {
            $dbStatus = 'error';
        }
    }
    
    return [
        'status' => 'online',
        'timestamp' => time(),
        'php_version' => PHP_VERSION,
        'memory_usage' => memory_get_usage(true),
        'config_exists' => $configExists,
        'database_status' => $dbStatus,
        'storage_writable' => is_writable('../storage'),
        'logs_writable' => is_writable('../logs')
    ];
}

function getBlockchainInfo(): array
{
    // Load blockchain state
    $stateFile = '../storage/state/blockchain_state.json';
    $chainFile = '../storage/blockchain/chain.json';
    
    $info = [
        'network' => 'mainnet',
        'height' => 0,
        'difficulty' => 1,
        'hash_rate' => '0 H/s',
        'version' => '1.0.0'
    ];
    
    if (file_exists($stateFile)) {
        $state = json_decode(file_get_contents($stateFile), true);
        if ($state) {
            $info = array_merge($info, $state);
        }
    }
    
    if (file_exists($chainFile)) {
        $chain = json_decode(file_get_contents($chainFile), true);
        if ($chain && is_array($chain)) {
            $info['height'] = count($chain) - 1;
            $info['latest_block'] = end($chain);
        }
    }
    
    return $info;
}

// Placeholder functions - implement these based on your blockchain logic
function getBlockchainBlocks(array $params): array
{
    return ['message' => 'Blockchain blocks endpoint - implement me'];
}

function getBlockchainTransactions(array $params): array
{
    return ['message' => 'Blockchain transactions endpoint - implement me'];
}

function submitTransaction(array $params): array
{
    return ['message' => 'Submit transaction endpoint - implement me'];
}

function createWallet(array $params): array
{
    return ['message' => 'Create wallet endpoint - implement me'];
}

function getWalletBalance(string $address): array
{
    return ['message' => 'Wallet balance endpoint - implement me', 'address' => $address];
}

function getWalletTransactions(string $address, array $params): array
{
    return ['message' => 'Wallet transactions endpoint - implement me', 'address' => $address];
}

function getNodesList(): array
{
    return ['nodes' => [], 'message' => 'Nodes list endpoint - implement me'];
}

function getNodesStatus(): array
{
    return ['status' => 'unknown', 'message' => 'Nodes status endpoint - implement me'];
}

function connectToNode(array $params): array
{
    return ['message' => 'Connect to node endpoint - implement me'];
}
?>

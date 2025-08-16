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
        case 'sync':
            // Forward to wallet sync API (handles its own output)
            include_once 'sync/wallet.php';
            exit(0);
            
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
            
        case 'register':
            if ($method !== 'POST') {
                throw new Exception('POST method required');
            }
            return registerNewNode($params);
            
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
            'GET /api/explorer/nodes' => 'Active network nodes',
            'GET /api/blockchain/info' => 'Blockchain information',
            'GET /api/wallet/balance/{address}' => 'Wallet balance',
            'POST /api/wallet/create' => 'Create new wallet',
            'GET /api/nodes/list' => 'Connected nodes',
            'POST /api/nodes/register' => 'Register new node'
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

/**
 * Register a new node in the network
 * This endpoint is called by new nodes to register themselves
 */
function registerNewNode(array $params): array
{
    $logFile = '../logs/node_registration.log';
    $logMessage = "\n=== NODE REGISTRATION API " . date('Y-m-d H:i:s') . " ===\n";
    
    try {
        // Log incoming request
        $logMessage .= "Incoming registration request from: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
        $logMessage .= "Request data: " . json_encode($params, JSON_PRETTY_PRINT) . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Load environment and database connection
        require_once '../core/Environment/EnvironmentLoader.php';
        \Blockchain\Core\Environment\EnvironmentLoader::load(dirname(__DIR__));
        
        require_once '../core/Database/DatabaseManager.php';
        $pdo = \Blockchain\Core\Database\DatabaseManager::getConnection();
        
        // Ensure autocommit is on and connection is properly configured
        $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Validate required fields
        $requiredFields = ['node_id', 'domain', 'protocol'];
        foreach ($requiredFields as $field) {
            if (!isset($params[$field]) || empty($params[$field])) {
                $logMessage = "❌ Missing required field: $field\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                throw new Exception("Missing required field: $field");
            }
        }
        
        $logMessage = "✓ All required fields present\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // For domain-based registration, use domain as primary identifier
        // IP address will be determined automatically or use provided ip_address
        $ip = $params['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $port = $params['port'] ?? 80;
        $publicKey = $params['public_key'] ?? '';
        $domain = $params['domain'];
        
        // Check for existing node by domain first (primary identifier)
        $checkStmt = $pdo->prepare("
            SELECT id, node_id, ip_address, port, public_key FROM nodes 
            WHERE JSON_EXTRACT(metadata, '$.domain') = ?
        ");
        $checkStmt->execute([$domain]);
        $existingNodeByDomain = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingNodeByDomain) {
            $logMessage = "✓ Found existing node by domain: " . $domain . " (ID: " . $existingNodeByDomain['id'] . ")\n";
            $logMessage .= "  Current: IP=" . $existingNodeByDomain['ip_address'] . ", port=" . $existingNodeByDomain['port'] . ", public_key=" . substr($existingNodeByDomain['public_key'], 0, 20) . "...\n";
            $logMessage .= "  New: IP=" . $ip . ", port=" . $port . ", public_key=" . substr($publicKey, 0, 20) . "...\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            $existingNode = $existingNodeByDomain;
        } else {
            // Check for existing node by unique combination: IP + port + public_key (secondary check)
            $checkStmt = $pdo->prepare("
                SELECT id, node_id FROM nodes 
                WHERE ip_address = ? AND port = ? AND public_key = ?
            ");
            $checkStmt->execute([$ip, $port, $publicKey]);
            $existingNode = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingNode) {
                $logMessage = "✓ Found existing node by IP/port/key (ID: " . $existingNode['id'] . ", node_id: " . $existingNode['node_id'] . ")\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
        
        if ($existingNode) {
            $logMessage = "✓ Updating existing node (ID: " . $existingNode['id'] . ")\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            // Update existing node with transaction
            $metadata = json_encode([
                'domain' => $params['domain'],
                'protocol' => $params['protocol'],
                'node_type' => $params['node_type'] ?? 'regular'
            ]);
            
            try {
                $pdo->beginTransaction();
                
                $updateStmt = $pdo->prepare("
                    UPDATE nodes 
                    SET node_id = ?, ip_address = ?, port = ?, public_key = ?, version = ?, status = 'active', 
                        last_seen = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP, metadata = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $params['node_id'],
                    $ip,
                    $port,
                    $publicKey,
                    $params['version'] ?? '1.0.0',
                    $metadata,
                    $existingNode['id']
                ]);
                
                $pdo->commit();
                
                $message = 'Node updated successfully';
                $nodeId = $existingNode['id'];
                
                $logMessage = "✓ UPDATE successful, node ID: $nodeId\n";
                $logMessage .= "✓ Transaction committed successfully\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $logMessage = "❌ UPDATE failed: " . $e->getMessage() . "\n";
                $logMessage .= "❌ Transaction rolled back\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                throw $e;
            }
        } else {
            $logMessage = "✓ Registering new node\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            // Insert new node with proper field mapping
            $metadata = json_encode([
                'domain' => $params['domain'],
                'protocol' => $params['protocol'],
                'node_type' => $params['node_type'] ?? 'regular'
            ]);
            
            try {
                // Start transaction explicitly
                $pdo->beginTransaction();
                
                $insertStmt = $pdo->prepare("
                    INSERT INTO nodes (node_id, ip_address, port, public_key, version, status, 
                                     last_seen, blocks_synced, ping_time, reputation_score, metadata, 
                                     created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP, 0, 0, 100, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $insertStmt->execute([
                    $params['node_id'],                           // 1. node_id
                    $ip,                                          // 2. ip_address  
                    $port,                                        // 3. port
                    $publicKey,                                   // 4. public_key
                    $params['version'] ?? '1.0.0',              // 5. version
                    $metadata                                     // 6. metadata
                ]);
                
                $nodeId = $pdo->lastInsertId();
                
                // Commit transaction
                $pdo->commit();
                
                $message = 'Node registered successfully';
                
                $logMessage = "✓ INSERT successful, new node ID: $nodeId\n";
                $logMessage .= "✓ Transaction committed successfully\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                
            } catch (PDOException $e) {
                // Rollback transaction on error
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                
                $logMessage = "❌ INSERT failed: " . $e->getMessage() . "\n";
                $logMessage .= "❌ Transaction rolled back\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                
                // Check if it's a duplicate key error
                if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    // If it's the unique_node constraint (ip_address-port), check if it's the same public_key
                    if (strpos($e->getMessage(), 'unique_node') !== false) {
                        // Find existing node by IP and port
                        $existingStmt = $pdo->prepare("
                            SELECT id, public_key FROM nodes 
                            WHERE ip_address = ? AND port = ?
                        ");
                        $existingStmt->execute([
                            $ip, 
                            $port
                        ]);
                        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($existing) {
                            if ($existing['public_key'] === $publicKey) {
                                // Same public key - this is a legitimate update
                                $nodeId = $existing['id'];
                                $message = 'Node already exists with same IP/port/public_key, returning existing ID';
                                $logMessage = "✓ Found existing node with matching credentials: $nodeId\n";
                            } else {
                                // Different public key - this is a conflict due to DB constraint
                                throw new Exception('Cannot register multiple nodes on same IP:port due to database constraint. Contact administrator to enable multiple nodes per host.');
                            }
                        } else {
                            throw $e; // Re-throw if we can't find the conflicting record
                        }
                    } else {
                        // Try to find existing node by unique combination: IP + port + public_key
                        $existingStmt = $pdo->prepare("
                            SELECT id FROM nodes 
                            WHERE ip_address = ? AND port = ? AND public_key = ?
                        ");
                        $existingStmt->execute([
                            $ip, 
                            $port, 
                            $publicKey
                        ]);
                        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($existing) {
                            $nodeId = $existing['id'];
                            $message = 'Node already exists with same IP/port/public_key combination, returning existing ID';
                            $logMessage = "✓ Found existing node with same IP/port/public_key: $nodeId\n";
                        } else {
                            throw $e; // Re-throw if we can't handle it
                        }
                    }
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                } else {
                    throw $e; // Re-throw for other errors
                }
            }
        }
        
        $logMessage = "✓ $message (Node ID: $nodeId)\n";
        $logMessage .= "✓ Registration completed successfully\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Log the registration to system log as well
        error_log("Node registration: {$params['domain']} ({$params['node_id']})");
        
        return [
            'status' => 'success',
            'message' => $message,
            'data' => [
                'node_id' => $nodeId,
                'domain' => $params['domain'],
                'registered_at' => date('Y-m-d H:i:s')
            ]
        ];
        
    } catch (Exception $e) {
        $logMessage = "❌ Registration failed: " . $e->getMessage() . "\n";
        $logMessage .= "❌ Stack trace: " . $e->getTraceAsString() . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        error_log("Node registration failed: " . $e->getMessage());
        
        return [
            'status' => 'error',
            'message' => 'Node registration failed: ' . $e->getMessage(),
            'data' => null
        ];
    }
}
?>

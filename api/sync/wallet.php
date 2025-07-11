<?php
/**
 * Wallet Synchronization API
 * Handles wallet sync between blockchain nodes
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Node-ID');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Error handler
function handleError($message, $code = 500) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message,
        'timestamp' => time()
    ]);
    exit();
}

// Logging function
function writeLog($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    error_log($logMessage, 3, __DIR__ . '/wallet_sync.log');
}

try {
    // Load environment variables
    require_once '../core/Environment/EnvironmentLoader.php';
    \Blockchain\Core\Environment\EnvironmentLoader::load(dirname(__DIR__));
    
    // Get request data
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? $_GET['action'] ?? '';
    
    if (empty($action)) {
        handleError('Action parameter is required', 400);
    }
    
    writeLog("Wallet sync API called with action: $action");
    
    // Load DatabaseManager
    require_once '../core/Database/DatabaseManager.php';
    
    // Connect to database using DatabaseManager
    $pdo = \Blockchain\Core\Database\DatabaseManager::getConnection();
    
    // Load blockchain manager
    require_once 'WalletBlockchainManager.php';
    $blockchainManager = new \Blockchain\Wallet\WalletBlockchainManager($pdo, []);
    
    $result = [];
    
    switch ($action) {
        case 'receive_wallet_transaction':
            $result = receiveWalletTransaction($pdo, $input);
            break;
            
        case 'sync_wallet_with_node':
            $result = syncWalletWithNode($pdo, $blockchainManager, $input);
            break;
            
        case 'get_wallet_sync_status':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Wallet address is required');
            }
            $result = getWalletSyncStatus($pdo, $address);
            break;
            
        case 'broadcast_wallet_update':
            $result = broadcastWalletUpdate($pdo, $input);
            break;
            
        default:
            handleError('Unknown action: ' . $action, 400);
    }
    
    // Success response
    echo json_encode([
        'success' => true,
        'data' => $result,
        'timestamp' => time()
    ]);
    
} catch (PDOException $e) {
    writeLog("Database error: " . $e->getMessage(), 'ERROR');
    handleError('Database error: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    writeLog("Error: " . $e->getMessage(), 'ERROR');
    handleError($e->getMessage(), 500);
}

/**
 * Receive wallet transaction from another node
 */
function receiveWalletTransaction($pdo, array $data) {
    writeLog("Receiving wallet transaction from node", 'INFO');
    
    $nodeId = $_SERVER['HTTP_X_NODE_ID'] ?? 'unknown';
    $transaction = $data['transaction'] ?? [];
    $block = $data['block'] ?? [];
    
    if (empty($transaction) || empty($block)) {
        throw new Exception('Transaction and block data are required');
    }
    
    writeLog("Processing transaction: " . $transaction['hash'], 'INFO');
    
    // Check if transaction already exists
    $stmt = $pdo->prepare("SELECT id FROM transactions WHERE tx_hash = ?");
    $stmt->execute([$transaction['hash']]);
    if ($stmt->fetch()) {
        writeLog("Transaction already exists: " . $transaction['hash'], 'INFO');
        return ['status' => 'already_exists'];
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Insert or update block
        $stmt = $pdo->prepare("
            INSERT INTO blocks (hash, parent_hash, height, timestamp, merkle_root, transactions_count, metadata) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            merkle_root = VALUES(merkle_root),
            transactions_count = VALUES(transactions_count),
            metadata = VALUES(metadata)
        ");
        
        $stmt->execute([
            $block['hash'],
            $block['previous_hash'] ?? '',
            $block['height'],
            $block['timestamp'],
            $block['merkle_root'] ?? '',
            count($block['transactions'] ?? []),
            json_encode(['synced_from' => $nodeId])
        ]);
        
        // Insert transaction
        $stmt = $pdo->prepare("
            INSERT INTO transactions (tx_hash, block_hash, from_address, to_address, amount, fee, data, signature, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $transaction['hash'],
            $block['hash'],
            $transaction['from'],
            $transaction['to'],
            $transaction['amount'],
            $transaction['fee'],
            json_encode($transaction['data']),
            $transaction['signature']
        ]);
        
        // Update or create wallet if it's a wallet transaction
        if (in_array($transaction['type'], ['wallet_create', 'wallet_restore'])) {
            $walletAddress = $transaction['to'];
            $publicKey = $transaction['data']['public_key'] ?? '';
            
            if ($walletAddress && $publicKey) {
                $stmt = $pdo->prepare("
                    INSERT INTO wallets (address, public_key, balance, created_at, updated_at)
                    VALUES (?, ?, 0, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE 
                    public_key = VALUES(public_key),
                    updated_at = NOW()
                ");
                
                $stmt->execute([$walletAddress, $publicKey]);
                writeLog("Wallet synced: " . $walletAddress, 'INFO');
            }
        }
        
        $pdo->commit();
        writeLog("Transaction synced successfully: " . $transaction['hash'], 'INFO');
        
        return [
            'status' => 'synced',
            'transaction_hash' => $transaction['hash'],
            'block_hash' => $block['hash']
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Sync wallet with another node
 */
function syncWalletWithNode($pdo, $blockchainManager, array $data) {
    $address = $data['address'] ?? '';
    $nodeUrl = $data['node_url'] ?? '';
    
    if (!$address || !$nodeUrl) {
        throw new Exception('Address and node_url are required');
    }
    
    writeLog("Syncing wallet $address with node $nodeUrl", 'INFO');
    
    // Get wallet transactions from blockchain
    $transactions = $blockchainManager->getWalletTransactionHistory($address);
    
    // Send to other node
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $nodeUrl . '/api/sync/wallet',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'action' => 'receive_wallet_sync',
            'address' => $address,
            'transactions' => $transactions
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Node-ID: ' . gethostname()
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        writeLog("Wallet synced successfully with node", 'INFO');
        return ['status' => 'synced', 'node_url' => $nodeUrl];
    } else {
        throw new Exception("Failed to sync with node (HTTP $httpCode)");
    }
}

/**
 * Get wallet synchronization status
 */
function getWalletSyncStatus($pdo, string $address) {
    writeLog("Getting sync status for wallet: $address", 'INFO');
    
    // Get wallet info
    $stmt = $pdo->prepare("SELECT * FROM wallets WHERE address = ?");
    $stmt->execute([$address]);
    $wallet = $stmt->fetch();
    
    // Get transaction count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as tx_count, MAX(created_at) as last_tx
        FROM transactions 
        WHERE from_address = ? OR to_address = ?
    ");
    $stmt->execute([$address, $address]);
    $txInfo = $stmt->fetch();
    
    // Get active nodes
    $stmt = $pdo->query("SELECT COUNT(*) as node_count FROM nodes WHERE status = 'active'");
    $nodeInfo = $stmt->fetch();
    
    return [
        'address' => $address,
        'exists' => $wallet !== false,
        'transaction_count' => (int)$txInfo['tx_count'],
        'last_transaction' => $txInfo['last_tx'],
        'active_nodes' => (int)$nodeInfo['node_count'],
        'sync_status' => $wallet ? 'synced' : 'not_found'
    ];
}

/**
 * Broadcast wallet update to all nodes
 */
function broadcastWalletUpdate($pdo, array $data) {
    $address = $data['address'] ?? '';
    $updateType = $data['update_type'] ?? '';
    
    if (!$address || !$updateType) {
        throw new Exception('Address and update_type are required');
    }
    
    writeLog("Broadcasting wallet update: $address ($updateType)", 'INFO');
    
    // Get active nodes
    $stmt = $pdo->query("SELECT * FROM nodes WHERE status = 'active'");
    $nodes = $stmt->fetchAll();
    
    $successCount = 0;
    foreach ($nodes as $node) {
        try {
            $url = ($node['protocol'] ?? 'https') . '://' . $node['ip_address'];
            if (!empty($node['port']) && $node['port'] != 80 && $node['port'] != 443) {
                $url .= ':' . $node['port'];
            }
            $url .= '/api/sync/wallet';
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'action' => 'receive_wallet_update',
                    'address' => $address,
                    'update_type' => $updateType,
                    'data' => $data
                ]),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Node-ID: ' . gethostname()
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $successCount++;
            }
            
        } catch (Exception $e) {
            writeLog("Failed to broadcast to node {$node['ip_address']}: " . $e->getMessage(), 'ERROR');
        }
    }
    
    writeLog("Broadcast completed: $successCount/" . count($nodes) . " nodes", 'INFO');
    
    return [
        'broadcast_sent' => count($nodes),
        'broadcast_successful' => $successCount,
        'success_rate' => count($nodes) > 0 ? round($successCount / count($nodes) * 100, 2) : 0
    ];
}

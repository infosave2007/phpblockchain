<?php
/**
 * Wallet Synchronization API
 * Handles wallet sync between blockchain nodes
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Node-ID, X-Broadcast-Signature');

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

// Load broadcast secret from config or env
function getBroadcastSecret(PDO $pdo): string {
    try {
        $stmt = $pdo->prepare("SELECT value FROM config WHERE key_name = 'network.broadcast_secret' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val) return (string)$val;
    } catch (Exception $e) {}
    $candidates = [
        $_ENV['BROADCAST_SECRET'] ?? null,
        $_ENV['NETWORK_BROADCAST_SECRET'] ?? null,
        getenv('BROADCAST_SECRET') ?: null,
        getenv('NETWORK_BROADCAST_SECRET') ?: null,
    ];
    foreach ($candidates as $c) { if ($c) return (string)$c; }
    return '';
}

// Simple file-based dedup for events (15 min TTL)
function isDuplicateEvent(string $eventId): bool {
    // Use project root as base to ensure correct storage path regardless of include context
    $base = dirname(__DIR__, 2);
    $tmpDir = $base . '/storage/tmp';
    if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0777, true); }
    $lockFile = $tmpDir . '/wallet_event_' . preg_replace('/[^a-z0-9_-]/i','', $eventId) . '.lock';
    // If exists and fresh -> duplicate
    if (file_exists($lockFile)) {
        $age = time() - (int)@filemtime($lockFile);
        if ($age < 900) { return true; }
    }
    // Touch to mark
    @file_put_contents($lockFile, (string)time());
    return false;
}

try {
    // Load environment variables (absolute paths from project root)
    require_once dirname(__DIR__, 2) . '/core/Environment/EnvironmentLoader.php';
    \Blockchain\Core\Environment\EnvironmentLoader::load(dirname(__DIR__, 2));
    
    // Get request data
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? $_GET['action'] ?? '';
    
    if (empty($action)) {
        handleError('Action parameter is required', 400);
    }
    
    writeLog("Wallet sync API called with action: $action");
    
    // Load DatabaseManager
    require_once dirname(__DIR__, 2) . '/core/Database/DatabaseManager.php';
    
    // Connect to database using DatabaseManager
    $pdo = \Blockchain\Core\Database\DatabaseManager::getConnection();
    
    // Load blockchain manager
    require_once dirname(__DIR__, 2) . '/wallet/WalletBlockchainManager.php';
    $blockchainManager = new \Blockchain\Wallet\WalletBlockchainManager($pdo, []);
    
    $result = [];
    
    switch ($action) {
        case 'receive_wallet_transaction':
            $result = receiveWalletTransaction($pdo, $input);
            break;
        case 'receive_wallet_update':
            // Verify HMAC signature (required when secret configured) and deduplicate events
            $rawBody = file_get_contents('php://input');
            $sigHeader = '';
            foreach (getallheaders() as $k => $v) {
                if (strtolower($k) === 'x-broadcast-signature') { $sigHeader = (string)$v; break; }
            }
            $secret = getBroadcastSecret($pdo);
            if ($secret) {
                if (!$sigHeader) {
                    writeLog('Missing broadcast signature while secret configured', 'WARNING');
                    handleError('Signature required', 401);
                }
                $calc = hash_hmac('sha256', $rawBody, $secret);
                $provided = '';
                if (stripos($sigHeader, 'sha256=') === 0) { $provided = substr($sigHeader, 7); } else { $provided = $sigHeader; }
                if (!hash_equals($calc, $provided)) {
                    writeLog('Invalid wallet update signature', 'WARNING');
                    handleError('Invalid signature', 401);
                }
            }

            $eventId = $input['event_id'] ?? null;
            if (!$eventId) {
                $addr = $input['address'] ?? '';
                $ut = $input['update_type'] ?? '';
                $txh = $input['data']['transaction']['hash'] ?? '';
                $ts = (string)($input['timestamp'] ?? time());
                $eventId = hash('sha256', $ut.'|'.$addr.'|'.$txh.'|'.$ts);
            }
            if (isDuplicateEvent($eventId)) {
                writeLog("Duplicate wallet event skipped: $eventId", 'INFO');
                $result = ['status' => 'duplicate', 'event_id' => $eventId];
                break;
            }

            // Minimal processing: log and optionally ensure wallet exists on create/restore
            $updateType = $input['update_type'] ?? '';
            $address = $input['address'] ?? '';
            writeLog("Received wallet event: $updateType for $address, event_id=$eventId", 'INFO');

            if (in_array($updateType, ['create_wallet','restore_wallet'], true) && $address) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO wallets (address, public_key, balance, created_at, updated_at) VALUES (?, ?, 0, NOW(), NOW()) ON DUPLICATE KEY UPDATE updated_at = NOW()");
                    $pub = $input['data']['public_key'] ?? '';
                    $stmt->execute([$address, $pub]);
                } catch (Exception $e) {
                    // Best-effort; don't fail the webhook
                    writeLog('ensure wallet on event failed: ' . $e->getMessage(), 'WARNING');
                }
            }

            $result = ['status' => 'ok', 'event_id' => $eventId];
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

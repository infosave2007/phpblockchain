<?php
// Explorer API - Public blockchain explorer endpoints

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Get the request path
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);
$path = str_replace('/api/explorer', '', $path);
$path = trim($path, '/');

// Parse query parameters
$params = $_GET;
$network = $params['network'] ?? 'mainnet';

try {
    // Load EnvironmentLoader first
    require_once '../../core/Environment/EnvironmentLoader.php';
    
    // Load DatabaseManager
    require_once '../../core/Database/DatabaseManager.php';
    
    // Connect to database using DatabaseManager
    $pdo = \Blockchain\Core\Database\DatabaseManager::getConnection();
    
    // Route the request
    switch ($path) {
        case 'stats':
            echo json_encode(getNetworkStats($pdo, $network));
            break;
            
        case 'blocks':
            $page = (int)($params['page'] ?? 0);
            $limit = min((int)($params['limit'] ?? 10), 100); // Max 100 blocks
            echo json_encode(getBlocks($pdo, $network, $page, $limit));
            break;
            
        case 'transactions':
            $page = (int)($params['page'] ?? 0);
            $limit = min((int)($params['limit'] ?? 10), 100); // Max 100 transactions
            echo json_encode(getTransactions($pdo, $network, $page, $limit));
            break;
            
        case 'block':
            $blockId = $params['id'] ?? '';
            if (empty($blockId)) {
                throw new Exception('Block ID required');
            }
            echo json_encode(getBlock($pdo, $network, $blockId));
            break;
            
        case 'transaction':
            $txId = $params['id'] ?? '';
            if (empty($txId)) {
                throw new Exception('Transaction ID required');
            }
            echo json_encode(getTransaction($pdo, $network, $txId));
            break;
            
        case 'search':
            $query = $params['q'] ?? '';
            if (empty($query)) {
                throw new Exception('Search query required');
            }
            echo json_encode(search($pdo, $network, $query));
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Error $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

function getNetworkStats(PDO $pdo, string $network): array
{
    // Get blockchain state
    $stateFile = '../../storage/state/blockchain_state.json';
    $genesisFile = '../../storage/blockchain/genesis.json';
    $chainFile = '../../storage/blockchain/chain.json';
    
    $stats = [
        'network' => $network,
        'status' => 'active',
        'current_height' => 0,
        'total_transactions' => 0,
        'total_supply' => 1000000,
        'circulating_supply' => 1000000,
        'last_block_time' => null,
        'hash_rate' => '0 H/s',
        'difficulty' => 1,
        'active_nodes' => 1,
        'consensus' => 'pos',
        'version' => '1.0.0'
    ];
    
    // Load blockchain state if available
    if (file_exists($stateFile)) {
        $state = json_decode(file_get_contents($stateFile), true);
        if ($state) {
            $stats['current_height'] = $state['current_height'] ?? 0;
            $stats['total_transactions'] = $state['total_transactions'] ?? 0;
            $stats['total_supply'] = $state['total_supply'] ?? 1000000;
            $stats['consensus'] = $state['consensus_type'] ?? 'pos';
        }
    }
    
    // Load chain data if available
    if (file_exists($chainFile)) {
        $chain = json_decode(file_get_contents($chainFile), true);
        if ($chain && is_array($chain)) {
            $stats['current_height'] = count($chain) - 1;
            
            // Get last block time
            $lastBlock = end($chain);
            if ($lastBlock && isset($lastBlock['timestamp'])) {
                $stats['last_block_time'] = $lastBlock['timestamp'];
            }
            
            // Count total transactions
            $totalTx = 0;
            foreach ($chain as $block) {
                if (isset($block['transactions']) && is_array($block['transactions'])) {
                    $totalTx += count($block['transactions']);
                }
            }
            $stats['total_transactions'] = $totalTx;
        }
    }
    
    // Try to get data from database
    try {
        // Check if blocks table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'blocks'");
        if ($stmt->rowCount() > 0) {
            // Get block count
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM blocks");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['current_height'] = max($stats['current_height'], ($result['count'] ?? 1) - 1);
            
            // Get latest block
            $stmt = $pdo->query("SELECT * FROM blocks ORDER BY height DESC LIMIT 1");
            $latestBlock = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($latestBlock) {
                $stats['last_block_time'] = (int)$latestBlock['timestamp'];
            }
        }
        
        // Check if transactions table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'transactions'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM transactions");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['total_transactions'] = max($stats['total_transactions'], $result['count'] ?? 0);
        }
    } catch (Exception $e) {
        // Database tables might not exist yet, use file-based data
    }
    
    return $stats;
}

function getBlocks(PDO $pdo, string $network, int $page, int $limit): array
{
    $blocks = [];
    $chainFile = '../../storage/blockchain/chain.json';
    
    // Try to load from chain file first
    if (file_exists($chainFile)) {
        $chain = json_decode(file_get_contents($chainFile), true);
        if ($chain && is_array($chain)) {
            // Reverse to get newest first
            $chain = array_reverse($chain);
            
            // Apply pagination
            $offset = $page * $limit;
            $blocks = array_slice($chain, $offset, $limit);
            
            // Format blocks for API
            $blocks = array_map(function($block) {
                return [
                    'index' => $block['index'] ?? 0,
                    'hash' => $block['hash'] ?? '',
                    'previous_hash' => $block['previous_hash'] ?? '',
                    'timestamp' => $block['timestamp'] ?? time(),
                    'transaction_count' => count($block['transactions'] ?? []),
                    'merkle_root' => $block['merkle_root'] ?? '',
                    'nonce' => $block['nonce'] ?? 0,
                    'difficulty' => $block['difficulty'] ?? 1,
                    'size' => strlen(json_encode($block))
                ];
            }, $blocks);
        }
    }
    
    // Try to load from database if available
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'blocks'");
        if ($stmt->rowCount() > 0) {
            $offset = $page * $limit;
            $stmt = $pdo->prepare(
                "SELECT * FROM blocks ORDER BY height DESC LIMIT ? OFFSET ?"
            );
            $stmt->execute([$limit, $offset]);
            $dbBlocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($dbBlocks)) {
                $blocks = array_map(function($block) {
                    return [
                        'index' => (int)$block['height'],
                        'hash' => $block['hash'],
                        'previous_hash' => $block['parent_hash'],
                        'timestamp' => (int)$block['timestamp'],
                        'transaction_count' => (int)$block['transactions_count'],
                        'merkle_root' => $block['merkle_root'] ?? '',
                        'nonce' => 0,
                        'difficulty' => 1,
                        'size' => strlen($block['metadata'] ?? '{}'),
                        'validator' => $block['validator'] ?? '',
                        'signature' => $block['signature'] ?? ''
                    ];
                }, $dbBlocks);
            }
        }
    } catch (Exception $e) {
        // Continue with file-based data
    }
    
    return [
        'blocks' => $blocks,
        'page' => $page,
        'limit' => $limit,
        'total' => count($blocks)
    ];
}

function getTransactions(PDO $pdo, string $network, int $page, int $limit): array
{
    $transactions = [];
    $chainFile = '../../storage/blockchain/chain.json';
    
    // Try to load from chain file first
    if (file_exists($chainFile)) {
        $chain = json_decode(file_get_contents($chainFile), true);
        if ($chain && is_array($chain)) {
            // Extract all transactions from all blocks
            $allTransactions = [];
            foreach ($chain as $block) {
                if (isset($block['transactions']) && is_array($block['transactions'])) {
                    foreach ($block['transactions'] as $tx) {
                        $tx['block_index'] = $block['index'] ?? 0;
                        $tx['block_hash'] = $block['hash'] ?? '';
                        $allTransactions[] = $tx;
                    }
                }
            }
            
            // Sort by timestamp (newest first)
            usort($allTransactions, function($a, $b) {
                return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
            });
            
            // Apply pagination
            $offset = $page * $limit;
            $transactions = array_slice($allTransactions, $offset, $limit);
            
            // Format transactions for API
            $transactions = array_map(function($tx) {
                return [
                    'hash' => $tx['hash'] ?? hash('sha256', json_encode($tx)),
                    'type' => $tx['type'] ?? 'transfer',
                    'from' => $tx['from'] ?? '',
                    'to' => $tx['to'] ?? '',
                    'amount' => $tx['amount'] ?? 0,
                    'timestamp' => $tx['timestamp'] ?? time(),
                    'block_index' => $tx['block_index'] ?? 0,
                    'block_hash' => $tx['block_hash'] ?? '',
                    'status' => 'confirmed'
                ];
            }, $transactions);
        }
    }
    
    // Try to load from database if available
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'transactions'");
        if ($stmt->rowCount() > 0) {
            $offset = $page * $limit;
            $stmt = $pdo->prepare(
                "SELECT * FROM transactions ORDER BY timestamp DESC LIMIT ? OFFSET ?"
            );
            $stmt->execute([$limit, $offset]);
            $dbTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($dbTransactions)) {
                $transactions = array_map(function($tx) {
                    return [
                        'hash' => $tx['hash'],
                        'type' => 'transfer',
                        'from' => $tx['from_address'],
                        'to' => $tx['to_address'],
                        'amount' => (float)$tx['amount'],
                        'fee' => (float)$tx['fee'],
                        'timestamp' => (int)$tx['timestamp'],
                        'block_height' => (int)$tx['block_height'],
                        'block_hash' => $tx['block_hash'],
                        'status' => $tx['status']
                    ];
                }, $dbTransactions);
            }
        }
    } catch (Exception $e) {
        // Continue with file-based data
    }
    
    return [
        'transactions' => $transactions,
        'page' => $page,
        'limit' => $limit,
        'total' => count($transactions)
    ];
}

function getBlock(PDO $pdo, string $network, string $blockId): array
{
    // Try to get block by index or hash
    $chainFile = '../../storage/blockchain/chain.json';
    
    if (file_exists($chainFile)) {
        $chain = json_decode(file_get_contents($chainFile), true);
        if ($chain && is_array($chain)) {
            foreach ($chain as $block) {
                if ((string)($block['index'] ?? '') === $blockId || 
                    ($block['hash'] ?? '') === $blockId) {
                    return [
                        'block' => $block,
                        'transaction_count' => count($block['transactions'] ?? []),
                        'size' => strlen(json_encode($block))
                    ];
                }
            }
        }
    }
    
    throw new Exception('Block not found');
}

function getTransaction(PDO $pdo, string $network, string $txId): array
{
    // Search for transaction in all blocks
    $chainFile = '../../storage/blockchain/chain.json';
    
    if (file_exists($chainFile)) {
        $chain = json_decode(file_get_contents($chainFile), true);
        if ($chain && is_array($chain)) {
            foreach ($chain as $block) {
                if (isset($block['transactions']) && is_array($block['transactions'])) {
                    foreach ($block['transactions'] as $tx) {
                        $txHash = $tx['hash'] ?? hash('sha256', json_encode($tx));
                        if ($txHash === $txId) {
                            return [
                                'transaction' => $tx,
                                'block_index' => $block['index'] ?? 0,
                                'block_hash' => $block['hash'] ?? '',
                                'confirmations' => count($chain) - ($block['index'] ?? 0)
                            ];
                        }
                    }
                }
            }
        }
    }
    
    throw new Exception('Transaction not found');
}

function search(PDO $pdo, string $network, string $query): array
{
    $results = [];
    
    // Try to find as block hash/index
    try {
        $block = getBlock($pdo, $network, $query);
        $results[] = [
            'type' => 'block',
            'data' => $block
        ];
    } catch (Exception $e) {
        // Not a block
    }
    
    // Try to find as transaction hash
    try {
        $transaction = getTransaction($pdo, $network, $query);
        $results[] = [
            'type' => 'transaction',
            'data' => $transaction
        ];
    } catch (Exception $e) {
        // Not a transaction
    }
    
    if (empty($results)) {
        throw new Exception('No results found for: ' . $query);
    }
    
    return ['results' => $results];
}
?>

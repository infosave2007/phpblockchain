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
    $debug = $_GET['debug'] ?? false;
    $debugInfo = [];
    
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
            if ($debug) {
                $debugInfo['state_file'] = 'loaded';
                $debugInfo['state_data'] = $state;
            }
        }
    } else if ($debug) {
        $debugInfo['state_file'] = 'not_found';
    }
    
    // Load chain data if available
    if (file_exists($chainFile)) {
        $chain = json_decode(file_get_contents($chainFile), true);
        if ($chain && is_array($chain)) {
            $fileHeight = count($chain) - 1;
            $stats['current_height'] = $fileHeight;
            
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
            
            if ($debug) {
                $debugInfo['chain_file'] = 'loaded';
                $debugInfo['chain_blocks_count'] = count($chain);
                $debugInfo['chain_total_tx'] = $totalTx;
                $debugInfo['chain_height'] = $fileHeight;
            }
        }
    } else if ($debug) {
        $debugInfo['chain_file'] = 'not_found';
    }
    
    // Try to get data from database
    try {
        // Check if blocks table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'blocks'");
        if ($stmt->rowCount() > 0) {
            // Get block count
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM blocks");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $dbBlockCount = $result['count'] ?? 0;
            $dbHeight = max(0, $dbBlockCount - 1);
            $stats['current_height'] = max($stats['current_height'], $dbHeight);
            
            // Get latest block
            $stmt = $pdo->query("SELECT * FROM blocks ORDER BY height DESC LIMIT 1");
            $latestBlock = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($latestBlock) {
                $stats['last_block_time'] = (int)$latestBlock['timestamp'];
            }
            
            if ($debug) {
                $debugInfo['db_blocks_table'] = 'exists';
                $debugInfo['db_blocks_count'] = $dbBlockCount;
                $debugInfo['db_height'] = $dbHeight;
                $debugInfo['db_latest_block'] = $latestBlock;
            }
        } else if ($debug) {
            $debugInfo['db_blocks_table'] = 'not_exists';
        }
        
        // Check if transactions table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'transactions'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM transactions");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $dbTxCount = $result['count'] ?? 0;
            $stats['total_transactions'] = max($stats['total_transactions'], $dbTxCount);
            
            if ($debug) {
                $debugInfo['db_transactions_table'] = 'exists';
                $debugInfo['db_transactions_count'] = $dbTxCount;
                
                // Get sample transactions for debug
                $stmt = $pdo->query("SELECT id, hash, status, block_height FROM transactions ORDER BY id DESC LIMIT 3");
                $debugInfo['db_sample_transactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } else if ($debug) {
            $debugInfo['db_transactions_table'] = 'not_exists';
        }
    } catch (Exception $e) {
        // Database tables might not exist yet, use file-based data
        if ($debug) {
            $debugInfo['db_error'] = $e->getMessage();
        }
    }
    
    if ($debug) {
        $stats['_debug'] = $debugInfo;
    }
    
    return $stats;
}

function getBlocks(PDO $pdo, string $network, int $page, int $limit): array
{
    $blocks = [];
    $debug = $_GET['debug'] ?? false;
    
    // Try to load from database first (prioritize database over file)
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'blocks'");
        if ($stmt->rowCount() > 0) {
            $offset = $page * $limit;
            $stmt = $pdo->prepare(
                "SELECT * FROM blocks ORDER BY height DESC LIMIT ? OFFSET ?"
            );
            $stmt->bindParam(1, $limit, PDO::PARAM_INT);
            $stmt->bindParam(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $dbBlocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($debug) {
                error_log("DEBUG: Found " . count($dbBlocks) . " blocks in database");
                error_log("DEBUG: SQL query: SELECT * FROM blocks ORDER BY height DESC LIMIT $limit OFFSET $offset");
                error_log("DEBUG: Raw database result: " . json_encode($dbBlocks));
            }
            
            if (!empty($dbBlocks)) {
                if ($debug) {
                    error_log("DEBUG: Using database blocks, count: " . count($dbBlocks));
                }
                
                $blocks = array_map(function($block) {
                    return [
                        'index' => (int)$block['height'],
                        'hash' => $block['hash'],
                        'previous_hash' => $block['parent_hash'],
                        'timestamp' => (int)$block['timestamp'],
                        'transaction_count' => (int)$block['transactions_count'],
                        'merkle_root' => $block['merkle_root'] ?? '',
                        'nonce' => 0, // Not stored in DB yet
                        'difficulty' => 1, // Default difficulty
                        'size' => strlen(json_encode($block))
                    ];
                }, $dbBlocks);
                
                // Get total count for pagination
                $stmt = $pdo->query("SELECT COUNT(*) FROM blocks");
                $total = (int)$stmt->fetchColumn();
                
                return [
                    'blocks' => $blocks,
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    '_debug' => $debug ? [
                        'source' => 'database',
                        'db_blocks_found' => count($dbBlocks),
                        'db_total_count' => $total
                    ] : null
                ];
            }
        }
    } catch (Exception $e) {
        // If database fails, try file fallback
        if ($debug) {
            error_log("DEBUG: Database blocks query failed: " . $e->getMessage() . " - falling back to file");
        }
        error_log("Database blocks query failed: " . $e->getMessage());
    }
    
    // Fallback to chain file if database is empty or fails
    if ($debug) {
        error_log("DEBUG: Using file fallback for blocks");
    }
    $chainFile = '../../storage/blockchain/chain.json';
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
    
    return [
        'blocks' => $blocks,
        'page' => $page,
        'limit' => $limit,
        'total' => count($blocks),
        '_debug' => $debug ? [
            'source' => 'file',
            'file_blocks_found' => count($blocks),
            'chain_file_exists' => file_exists($chainFile)
        ] : null
    ];
}

function getTransactions(PDO $pdo, string $network, int $page, int $limit): array
{
    $transactions = [];
    $debug = $_GET['debug'] ?? false;
    
    // Try to load from database first (prioritize database over file)
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'transactions'");
        if ($stmt->rowCount() > 0) {
            $offset = $page * $limit;
            $stmt = $pdo->prepare(
                "SELECT t.*, 
                        CASE 
                            WHEN t.data IS NOT NULL AND JSON_VALID(t.data) THEN JSON_EXTRACT(t.data, '$.action')
                            ELSE 'transfer'
                        END as tx_type
                 FROM transactions t 
                 ORDER BY t.timestamp DESC, t.id DESC 
                 LIMIT ? OFFSET ?"
            );
            $stmt->bindParam(1, $limit, PDO::PARAM_INT);
            $stmt->bindParam(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $dbTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($debug) {
                error_log("DEBUG: Found " . count($dbTransactions) . " transactions in database");
                error_log("DEBUG: SQL query: transactions with limit $limit offset $offset");
                error_log("DEBUG: Raw database result: " . json_encode($dbTransactions));
            }
            
            if (!empty($dbTransactions)) {
                if ($debug) {
                    error_log("DEBUG: Using database transactions, count: " . count($dbTransactions));
                }
                
                $transactions = array_map(function($tx) {
                    // Parse transaction type from data field
                    $txType = 'transfer';
                    if (!empty($tx['data'])) {
                        $data = json_decode($tx['data'], true);
                        if (isset($data['action'])) {
                            $txType = $data['action'];
                        }
                    }
                    
                    // Handle special addresses
                    if ($tx['from_address'] === 'genesis') {
                        $txType = 'genesis';
                    } elseif ($tx['to_address'] === 'genesis_address') {
                        $txType = 'genesis';
                    } elseif ($tx['to_address'] === 'staking_contract') {
                        $txType = 'stake';
                    } elseif ($tx['to_address'] === 'validator_registry') {
                        $txType = 'register_validator';
                    } elseif ($tx['to_address'] === 'node_registry') {
                        $txType = 'register_node';
                    }
                    
                    return [
                        'hash' => $tx['hash'],
                        'type' => $txType,
                        'from' => $tx['from_address'],
                        'to' => $tx['to_address'],
                        'amount' => (float)$tx['amount'],
                        'timestamp' => (int)$tx['timestamp'],
                        'block_index' => (int)$tx['block_height'],
                        'block_hash' => $tx['block_hash'],
                        'status' => $tx['status'] === 'confirmed' ? 'confirmed' : 'pending'
                    ];
                }, $dbTransactions);
                
                // Get total count for pagination
                $stmt = $pdo->query("SELECT COUNT(*) FROM transactions");
                $total = (int)$stmt->fetchColumn();
                
                return [
                    'transactions' => $transactions,
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    '_debug' => $debug ? [
                        'source' => 'database',
                        'db_transactions_found' => count($dbTransactions),
                        'db_total_count' => $total,
                        'db_sample_data' => array_slice($dbTransactions, 0, 2)
                    ] : null
                ];
            }
        }
    } catch (Exception $e) {
        // If database fails, try file fallback
        if ($debug) {
            error_log("DEBUG: Database transactions query failed: " . $e->getMessage() . " - falling back to file");
        }
        error_log("Database transactions query failed: " . $e->getMessage());
    }
    
    // Fallback to chain file if database is empty or fails
    if ($debug) {
        error_log("DEBUG: Using file fallback for transactions");
    }
    $chainFile = '../../storage/blockchain/chain.json';
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
    
    return [
        'transactions' => $transactions,
        'page' => $page,
        'limit' => $limit,
        'total' => count($transactions),
        '_debug' => $debug ? [
            'source' => 'file',
            'file_transactions_found' => count($transactions),
            'chain_file_exists' => file_exists($chainFile)
        ] : null
    ];
}

function getBlock(PDO $pdo, string $network, string $blockId): array
{
    $debug = $_GET['debug'] ?? false;
    
    // First try to get block from database
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'blocks'");
        if ($stmt->rowCount() > 0) {
            // Search by hash or height
            if (is_numeric($blockId)) {
                // Search by height (index)
                $stmt = $pdo->prepare("SELECT * FROM blocks WHERE height = ? LIMIT 1");
                $stmt->execute([(int)$blockId]);
            } else {
                // Search by hash
                $stmt = $pdo->prepare("SELECT * FROM blocks WHERE hash = ? LIMIT 1");
                $stmt->execute([$blockId]);
            }
            $dbBlock = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($debug) {
                error_log("DEBUG: Block search - blockId: $blockId, is_numeric: " . (is_numeric($blockId) ? 'true' : 'false'));
                error_log("DEBUG: Block SQL result: " . json_encode($dbBlock));
            }
            
            if ($dbBlock) {
                // Get transactions for this block
                $stmt = $pdo->prepare(
                    "SELECT * FROM transactions WHERE block_hash = ? ORDER BY timestamp ASC"
                );
                $stmt->execute([$dbBlock['hash']]);
                $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Format transactions
                $formattedTransactions = array_map(function($tx) {
                    // Parse transaction type from data field
                    $txType = 'transfer';
                    if (!empty($tx['data'])) {
                        $data = json_decode($tx['data'], true);
                        if (isset($data['action'])) {
                            $txType = $data['action'];
                        }
                    }
                    
                    // Handle special addresses
                    if ($tx['from_address'] === 'genesis') {
                        $txType = 'genesis';
                    } elseif ($tx['to_address'] === 'genesis_address') {
                        $txType = 'genesis';
                    } elseif ($tx['to_address'] === 'staking_contract') {
                        $txType = 'stake';
                    } elseif ($tx['to_address'] === 'validator_registry') {
                        $txType = 'register_validator';
                    } elseif ($tx['to_address'] === 'node_registry') {
                        $txType = 'register_node';
                    }
                    
                    return [
                        'hash' => $tx['hash'],
                        'type' => $txType,
                        'from' => $tx['from_address'],
                        'to' => $tx['to_address'],
                        'amount' => (float)$tx['amount'],
                        'timestamp' => (int)$tx['timestamp'],
                        'status' => $tx['status'] === 'confirmed' ? 'confirmed' : 'pending'
                    ];
                }, $transactions);
                
                return [
                    'block' => [
                        'index' => (int)$dbBlock['height'],
                        'hash' => $dbBlock['hash'],
                        'previous_hash' => $dbBlock['parent_hash'],
                        'timestamp' => (int)$dbBlock['timestamp'],
                        'validator' => $dbBlock['validator'],
                        'signature' => $dbBlock['signature'],
                        'merkle_root' => $dbBlock['merkle_root'],
                        'transactions' => $formattedTransactions,
                        'metadata' => json_decode($dbBlock['metadata'] ?? '{}', true)
                    ],
                    'transaction_count' => count($formattedTransactions),
                    'size' => strlen(json_encode($dbBlock)),
                    '_debug' => $debug ? ['source' => 'database'] : null
                ];
            }
        }
    } catch (Exception $e) {
        if ($debug) {
            error_log("DEBUG: Database block query failed: " . $e->getMessage());
        }
    }
    
    // Fallback to chain file if not found in database
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
                        'size' => strlen(json_encode($block)),
                        '_debug' => $debug ? ['source' => 'file'] : null
                    ];
                }
            }
        }
    }
    
    throw new Exception('Block not found');
}

function getTransaction(PDO $pdo, string $network, string $txId): array
{
    $debug = $_GET['debug'] ?? false;
    
    // First try to get transaction from database
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'transactions'");
        if ($stmt->rowCount() > 0) {
            // Search for transaction by hash
            $stmt = $pdo->prepare(
                "SELECT t.*, b.height as block_height_num FROM transactions t 
                 LEFT JOIN blocks b ON t.block_hash = b.hash 
                 WHERE t.hash = ? LIMIT 1"
            );
            $stmt->execute([$txId]);
            $dbTx = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($dbTx) {
                // Parse transaction type from data field
                $txType = 'transfer';
                if (!empty($dbTx['data'])) {
                    $data = json_decode($dbTx['data'], true);
                    if (isset($data['action'])) {
                        $txType = $data['action'];
                    }
                }
                
                // Handle special addresses
                if ($dbTx['from_address'] === 'genesis') {
                    $txType = 'genesis';
                } elseif ($dbTx['to_address'] === 'genesis_address') {
                    $txType = 'genesis';
                } elseif ($dbTx['to_address'] === 'staking_contract') {
                    $txType = 'stake';
                } elseif ($dbTx['to_address'] === 'validator_registry') {
                    $txType = 'register_validator';
                } elseif ($dbTx['to_address'] === 'node_registry') {
                    $txType = 'register_node';
                }
                
                // Get total blocks count for confirmations
                $stmt = $pdo->query("SELECT COUNT(*) FROM blocks");
                $totalBlocks = (int)$stmt->fetchColumn();
                $confirmations = max(0, $totalBlocks - (int)$dbTx['block_height']);
                
                return [
                    'transaction' => [
                        'hash' => $dbTx['hash'],
                        'type' => $txType,
                        'from' => $dbTx['from_address'],
                        'to' => $dbTx['to_address'],
                        'amount' => (float)$dbTx['amount'],
                        'fee' => (float)$dbTx['fee'],
                        'timestamp' => (int)$dbTx['timestamp'],
                        'status' => $dbTx['status'] === 'confirmed' ? 'confirmed' : 'pending',
                        'data' => $dbTx['data'] ? json_decode($dbTx['data'], true) : null,
                        'signature' => $dbTx['signature']
                    ],
                    'block_index' => (int)$dbTx['block_height'],
                    'block_hash' => $dbTx['block_hash'],
                    'confirmations' => $confirmations,
                    '_debug' => $debug ? ['source' => 'database'] : null
                ];
            }
        }
    } catch (Exception $e) {
        if ($debug) {
            error_log("DEBUG: Database transaction query failed: " . $e->getMessage());
        }
    }
    
    // Fallback to chain file if not found in database
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
                                'confirmations' => count($chain) - ($block['index'] ?? 0),
                                '_debug' => $debug ? ['source' => 'file'] : null
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

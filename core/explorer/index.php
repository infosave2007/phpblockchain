<?php
// Explorer API - Public blockchain explorer endpoints

// Enable gzip compression for better performance
if (extension_loaded('zlib') && !ob_get_length()) {
    ini_set('zlib.output_compression', 'On');
    ini_set('zlib.output_compression_level', 6);
    
    // Set compression headers
    if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false) {
        header('Content-Encoding: gzip');
    }
}

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
$action = $params['action'] ?? '';

try {
    // Load EnvironmentLoader first
    require_once '../../core/Environment/EnvironmentLoader.php';
    
    // Load DatabaseManager
    require_once '../../core/Database/DatabaseManager.php';
    
    // Connect to database using DatabaseManager
    $pdo = \Blockchain\Core\Database\DatabaseManager::getConnection();
    
    // Handle action-based requests (legacy compatibility)
    if (!empty($action)) {
        switch ($action) {
            case 'get_wallet_balance':
                $address = $params['address'] ?? '';
                if (empty($address)) {
                    throw new Exception('Wallet address required');
                }
                echo json_encode(getWalletBalance($pdo, $network, $address));
                exit;
                
            case 'get_wallet_node_info':
                $address = $params['address'] ?? '';
                if (empty($address)) {
                    throw new Exception('Wallet address required');
                }
                echo json_encode(getWalletNodeInfo($pdo, $network, $address));
                exit;
                
            case 'get_block':
                $blockId = $params['block_id'] ?? '';
                if ($blockId === '') {
                    throw new Exception('Block ID required');
                }
                echo json_encode(getBlockById($pdo, $network, $blockId));
                exit;
                
            case 'get_network_stats':
                echo json_encode(getNetworkStats($pdo, $network));
                exit;
                
            case 'get_network_config':
                echo json_encode(getNetworkConfig($pdo, $network));
                exit;
                
            case 'verify_wallet_ownership':
                $address = $params['address'] ?? '';
                $signature = $params['signature'] ?? '';
                $message = $params['message'] ?? '';
                if (empty($address) || empty($signature) || empty($message)) {
                    throw new Exception('Address, signature and message are required for wallet verification');
                }
                echo json_encode(verifyWalletOwnership($address, $signature, $message));
                exit;
                
            case 'get_nodes_list':
                echo json_encode(getNodesListAPI($pdo, $network));
                exit;
                
            case 'get_validators_list':
                echo json_encode(getValidatorsList($pdo, $network));
                exit;
                
            case 'get_staking_data':
                echo json_encode(getStakingData($pdo, $network));
                exit;
                
            case 'get_all_blocks':
                $page = (int)($params['page'] ?? 0);
                $limit = min((int)($params['limit'] ?? 100), 1000); // Max 1000 blocks for sync
                echo json_encode(getAllBlocks($pdo, $network, $page, $limit));
                exit;
                
            case 'get_all_transactions':
                $page = (int)($params['page'] ?? 0);
                $limit = min((int)($params['limit'] ?? 100), 1000); // Max 1000 transactions for sync
                echo json_encode(getAllTransactions($pdo, $network, $page, $limit));
                exit;
                
            case 'get_smart_contracts':
                $page = (int)($params['page'] ?? 0);
                $limit = min((int)($params['limit'] ?? 100), 1000); // Max 1000 contracts for sync
                echo json_encode(getSmartContracts($pdo, $network, $page, $limit));
                exit;
            
            case 'get_smart_contract':
                $address = $params['address'] ?? '';
                if (empty($address)) {
                    throw new Exception('Contract address required');
                }
                echo json_encode(getSmartContractByAddress($pdo, $address));
                exit;
                
            case 'get_staking_records':
                $page = (int)($params['page'] ?? 0);
                $limit = min((int)($params['limit'] ?? 100), 1000); // Max 1000 staking records for sync
                echo json_encode(getStakingRecords($pdo, $network, $page, $limit));
                exit;
                
            case 'get_nodes':
                echo json_encode(getNodesListAPI($pdo, $network));
                exit;
                
            case 'get_validators':
                echo json_encode(getValidatorsList($pdo, $network));
                exit;
                
            case 'get_wallets':
                $page = intval($params['page'] ?? 0);
                $limit = intval($params['limit'] ?? 100);
                echo json_encode(getWalletsList($pdo, $network, $page, $limit));
                exit;
                
            case 'get_mempool':
                $limit = intval($params['limit'] ?? 500);
                echo json_encode(getMempoolTransactions($pdo, $network, $limit));
                exit;
                
            case 'stats':
                echo json_encode(getNetworkStats($pdo, $network));
                exit;
                
            default:
                throw new Exception('Unknown action: ' . $action);
        }
    }
    
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
            
        case 'nodes':
            echo json_encode(getNodesListAPI($pdo, $network));
            break;
            
        case 'validators':
            echo json_encode(getValidatorsList($pdo, $network));
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
        'active_nodes' => 0,
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
        
        // Get active nodes count
        $stmt = $pdo->query("SHOW TABLES LIKE 'nodes'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM nodes WHERE status = 'active'");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $activeNodesCount = $result['count'] ?? 0;
            $stats['active_nodes'] = $activeNodesCount;
            
            if ($debug) {
                $debugInfo['db_nodes_table'] = 'exists';
                $debugInfo['db_active_nodes_count'] = $activeNodesCount;
            }
        } else {
            // Default to 1 if nodes table doesn't exist
            $stats['active_nodes'] = 1;
            if ($debug) {
                $debugInfo['db_nodes_table'] = 'not_exists';
            }
        }
        
        // Calculate hash rate (blocks per hour for PoS)
        $stmt = $pdo->query("SHOW TABLES LIKE 'blocks'");
        if ($stmt->rowCount() > 0) {
            // Get blocks from last hour
            $oneHourAgo = time() - 3600;
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM blocks WHERE timestamp > ?");
            $stmt->execute([$oneHourAgo]);
            $blocksLastHour = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            
            // For PoS, we show "blocks per hour" instead of traditional hash rate
            if ($blocksLastHour > 0) {
                $stats['hash_rate'] = $blocksLastHour . ' H';
            } else {
                // Fallback: get average from last 24 hours
                $twentyFourHoursAgo = time() - (24 * 3600);
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM blocks WHERE timestamp > ?");
                $stmt->execute([$twentyFourHoursAgo]);
                $blocksLast24h = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                $averagePerHour = $blocksLast24h > 0 ? round($blocksLast24h / 24, 1) : 0;
                $stats['hash_rate'] = $averagePerHour . ' H';
            }
            
            if ($debug) {
                $debugInfo['hash_rate_calculation'] = [
                    'blocks_last_hour' => $blocksLastHour,
                    'one_hour_ago_timestamp' => $oneHourAgo
                ];
            }
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
                "SELECT * FROM blocks ORDER BY height DESC LIMIT ?, ?"
            );
            $stmt->bindValue(1, $offset, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
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
                 LIMIT ?, ?"
            );
            $stmt->bindValue(1, $offset, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
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

/**
 * Get wallet balance and info
 */
function getWalletBalance(PDO $pdo, string $network, string $address): array {
    $walletInfo = [
        'address' => $address,
        'balance' => 0,
        'stake' => 0,
        'transactions_count' => 0,
        'last_activity' => null
    ];
    
    try {
        // Check if wallets table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'wallets'");
        if ($stmt->rowCount() > 0) {
            // Get wallet from database
            $stmt = $pdo->prepare("SELECT * FROM wallets WHERE address = ?");
            $stmt->execute([$address]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($wallet) {
                $walletInfo['balance'] = (float)($wallet['balance'] ?? 0);
                $walletInfo['last_activity'] = $wallet['updated_at'] ?? $wallet['created_at'];
            }
        }
        
        // Check staking amount
        $stmt = $pdo->query("SHOW TABLES LIKE 'staking'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT SUM(amount) as total_stake FROM staking WHERE staker = ? AND status = 'active'");
            $stmt->execute([$address]);
            $stakeResult = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($stakeResult) {
                $walletInfo['stake'] = (float)($stakeResult['total_stake'] ?? 0);
            }
        }
        
        // Count transactions
        $stmt = $pdo->query("SHOW TABLES LIKE 'transactions'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM transactions WHERE from_address = ? OR to_address = ?");
            $stmt->execute([$address, $address]);
            $txResult = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($txResult) {
                $walletInfo['transactions_count'] = (int)($txResult['count'] ?? 0);
            }
        }
        
        return [
            'success' => true,
            'data' => $walletInfo
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => $walletInfo
        ];
    }
}

/**
 * Get wallet node binding information
 */
function getWalletNodeInfo(PDO $pdo, string $network, string $address): array {
    $nodeInfo = [
        'address' => $address,
        'bound_to_node' => false,
        'node_id' => null,
        'bound_since' => null,
        'last_activity' => null,
        'status' => 'unknown'
    ];
    
    try {
        // Check if nodes table exists and has wallet binding info
        $stmt = $pdo->query("SHOW TABLES LIKE 'nodes'");
        if ($stmt->rowCount() > 0) {
            // Check if there's a wallet_address column in nodes table
            $stmt = $pdo->query("SHOW COLUMNS FROM nodes LIKE 'wallet_address'");
            if ($stmt->rowCount() > 0) {
                // Get node binding info
                $stmt = $pdo->prepare("
                    SELECT id, domain, url, status, wallet_address, created_at, last_seen 
                    FROM nodes 
                    WHERE wallet_address = ? 
                    ORDER BY last_seen DESC 
                    LIMIT 1
                ");
                $stmt->execute([$address]);
                $node = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($node) {
                    $nodeInfo['bound_to_node'] = true;
                    $nodeInfo['node_id'] = $node['id'];
                    $nodeInfo['bound_since'] = $node['created_at'];
                    $nodeInfo['last_activity'] = $node['last_seen'];
                    $nodeInfo['status'] = $node['status'] ?? 'unknown';
                    $nodeInfo['node_domain'] = $node['domain'] ?? null;
                    $nodeInfo['node_url'] = $node['url'] ?? null;
                }
            }
        }
        
        // Alternative: check wallet_nodes table if it exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'wallet_nodes'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT wn.*, n.domain, n.url, n.status 
                FROM wallet_nodes wn 
                LEFT JOIN nodes n ON wn.node_id = n.id 
                WHERE wn.wallet_address = ? 
                ORDER BY wn.created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$address]);
            $binding = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($binding) {
                $nodeInfo['bound_to_node'] = true;
                $nodeInfo['node_id'] = $binding['node_id'];
                $nodeInfo['bound_since'] = $binding['created_at'];
                $nodeInfo['last_activity'] = $binding['updated_at'] ?? $binding['created_at'];
                $nodeInfo['status'] = $binding['status'] ?? 'active';
                $nodeInfo['node_domain'] = $binding['domain'] ?? null;
                $nodeInfo['node_url'] = $binding['url'] ?? null;
            }
        }
        
        return [
            'success' => true,
            'data' => $nodeInfo
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => $nodeInfo
        ];
    }
}

/**
 * Get block by ID (height or hash)
 */
function getBlockById(PDO $pdo, string $network, string $blockId): array {
    try {
        // Try to get block from database first
        $stmt = $pdo->query("SHOW TABLES LIKE 'blocks'");
        if ($stmt->rowCount() > 0) {
            // Check if blockId is numeric (height) or hash
            if (is_numeric($blockId)) {
                $stmt = $pdo->prepare("SELECT * FROM blocks WHERE height = ? ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([(int)$blockId]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM blocks WHERE hash = ? LIMIT 1");
                $stmt->execute([$blockId]);
            }
            
            $block = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($block) {
                // Parse data field if it's JSON
                if (isset($block['data']) && is_string($block['data'])) {
                    $blockData = json_decode($block['data'], true);
                    if ($blockData !== null) {
                        $block['data'] = $blockData;
                    }
                }
                
                return [
                    'success' => true,
                    'data' => $block
                ];
            }
        }
        
        // Try to get block from file storage
        $chainFile = '../../storage/blockchain/chain.json';
        if (file_exists($chainFile)) {
            $chain = json_decode(file_get_contents($chainFile), true);
            
            if (is_array($chain)) {
                foreach ($chain as $block) {
                    $matchesHeight = is_numeric($blockId) && ($block['index'] ?? $block['height'] ?? -1) == $blockId;
                    $matchesHash = !is_numeric($blockId) && ($block['hash'] ?? '') === $blockId;
                    
                    if ($matchesHeight || $matchesHash) {
                        return [
                            'success' => true,
                            'data' => $block
                        ];
                    }
                }
            }
        }
        
        throw new Exception('Block not found');
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Verify wallet ownership by signature
 */
function verifyWalletOwnership(string $address, string $signature, string $message): array {
    try {
        // Load required crypto libraries
        require_once '../../vendor/autoload.php';
        require_once '../../core/Cryptography/EllipticCurve.php';
        require_once '../../core/Cryptography/Signature.php';
        require_once '../../core/Cryptography/KeyPair.php';
        require_once '../../core/Cryptography/Mnemonic.php';
        
        $isValid = false;
        $verificationMethod = 'none';
        $details = [];
        
        // Basic format validation
        $signatureFormatValid = !empty($signature) && ctype_xdigit(str_replace('0x', '', $signature));
        $addressFormatValid = !empty($address) && strlen($address) === 42 && substr($address, 0, 2) === '0x';
        
        if (!$signatureFormatValid || !$addressFormatValid) {
            return [
                'success' => true,
                'data' => [
                    'verified' => false,
                    'address' => $address,
                    'message' => $message,
                    'signature_format_valid' => $signatureFormatValid,
                    'address_format_valid' => $addressFormatValid,
                    'error' => 'Invalid signature or address format'
                ]
            ];
        }
        
        // Clean signature (remove 0x prefix if present)
        $cleanSignature = str_replace('0x', '', $signature);
        
        // Try real cryptographic verification
        try {
            // Method 1: Try to recover public key from signature and verify
            if (strlen($cleanSignature) === 128) { // Standard ECDSA signature (r+s format)
                $verificationMethod = 'ecdsa_signature_verification';
                
                // Try to recover public key from signature
                $recoveredPublicKey = \Blockchain\Core\Cryptography\Signature::recoverPublicKey($message, $cleanSignature);
                
                if ($recoveredPublicKey) {
                    // Generate address from recovered public key
                    $recoveredAddress = generateAddressFromPublicKey($recoveredPublicKey);
                    
                    // Check if recovered address matches provided address
                    $isValid = strtolower($address) === strtolower($recoveredAddress);
                    
                    $details = [
                        'verification_method' => $verificationMethod,
                        'recovered_public_key' => $recoveredPublicKey,
                        'recovered_address' => $recoveredAddress,
                        'address_match' => $isValid
                    ];
                    
                } else {
                    // Public key recovery failed, try direct verification with signature
                    // This requires knowing the public key beforehand
                    $isValid = false;
                    $verificationMethod = 'public_key_recovery_failed';
                    
                    $details = [
                        'verification_method' => $verificationMethod,
                        'error' => 'Could not recover public key from signature'
                    ];
                }
            }
            // Method 2: Check if signature is actually a private key (64 hex chars)
            else if (strlen($cleanSignature) === 64) { // This might be a private key
                try {
                    // Generate public key and address from potential private key
                    $testKeyPair = \Blockchain\Core\Cryptography\KeyPair::fromPrivateKey($cleanSignature);
                    $testAddress = $testKeyPair->getAddress();
                    
                    if (strtolower($address) === strtolower($testAddress)) {
                        // This signature is actually a private key that matches the address
                        // Generate a proper signature to prove ownership
                        $realSignature = \Blockchain\Core\Cryptography\Signature::sign($message, $cleanSignature);
                        
                        $isValid = true;
                        $verificationMethod = 'private_key_ownership_verification';
                        
                        $details = [
                            'verification_method' => $verificationMethod,
                            'ownership_proven' => true,
                            'verified_address' => $testAddress,
                            'generated_signature' => $realSignature,
                            'public_key' => $testKeyPair->getPublicKey()
                        ];
                    } else {
                        $isValid = false;
                        $verificationMethod = 'private_key_address_mismatch';
                        
                        $details = [
                            'verification_method' => $verificationMethod,
                            'provided_address' => $address,
                            'generated_address' => $testAddress,
                            'ownership_verified' => false
                        ];
                    }
                } catch (Exception $e) {
                    $isValid = false;
                    $verificationMethod = 'invalid_private_key';
                    
                    $details = [
                        'verification_method' => $verificationMethod,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            // Method 3: Full signature with recovery ID
            else if (strlen($cleanSignature) === 130) { // Full signature with recovery ID
                $r = substr($cleanSignature, 0, 64);
                $s = substr($cleanSignature, 64, 64);
                $v = substr($cleanSignature, 128, 2);
                
                // Create standard r+s signature format
                $rsSignature = $r . $s;
                
                // Try to recover public key from signature
                $recoveredPublicKey = \Blockchain\Core\Cryptography\Signature::recoverPublicKey($message, $rsSignature);
                
                if ($recoveredPublicKey) {
                    $recoveredAddress = generateAddressFromPublicKey($recoveredPublicKey);
                    $isValid = strtolower($address) === strtolower($recoveredAddress);
                    $verificationMethod = 'ecdsa_with_recovery_id';
                    
                    $details = [
                        'verification_method' => $verificationMethod,
                        'signature_components' => [
                            'r' => $r,
                            's' => $s,
                            'v' => $v
                        ],
                        'recovered_public_key' => $recoveredPublicKey,
                        'recovered_address' => $recoveredAddress,
                        'address_match' => $isValid
                    ];
                } else {
                    $isValid = false;
                    $verificationMethod = 'signature_recovery_failed_with_v';
                    
                    $details = [
                        'verification_method' => $verificationMethod,
                        'error' => 'Could not recover public key from signature with recovery ID'
                    ];
                }
            }
            
            // Method 4: Invalid signature length
            else {
                return [
                    'success' => true,
                    'data' => [
                        'verified' => false,
                        'address' => $address,
                        'message' => $message,
                        'signature_format_valid' => false,
                        'address_format_valid' => $addressFormatValid,
                        'error' => 'Invalid signature length. Expected 64 (private key), 128 (r+s) or 130 (r+s+v) hex characters, got ' . strlen($cleanSignature)
                    ]
                ];
            }
            
        } catch (Exception $e) {
            // Cryptographic verification failed
            $isValid = false;
            $verificationMethod = 'crypto_error';
            $details['crypto_error'] = $e->getMessage();
        }
        
        return [
            'success' => true,
            'data' => [
                'verified' => $isValid,
                'address' => $address,
                'message' => $message,
                'signature_format_valid' => $signatureFormatValid,
                'address_format_valid' => $addressFormatValid,
                'verification_method' => $verificationMethod,
                'details' => $details
            ]
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => [
                'verified' => false,
                'address' => $address,
                'verification_method' => 'error'
            ]
        ];
    }
}

/**
 * Generate address from public key (helper function)
 */
function generateAddressFromPublicKey(string $publicKeyHex): string {
    // For compressed public key, decompress first
    if (strlen($publicKeyHex) === 66) { // Compressed public key
        $publicKeyPoint = \Blockchain\Core\Cryptography\EllipticCurve::decompressPublicKey($publicKeyHex);
        $uncompressedKey = '04' . str_pad($publicKeyPoint['x'], 64, '0', STR_PAD_LEFT) . 
                          str_pad($publicKeyPoint['y'], 64, '0', STR_PAD_LEFT);
    } else {
        $uncompressedKey = $publicKeyHex;
    }
    
    // Remove '04' prefix if present
    if (substr($uncompressedKey, 0, 2) === '04') {
        $uncompressedKey = substr($uncompressedKey, 2);
    }
    
    // Calculate hash (fallback using SHA-256)
    $hash = hash('sha256', hex2bin($uncompressedKey));
    
    // Take last 20 bytes (40 hex characters) as address
    $address = substr($hash, -40);
    
    return '0x' . $address;
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

/**
 * Get network configuration for node setup
 */
function getNetworkConfig(PDO $pdo, string $network): array {
    $config = [
        'network' => $network,
        'min_stake_amount' => 1000, // Default
        'network_name' => null,
        'token_symbol' => null,
        'token_name' => null,
        'consensus_type' => 'pos',
        'total_supply' => null,
        'initial_supply' => null,
        'decimals' => 18,
        'chain_id' => 1,
        'protocol_version' => '1.0.0',
        'block_time' => 10,
        'reward_rate' => 0.05,
        'circulating_supply' => null,
        'staking_enabled' => true,
        'validator_count' => 0,
        'active_nodes' => 0
    ];
    
    try {
        // Get network configuration from config table (primary source)
        $stmt = $pdo->query("SHOW TABLES LIKE 'config'");
        if ($stmt->rowCount() > 0) {
            // Fetch all relevant config values
            $configKeys = [
                'network.name' => 'network_name',
                'network.token_symbol' => 'token_symbol', 
                'network.token_name' => 'token_name',
                'network.initial_supply' => 'initial_supply',
                'network.decimals' => 'decimals',
                'network.chain_id' => 'chain_id',
                'network.protocol_version' => 'protocol_version',
                'consensus.min_stake' => 'min_stake_amount',
                'consensus.algorithm' => 'consensus_type',
                'consensus.reward_rate' => 'reward_rate',
                'blockchain.block_time' => 'block_time'
            ];
            
            foreach ($configKeys as $dbKey => $configField) {
                $stmt = $pdo->prepare("SELECT value FROM config WHERE key_name = ?");
                $stmt->execute([$dbKey]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result && !empty($result['value'])) {
                    $value = $result['value'];
                    
                    // Convert to appropriate type
                    if (in_array($configField, ['initial_supply', 'decimals', 'chain_id', 'min_stake_amount', 'block_time'])) {
                        $config[$configField] = (int)$value;
                    } elseif (in_array($configField, ['reward_rate'])) {
                        $config[$configField] = (float)$value;
                    } else {
                        $config[$configField] = $value;
                    }
                }
            }
            
            // Set total_supply same as initial_supply for consistency
            if ($config['initial_supply']) {
                $config['total_supply'] = $config['initial_supply'];
            }
        }
        
        // Get network configuration from blockchain state (fallback)
        $stateFile = '../../storage/state/blockchain_state.json';
        if (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true);
            if ($state) {
                // Only override if not already set from config table
                if (!$config['network_name'] && isset($state['network_name'])) {
                    $config['network_name'] = $state['network_name'];
                }
                if (!$config['token_symbol'] && isset($state['token_symbol'])) {
                    $config['token_symbol'] = $state['token_symbol'];
                }
                if (!$config['total_supply'] && isset($state['total_supply'])) {
                    $config['total_supply'] = (int)$state['total_supply'];
                }
                if (isset($state['consensus_type'])) {
                    $config['consensus_type'] = $state['consensus_type'];
                }
            }
        }
        
        // Get configuration from application config if available (fallback)
        $configFile = '../../config/config.php';
        if (file_exists($configFile)) {
            try {
                $appConfig = require_once $configFile;
                // Get global blockchain config from config array
                if (isset($appConfig['blockchain'])) {
                    $blockchainConfig = $appConfig['blockchain'];

                    // Only override if not already set
                    if (!$config['network_name'] && isset($blockchainConfig['network_name'])) {
                        $config['network_name'] = $blockchainConfig['network_name'];
                    }
                    if (!$config['token_symbol'] && isset($blockchainConfig['token_symbol'])) {
                        $config['token_symbol'] = $blockchainConfig['token_symbol'];
                    }
                    if (!$config['min_stake_amount'] && isset($blockchainConfig['min_stake_amount'])) {
                        $config['min_stake_amount'] = (int)$blockchainConfig['min_stake_amount'];
                    }
                    if (!$config['total_supply'] && isset($blockchainConfig['total_supply'])) {
                        $config['total_supply'] = (int)$blockchainConfig['total_supply'];
                    }
                }
            } catch (Exception $e) {
                // Config file might not be properly formatted
            }
        }
        
        // Get installation config if available (fallback)
        $installConfigFile = '../../config/install_config.json';
        if (file_exists($installConfigFile)) {
            $installConfig = json_decode(file_get_contents($installConfigFile), true);
            if ($installConfig) {
                // Only override if not already set
                if (!$config['network_name'] && isset($installConfig['network_name'])) {
                    $config['network_name'] = $installConfig['network_name'];
                }
                if (!$config['token_symbol'] && isset($installConfig['token_symbol'])) {
                    $config['token_symbol'] = $installConfig['token_symbol'];
                }
                if (!$config['min_stake_amount'] && isset($installConfig['min_stake_amount'])) {
                    $config['min_stake_amount'] = (int)$installConfig['min_stake_amount'];
                }
                if (isset($installConfig['network_min_stake_amount'])) {
                    $config['min_stake_amount'] = (int)$installConfig['network_min_stake_amount'];
                }
                if (!$config['initial_supply'] && isset($installConfig['initial_supply'])) {
                    $config['initial_supply'] = (int)$installConfig['initial_supply'];
                    $config['total_supply'] = $config['initial_supply'];
                }
            }
        }
        
        // Get live statistics from database
        try {
            // Count validators
            $stmt = $pdo->query("SHOW TABLES LIKE 'validators'");
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM validators WHERE status = 'active'");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $config['validator_count'] = (int)($result['count'] ?? 0);
            }
            
            // Count active nodes
            $stmt = $pdo->query("SHOW TABLES LIKE 'nodes'");
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM nodes WHERE status = 'active'");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $config['active_nodes'] = (int)($result['count'] ?? 0);
            }
            
            // Get circulating supply from wallets
            $stmt = $pdo->query("SHOW TABLES LIKE 'wallets'");
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->query("SELECT SUM(balance) as total FROM wallets");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $config['circulating_supply'] = (int)($result['total'] ?? 0);
            }
        } catch (Exception $e) {
            // Database queries might fail, use defaults
        }
        
        return [
            'success' => true,
            'data' => $config
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => $config // Return defaults on error
        ];
    }
}

/**
 * Get list of active nodes for API
 */
function getNodesListAPI(PDO $pdo, string $network): array {
    try {
        // Check if nodes table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'nodes'");
        if ($stmt->rowCount() === 0) {
            return [
                'success' => true,
                'data' => [],
                'message' => 'Nodes table not found'
            ];
        }
        
        // Check table structure to determine which fields are available
        $stmt = $pdo->query("SHOW COLUMNS FROM nodes");
        $columnsResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $columns = array_column($columnsResult, 'Field');
        $hasNodeId = in_array('node_id', $columns);
        $hasAddress = in_array('address', $columns);
        $hasDomain = in_array('domain', $columns);
        $hasIpAddress = in_array('ip_address', $columns);
        $hasStakeAmount = in_array('stake_amount', $columns);
        
        // Build query based on available columns
        $selectFields = ['id', 'status', 'last_seen', 'created_at', 'updated_at'];
        
        if ($hasNodeId) $selectFields[] = 'node_id';
        if ($hasAddress) $selectFields[] = 'address';
        if ($hasDomain) $selectFields[] = 'domain';
        if ($hasIpAddress) $selectFields[] = 'ip_address';
        if (in_array('port', $columns)) $selectFields[] = 'port';
        if (in_array('protocol', $columns)) $selectFields[] = 'protocol';
        if ($hasStakeAmount) $selectFields[] = 'stake_amount';
        if (in_array('public_key', $columns)) $selectFields[] = 'public_key';
        if (in_array('node_type', $columns)) $selectFields[] = 'node_type';
        if (in_array('blocks_synced', $columns)) $selectFields[] = 'blocks_synced';
        if (in_array('ping_time', $columns)) $selectFields[] = 'ping_time';
        if (in_array('reputation_score', $columns)) $selectFields[] = 'reputation_score';
        if (in_array('metadata', $columns)) $selectFields[] = 'metadata';
        
        $selectClause = implode(', ', $selectFields);
        
        // Get active nodes
        $stmt = $pdo->prepare("SELECT $selectClause FROM nodes WHERE status = 'active' ORDER BY last_seen DESC");
        $stmt->execute();
        $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format nodes for consistent API response
        $formattedNodes = [];
        foreach ($nodes as $node) {
            $formattedNode = [
                'id' => $node['id'],
                'status' => $node['status'],
                'last_seen' => $node['last_seen'],
                'created_at' => $node['created_at'] ?? null,
                'updated_at' => $node['updated_at'] ?? null
            ];
            
            // Add identification fields
            if ($hasAddress) $formattedNode['address'] = $node['address'];
            if ($hasDomain) $formattedNode['domain'] = $node['domain'];
            if ($hasNodeId) $formattedNode['node_id'] = $node['node_id'];
            if ($hasIpAddress) $formattedNode['ip_address'] = $node['ip_address'];
            
            // Add connection fields
            if (isset($node['port'])) $formattedNode['port'] = (int)$node['port'];
            if (isset($node['protocol'])) $formattedNode['protocol'] = $node['protocol'];
            
            // Add stake and validation fields
            if ($hasStakeAmount) $formattedNode['stake_amount'] = (float)$node['stake_amount'];
            if (isset($node['public_key'])) $formattedNode['public_key'] = $node['public_key'];
            if (isset($node['node_type'])) $formattedNode['node_type'] = $node['node_type'];
            
            // Add performance fields
            if (isset($node['blocks_synced'])) $formattedNode['blocks_synced'] = (int)$node['blocks_synced'];
            if (isset($node['ping_time'])) $formattedNode['ping_time'] = (int)$node['ping_time'];
            if (isset($node['reputation_score'])) $formattedNode['reputation_score'] = (int)$node['reputation_score'];
            if (isset($node['metadata'])) $formattedNode['metadata'] = json_decode($node['metadata'], true);
            
            $formattedNodes[] = $formattedNode;
        }
        
        return [
            'success' => true,
            'data' => $formattedNodes,
            'total' => count($formattedNodes),
            'message' => 'Active nodes retrieved successfully'
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => []
        ];
    }
}

/**
 * Get list of validators
 */
function getValidatorsList(PDO $pdo, string $network): array {
    try {
        // Check if validators table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'validators'");
        if ($stmt->rowCount() === 0) {
            return [
                'success' => true,
                'data' => [],
                'message' => 'Validators table not found'
            ];
        }
        
        // Get validators
        $stmt = $pdo->prepare("SELECT address, stake, delegated_stake, commission_rate, status, blocks_produced, blocks_missed, last_active_block, metadata FROM validators ORDER BY stake DESC, status ASC");
        $stmt->execute();
        $validators = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'data' => $validators,
            'total' => count($validators)
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => []
        ];
    }
}

/**
 * Get staking data
 */
function getStakingData(PDO $pdo, string $network): array {
    try {
        // Check if staking table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'staking'");
        if ($stmt->rowCount() === 0) {
            return [
                'success' => true,
                'data' => [],
                'message' => 'Staking table not found'
            ];
        }
        
        // Get staking records
        $stmt = $pdo->prepare("SELECT validator, staker, amount, reward_rate, start_block, end_block, status, rewards_earned, last_reward_block FROM staking ORDER BY amount DESC, start_block DESC");
        $stmt->execute();
        $stakingRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'data' => $stakingRecords,
            'total' => count($stakingRecords)
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => []
        ];
    }
}

/**
 * Get list of wallets for synchronization
 */
function getWalletsList(PDO $pdo, string $network, int $page = 0, int $limit = 100): array {
    try {
        // Check if wallets table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'wallets'");
        if ($stmt->rowCount() === 0) {
            return [
                'success' => true,
                'data' => [],
                'message' => 'Wallets table not found'
            ];
        }
        
        $offset = $page * $limit;
        
        // Get wallets with pagination
        $stmt = $pdo->prepare("SELECT address, public_key, balance, staked_balance, nonce, created_at, updated_at FROM wallets ORDER BY balance DESC, created_at DESC LIMIT ?, ?");
        $stmt->bindValue(1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $wallets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count
        $stmt = $pdo->query("SELECT COUNT(*) FROM wallets");
        $total = $stmt->fetchColumn();
        
        return [
            'success' => true,
            'data' => $wallets,
            'total' => intval($total),
            'page' => $page,
            'limit' => $limit,
            'has_more' => ($offset + count($wallets)) < $total
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => []
        ];
    }
}

/**
 * Get mempool transactions for synchronization
 */
function getMempoolTransactions(PDO $pdo, string $network, int $limit = 500): array {
    try {
        // Check if mempool table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'mempool'");
        if ($stmt->rowCount() === 0) {
            return [
                'success' => true,
                'data' => [],
                'message' => 'Mempool table not found'
            ];
        }
        
        // Get recent mempool transactions, ordered by priority and creation time
        $stmt = $pdo->prepare("
            SELECT tx_hash, from_address, to_address, amount, fee, gas_price, gas_limit, nonce, 
                   data, signature, priority_score, status, created_at, expires_at, node_id
            FROM mempool 
            WHERE status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY priority_score DESC, created_at DESC 
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $mempool = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total pending transactions count
        $stmt = $pdo->query("SELECT COUNT(*) FROM mempool WHERE status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())");
        $total = $stmt->fetchColumn();
        
        return [
            'success' => true,
            'data' => $mempool,
            'total' => intval($total),
            'limit' => $limit
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => []
        ];
    }
}

/**
 * Get all blocks for synchronization
 */
function getAllBlocks(PDO $pdo, string $network, int $page = 0, int $limit = 100): array {
    try {
        // Check if blocks table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'blocks'");
        if ($stmt->rowCount() === 0) {
            return [
                'success' => true,
                'data' => [],
                'message' => 'Blocks table not found'
            ];
        }
        
        $offset = $page * $limit;
        
        // Get blocks with pagination
        $stmt = $pdo->prepare("SELECT hash, parent_hash, height, timestamp, validator, signature, merkle_root, transactions_count, metadata FROM blocks ORDER BY height ASC LIMIT ?, ?");
        $stmt->bindValue(1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count
        $countStmt = $pdo->query("SELECT COUNT(*) as total FROM blocks");
        $totalResult = $countStmt->fetch(PDO::FETCH_ASSOC);
        $total = (int)($totalResult['total'] ?? 0);
        
        return [
            'success' => true,
            'data' => $blocks,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'has_more' => ($offset + count($blocks)) < $total
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => []
        ];
    }
}

/**
 * Get all transactions for synchronization
 */
function getAllTransactions(PDO $pdo, string $network, int $page = 0, int $limit = 100): array {
    try {
        // Check if transactions table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'transactions'");
        if ($stmt->rowCount() === 0) {
            return [
                'success' => true,
                'data' => [],
                'message' => 'Transactions table not found'
            ];
        }
        
        $offset = $page * $limit;
        
        // Get transactions with pagination
        $stmt = $pdo->prepare("SELECT hash, block_hash, block_height, from_address, to_address, amount, fee, gas_limit, gas_used, gas_price, nonce, data, signature, status, timestamp FROM transactions ORDER BY block_height ASC, timestamp ASC LIMIT ?, ?");
        $stmt->bindValue(1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count
        $countStmt = $pdo->query("SELECT COUNT(*) as total FROM transactions");
        $totalResult = $countStmt->fetch(PDO::FETCH_ASSOC);
        $total = (int)($totalResult['total'] ?? 0);
        
        return [
            'success' => true,
            'data' => $transactions,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'has_more' => ($offset + count($transactions)) < $total
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => []
        ];
    }
}

/**
 * Get smart contracts for synchronization
 */
function getSmartContracts(PDO $pdo, string $network, int $page = 0, int $limit = 100): array {
    try {
        // Check if smart_contracts table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'smart_contracts'");
        if ($stmt->rowCount() === 0) {
            return [
                'success' => true,
                'data' => [],
                'message' => 'Smart contracts table not found',
                'pagination' => ['page' => $page, 'limit' => $limit, 'total' => 0, 'has_more' => false]
            ];
        }
        
        // Get total count
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM smart_contracts");
        $stmt->execute();
        $totalCount = (int)$stmt->fetchColumn();
        
        // Calculate offset
        $offset = $page * $limit;
        
        // Get smart contracts with pagination
        $stmt = $pdo->prepare("SELECT address, creator, name, version, bytecode, abi, source_code, deployment_tx, deployment_block, gas_used, status, storage, metadata FROM smart_contracts ORDER BY deployment_block ASC LIMIT ?, ?");
        $stmt->bindValue(1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON fields
        foreach ($contracts as &$contract) {
            if (isset($contract['abi']) && is_string($contract['abi'])) {
                $contract['abi'] = json_decode($contract['abi'], true) ?: [];
            }
            if (isset($contract['storage']) && is_string($contract['storage'])) {
                $contract['storage'] = json_decode($contract['storage'], true) ?: [];
            }
            if (isset($contract['metadata']) && is_string($contract['metadata'])) {
                $contract['metadata'] = json_decode($contract['metadata'], true) ?: [];
            }
        }
        
        return [
            'success' => true,
            'data' => $contracts,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalCount,
                'has_more' => ($offset + count($contracts)) < $totalCount,
                'returned' => count($contracts)
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => []
        ];
    }
}

/**
 * Get a single smart contract by address
 */
function getSmartContractByAddress(PDO $pdo, string $address): array {
    try {
        // Check if smart_contracts table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'smart_contracts'");
        if ($stmt->rowCount() === 0) {
            return [
                'success' => false,
                'error' => 'Smart contracts table not found'
            ];
        }

        $stmt = $pdo->prepare("SELECT address, creator, name, version, bytecode, abi, source_code, deployment_tx, deployment_block, gas_used, status, storage, metadata FROM smart_contracts WHERE address = ? LIMIT 1");
        $stmt->execute([$address]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contract) {
            return [
                'success' => false,
                'error' => 'Contract not found'
            ];
        }

        // Parse JSON fields
        if (isset($contract['abi']) && is_string($contract['abi'])) {
            $decoded = json_decode($contract['abi'], true);
            if ($decoded !== null) { $contract['abi'] = $decoded; }
        }
        if (isset($contract['storage']) && is_string($contract['storage'])) {
            $decoded = json_decode($contract['storage'], true);
            if ($decoded !== null) { $contract['storage'] = $decoded; }
        }
        if (isset($contract['metadata']) && is_string($contract['metadata'])) {
            $decoded = json_decode($contract['metadata'], true);
            if ($decoded !== null) { $contract['metadata'] = $decoded; }
        }

        return [
            'success' => true,
            'data' => $contract
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get staking records for synchronization
 */
function getStakingRecords(PDO $pdo, string $network, int $page = 0, int $limit = 100): array {
    try {
        // Check if staking table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'staking'");
        if ($stmt->rowCount() === 0) {
            return [
                'success' => true,
                'data' => [],
                'message' => 'Staking table not found',
                'pagination' => ['page' => $page, 'limit' => $limit, 'total' => 0, 'has_more' => false]
            ];
        }
        
        // Get total count - ONLY active/pending stakes (exclude withdrawn/completed to prevent double-withdrawal)
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM staking WHERE status NOT IN ('withdrawn', 'completed')");
        $stmt->execute();
        $totalCount = (int)$stmt->fetchColumn();
        
        // Calculate offset
        $offset = $page * $limit;
        
        // Get staking records with pagination - CRITICAL: exclude withdrawn/completed to prevent sync from restoring them
        $stmt = $pdo->prepare("SELECT validator, staker, amount, reward_rate, start_block, end_block, status, rewards_earned, last_reward_block FROM staking WHERE status NOT IN ('withdrawn', 'completed') ORDER BY start_block ASC LIMIT ?, ?");
        $stmt->bindValue(1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $stakingRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'data' => $stakingRecords,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalCount,
                'has_more' => ($offset + count($stakingRecords)) < $totalCount,
                'returned' => count($stakingRecords)
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => [],
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => 0, 'has_more' => false]
        ];
    }
}
?>

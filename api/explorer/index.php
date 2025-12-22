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

// Basic protection for shared hosting: block abusive User-Agent and apply simple rate limiting per IP
try {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (stripos($ua, 'NetworkSyncManager') !== false) {
        http_response_code(429);
        header('Retry-After: 600'); // 10 minutes
        echo json_encode(['success' => false, 'error' => 'Rate limited: NetworkSyncManager blocked on shared hosting']);
        exit;
    }

    // Simple per-IP rate limiting (60 requests per minute)
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateDir = __DIR__ . '/../../storage/tmp/rate';
    if (!is_dir($rateDir)) {
        @mkdir($rateDir, 0755, true);
    }
    $key = preg_replace('/[^0-9A-Fa-f:\.]/', '_', $ip);
    $rateFile = $rateDir . '/explorer_' . $key . '.json';
    $now = time();
    $window = 60; // seconds
    $limit = 60;  // requests per window
    $data = ['start' => $now, 'count' => 0];
    if (file_exists($rateFile)) {
        $json = @file_get_contents($rateFile);
        if ($json !== false) {
            $parsed = json_decode($json, true);
            if (is_array($parsed) && isset($parsed['start'], $parsed['count'])) {
                $data = $parsed;
            }
        }
    }
    if (($now - (int)$data['start']) >= $window) {
        $data['start'] = $now;
        $data['count'] = 0;
    }
    $data['count']++;
    @file_put_contents($rateFile, json_encode($data), LOCK_EX);
    if ($data['count'] > $limit) {
        http_response_code(429);
        header('Retry-After: 60');
        echo json_encode(['success' => false, 'error' => 'Too Many Requests', 'retry_after' => 60]);
        exit;
    }
} catch (Throwable $t) {
    // Fail-open: if rate limiter errors, proceed without blocking
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
            
            case 'get_blocks_range':
                $start = (int)($params['start'] ?? 0);
                $end = (int)($params['end'] ?? $start);
                echo json_encode(getBlocksRange($pdo, $network, $start, $end));
                exit;
            
            case 'get_block_headers':
                $start = (int)($params['start'] ?? 0);
                $end = (int)($params['end'] ?? $start);
                echo json_encode(getBlockHeaders($pdo, $network, $start, $end));
                exit;

            case 'get_block_hashes_range':
                $start = (int)($params['start'] ?? 0);
                $end = (int)($params['end'] ?? $start);
                echo json_encode(getBlockHashesRange($pdo, $network, $start, $end));
                exit;

            case 'get_tip_hashes':
                // Lightweight: return up to N hashes going backwards from current tip by offset
                $offset = (int)($params['offset'] ?? 0); // how many blocks down from tip to start
                $count = min((int)($params['count'] ?? 100), 2000); // cap to 2000
                echo json_encode(getTipHashes($pdo, $network, $offset, $count));
                exit;

            case 'consensus_report':
                // Compare tip windows across active nodes and provide mismatch ratio
                $count = min((int)($params['count'] ?? 50), 500);
                echo json_encode(getConsensusTipReport($pdo, $network, $count));
                exit;

            case 'height_monitor':
                // Monitor blockchain heights across network nodes
                echo json_encode(getHeightMonitorReport($pdo, $network));
                exit;

            case 'sync_status':
                // Get detailed synchronization status
                echo json_encode(getSyncStatus($pdo, $network));
                exit;

            case 'has_state_snapshot':
                $height = (int)($params['height'] ?? 0);
                echo json_encode(hasStateSnapshot($network, $height));
                exit;

            case 'get_state_snapshot':
                $height = (int)($params['height'] ?? 0);
                echo json_encode(getStateSnapshot($network, $height));
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
            $query = $params['query'] ?? $params['q'] ?? '';
            if (empty($query)) {
                throw new Exception('Search query required');
            }
            
            error_log("Search request: query='$query', network='$network'");
            $result = search($pdo, $network, $query);
            error_log("Search result: " . json_encode($result));
            
            echo json_encode($result);
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
                    'success' => true,
                    'data' => [
                        'height' => (int)$dbBlock['height'],
                        'hash' => $dbBlock['hash'],
                        'parent_hash' => $dbBlock['parent_hash'],
                        'timestamp' => (int)$dbBlock['timestamp'],
                        'validator' => $dbBlock['validator'],
                        'signature' => $dbBlock['signature'],
                        'merkle_root' => $dbBlock['merkle_root'],
                        'transactions' => $formattedTransactions,
                        'transaction_count' => count($formattedTransactions),
                        'metadata' => json_decode($dbBlock['metadata'] ?? '{}', true)
                    ]
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
                        'success' => true,
                        'data' => [
                            'height' => (int)($block['index'] ?? $block['height'] ?? 0),
                            'hash' => $block['hash'] ?? '',
                            'parent_hash' => $block['previousHash'] ?? $block['parent_hash'] ?? '',
                            'timestamp' => (int)($block['timestamp'] ?? time()),
                            'validator' => $block['validator'] ?? '',
                            'signature' => $block['signature'] ?? '',
                            'merkle_root' => $block['merkleRoot'] ?? $block['merkle_root'] ?? '',
                            'transactions' => $block['transactions'] ?? [],
                            'transaction_count' => count($block['transactions'] ?? [])
                        ]
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
                    'success' => true,
                    'data' => [
                        'hash' => $dbTx['hash'],
                        'type' => $txType,
                        'from_address' => $dbTx['from_address'],
                        'to_address' => $dbTx['to_address'],
                        'amount' => (float)$dbTx['amount'],
                        'fee' => (float)$dbTx['fee'],
                        'timestamp' => (int)$dbTx['timestamp'],
                        'status' => $dbTx['status'] === 'confirmed' ? 'confirmed' : 'pending',
                        'data' => $dbTx['data'] ? json_decode($dbTx['data'], true) : null,
                        'signature' => $dbTx['signature'],
                        'block_height' => (int)$dbTx['block_height'],
                        'block_hash' => $dbTx['block_hash']
                    ]
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
                                'success' => true,
                                'data' => [
                                    'hash' => $tx['hash'] ?? $txHash,
                                    'type' => $tx['type'] ?? 'transfer',
                                    'from_address' => $tx['from'] ?? $tx['from_address'] ?? '',
                                    'to_address' => $tx['to'] ?? $tx['to_address'] ?? '',
                                    'amount' => (float)($tx['amount'] ?? 0),
                                    'fee' => (float)($tx['fee'] ?? 0),
                                    'timestamp' => (int)($tx['timestamp'] ?? time()),
                                    'status' => 'confirmed',
                                    'block_height' => (int)($block['index'] ?? $block['height'] ?? 0),
                                    'block_hash' => $block['hash'] ?? ''
                                ]
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
    error_log("Search function called with query: '$query'");
    
    // Try to find as block hash/index
    try {
        error_log("Trying to find as block...");
        $block = getBlock($pdo, $network, $query);
        error_log("Block result: " . json_encode($block));
        
        if ($block && $block['success']) {
            error_log("Found block, returning result");
            return [
                'type' => 'block',
                'result' => $block['data']
            ];
        }
    } catch (Exception $e) {
        error_log("Block search failed: " . $e->getMessage());
        // Not a block
    }
    
    // Try to find as transaction hash
    try {
        error_log("Trying to find as transaction...");
        $transaction = getTransaction($pdo, $network, $query);
        error_log("Transaction result: " . json_encode($transaction));
        
        if ($transaction && $transaction['success']) {
            error_log("Found transaction, returning result");
            return [
                'type' => 'transaction',
                'result' => $transaction['data']
            ];
        }
    } catch (Exception $e) {
        error_log("Transaction search failed: " . $e->getMessage());
        // Not a transaction
    }
    
    // Try to find as address
    try {
        error_log("Trying to find as address...");
        $address = getAddressInfo($pdo, $network, $query);
        error_log("Address result: " . json_encode($address));
        
        if ($address && $address['success']) {
            error_log("Found address, returning result");
            return [
                'type' => 'address',
                'result' => $address['data']
            ];
        }
    } catch (Exception $e) {
        error_log("Address search failed: " . $e->getMessage());
        // Not an address
    }
    
    error_log("No results found for query: '$query'");
    throw new Exception('No results found for: ' . $query);
}

/**
 * Get address information including balance and transaction count
 */
function getAddressInfo(PDO $pdo, string $network, string $address): array {
    try {
        // Normalize address
        $address = strtolower(trim($address));
        if (!str_starts_with($address, '0x')) {
            $address = '0x' . $address;
        }
        
        // Get address balance and transaction count
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN to_address = ? AND status = 'confirmed' THEN amount ELSE 0 END) as received,
                SUM(CASE WHEN from_address = ? AND status = 'confirmed' THEN amount + fee ELSE 0 END) as sent,
                COUNT(*) as tx_count
            FROM transactions 
            WHERE (from_address = ? OR to_address = ?) AND status = 'confirmed'
        ");
        $stmt->execute([$address, $address, $address, $address]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $balance = ($stats['received'] ?? 0) - ($stats['sent'] ?? 0);
        $txCount = $stats['tx_count'] ?? 0;
        
        // Get recent transactions for this address
        $stmt = $pdo->prepare("
            SELECT * FROM transactions 
            WHERE (from_address = ? OR to_address = ?) AND status = 'confirmed'
            ORDER BY timestamp DESC 
            LIMIT 10
        ");
        $stmt->execute([$address, $address]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'data' => [
                'address' => $address,
                'balance' => $balance,
                'transaction_count' => $txCount,
                'sent_count' => count(array_filter($transactions, fn($tx) => $tx['from_address'] === $address)),
                'received_count' => count(array_filter($transactions, fn($tx) => $tx['to_address'] === $address)),
                'transactions' => $transactions
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
                require_once $configFile;
                // Get global blockchain config
                if (defined('BLOCKCHAIN_CONFIG')) {
                    $blockchainConfig = constant('BLOCKCHAIN_CONFIG');
                    
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
        
        // Get blocks with pagination (newest first)
        $stmt = $pdo->prepare("SELECT hash, parent_hash, height, timestamp, validator, signature, merkle_root, transactions_count, metadata FROM blocks ORDER BY height DESC LIMIT ?, ?");
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
        
    // Get transactions with pagination (newest-first)
    $stmt = $pdo->prepare("SELECT hash, block_hash, block_height, from_address, to_address, amount, fee, gas_limit, gas_used, gas_price, nonce, data, signature, status, timestamp FROM transactions ORDER BY timestamp DESC, block_height DESC LIMIT ?, ?");
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
 * Stubs for state snapshot availability and retrieval.
 * Return no snapshots to force SyncManager to fallback without errors.
 */
function hasStateSnapshot(string $network, int $height): array {
    return [
        'success' => true,
        'data' => [
            'exists' => false,
            'height' => $height
        ]
    ];
}

function getStateSnapshot(string $network, int $height): array {
    return [
        'success' => false,
        'error' => 'State snapshots are not available on this node',
        'data' => [
            'height' => $height
        ]
    ];
}

/**
 * Get blocks in an inclusive height range [start, end]
 * Returns database-backed data when available, falling back to chain.json.
 */
function getBlocksRange(PDO $pdo, string $network, int $start, int $end): array {
    try {
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        // Safety cap to avoid huge responses
        $maxRange = 500;
        if ($end - $start + 1 > $maxRange) {
            $end = $start + $maxRange - 1;
        }

        // Prefer database if table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'blocks'");
        if ($stmt->rowCount() > 0) {
            $q = $pdo->prepare("SELECT hash, parent_hash, height, timestamp, validator, signature, merkle_root, transactions_count, metadata FROM blocks WHERE height BETWEEN ? AND ? ORDER BY height ASC");
            $q->execute([$start, $end]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);

            $blocks = array_map(function($b) {
                return [
                    'height' => (int)$b['height'],
                    'index' => (int)$b['height'],
                    'hash' => (string)$b['hash'],
                    'parent_hash' => (string)$b['parent_hash'],
                    'previous_hash' => (string)$b['parent_hash'],
                    'timestamp' => (int)$b['timestamp'],
                    'validator' => $b['validator'],
                    'signature' => $b['signature'],
                    'merkle_root' => $b['merkle_root'] ?? '',
                    'transactions_count' => (int)($b['transactions_count'] ?? 0),
                    'metadata' => $b['metadata'] ? (json_decode($b['metadata'], true) ?: []) : []
                ];
            }, $rows);

            return [
                'success' => true,
                'data' => [
                    'blocks' => $blocks,
                    'range' => ['start' => $start, 'end' => $end]
                ]
            ];
        }

        // Fallback to file storage
        $chainFile = '../../storage/blockchain/chain.json';
        $blocks = [];
        if (file_exists($chainFile)) {
            $chain = json_decode(file_get_contents($chainFile), true);
            if (is_array($chain)) {
                foreach ($chain as $blk) {
                    $h = (int)($blk['index'] ?? $blk['height'] ?? -1);
                    if ($h >= $start && $h <= $end) {
                        $blocks[] = [
                            'height' => $h,
                            'index' => $h,
                            'hash' => $blk['hash'] ?? '',
                            'parent_hash' => $blk['previousHash'] ?? $blk['parent_hash'] ?? ($blk['previous_hash'] ?? ''),
                            'previous_hash' => $blk['previousHash'] ?? $blk['parent_hash'] ?? ($blk['previous_hash'] ?? ''),
                            'timestamp' => (int)($blk['timestamp'] ?? time()),
                            'validator' => $blk['validator'] ?? null,
                            'signature' => $blk['signature'] ?? null,
                            'merkle_root' => $blk['merkleRoot'] ?? ($blk['merkle_root'] ?? ''),
                            'transactions_count' => isset($blk['transactions']) && is_array($blk['transactions']) ? count($blk['transactions']) : 0,
                            'metadata' => $blk['metadata'] ?? []
                        ];
                    }
                }
                usort($blocks, fn($a, $b) => $a['height'] <=> $b['height']);
            }
        }

        return [
            'success' => true,
            'data' => [
                'blocks' => $blocks,
                'range' => ['start' => $start, 'end' => $end]
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => [ 'blocks' => [], 'range' => ['start' => $start, 'end' => $end] ]
        ];
    }
}

/**
 * Get only block headers for an inclusive height range [start, end]
 */
function getBlockHeaders(PDO $pdo, string $network, int $start, int $end): array {
    try {
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        $maxRange = 1000;
        if ($end - $start + 1 > $maxRange) {
            $end = $start + $maxRange - 1;
        }

        $headers = [];
        // Prefer database if available
        $stmt = $pdo->query("SHOW TABLES LIKE 'blocks'");
        if ($stmt->rowCount() > 0) {
            $q = $pdo->prepare("SELECT hash, parent_hash, height, timestamp, merkle_root, validator, signature FROM blocks WHERE height BETWEEN ? AND ? ORDER BY height ASC");
            $q->execute([$start, $end]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $headers[] = [
                    'height' => (int)$r['height'],
                    'index' => (int)$r['height'],
                    'hash' => (string)$r['hash'],
                    'previous_hash' => (string)$r['parent_hash'],
                    'timestamp' => (int)$r['timestamp'],
                    'merkle_root' => $r['merkle_root'] ?? '',
                    'validator' => $r['validator'] ?? null,
                    'signature' => $r['signature'] ?? null,
                ];
            }

            return [
                'success' => true,
                'data' => [
                    'headers' => $headers,
                    'range' => ['start' => $start, 'end' => $end]
                ]
            ];
        }

        // File fallback
        $chainFile = '../../storage/blockchain/chain.json';
        if (file_exists($chainFile)) {
            $chain = json_decode(file_get_contents($chainFile), true);
            if (is_array($chain)) {
                foreach ($chain as $blk) {
                    $h = (int)($blk['index'] ?? $blk['height'] ?? -1);
                    if ($h >= $start && $h <= $end) {
                        $headers[] = [
                            'height' => $h,
                            'index' => $h,
                            'hash' => $blk['hash'] ?? '',
                            'previous_hash' => $blk['previousHash'] ?? $blk['parent_hash'] ?? ($blk['previous_hash'] ?? ''),
                            'timestamp' => (int)($blk['timestamp'] ?? time()),
                            'merkle_root' => $blk['merkleRoot'] ?? ($blk['merkle_root'] ?? ''),
                            'validator' => $blk['validator'] ?? null,
                            'signature' => $blk['signature'] ?? null,
                        ];
                    }
                }
                usort($headers, fn($a, $b) => $a['height'] <=> $b['height']);
            }
        }

        return [
            'success' => true,
            'data' => [
                'headers' => $headers,
                'range' => ['start' => $start, 'end' => $end]
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => [ 'headers' => [], 'range' => ['start' => $start, 'end' => $end] ]
        ];
    }
}

/**
 * Get only block hashes for an inclusive height range [start, end]
 */
function getBlockHashesRange(PDO $pdo, string $network, int $start, int $end): array {
    try {
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        // Keep it lightweight; allow up to 2000 heights per call
        $maxRange = 2000;
        if ($end - $start + 1 > $maxRange) {
            $end = $start + $maxRange - 1;
        }

        $hashes = [];
        // Prefer database if available
        $stmt = $pdo->query("SHOW TABLES LIKE 'blocks'");
        if ($stmt->rowCount() > 0) {
            $q = $pdo->prepare("SELECT height, hash FROM blocks WHERE height BETWEEN ? AND ? ORDER BY height ASC");
            $q->execute([$start, $end]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $hashes[] = (string)$r['hash'];
            }

            return [
                'success' => true,
                'data' => [
                    'hashes' => $hashes,
                    'range' => ['start' => $start, 'end' => $end]
                ]
            ];
        }

        // File fallback
        $chainFile = '../../storage/blockchain/chain.json';
        if (file_exists($chainFile)) {
            $chain = json_decode(file_get_contents($chainFile), true);
            if (is_array($chain)) {
                foreach ($chain as $blk) {
                    $h = (int)($blk['index'] ?? $blk['height'] ?? -1);
                    if ($h >= $start && $h <= $end) {
                        $hashes[$h] = $blk['hash'] ?? '';
                    }
                }
                // Ensure ascending order by height
                ksort($hashes, SORT_NUMERIC);
                $hashes = array_values($hashes);
            }
        }

        return [
            'success' => true,
            'data' => [
                'hashes' => $hashes,
                'range' => ['start' => $start, 'end' => $end]
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => [ 'hashes' => [], 'range' => ['start' => $start, 'end' => $end] ]
        ];
    }
}

/**
 * Get block hashes from tip with offset/count (tip-offset .. tip-offset-count+1)
 */
function getTipHashes(PDO $pdo, string $network, int $offset, int $count): array {
    try {
        if ($offset < 0) { $offset = 0; }
        if ($count < 1) { $count = 1; }
        if ($count > 2000) { $count = 2000; }

        // Determine current tip height
        $tip = 0;
        $stmt = $pdo->query("SHOW TABLES LIKE 'blocks'");
        if ($stmt->rowCount() > 0) {
            $q = $pdo->query("SELECT MAX(height) as h FROM blocks");
            $row = $q->fetch(PDO::FETCH_ASSOC);
            $tip = (int)($row['h'] ?? 0);
        } else {
            $chainFile = '../../storage/blockchain/chain.json';
            if (file_exists($chainFile)) {
                $chain = json_decode(file_get_contents($chainFile), true);
                if (is_array($chain)) { $tip = max(0, count($chain) - 1); }
            }
        }

        $startHeight = max(0, $tip - $offset);
        $endHeight = max(0, $startHeight - $count + 1);

        $hashesByH = [];
        // Prefer DB
        $stmt = $pdo->query("SHOW TABLES LIKE 'blocks'");
        if ($stmt->rowCount() > 0) {
            $q = $pdo->prepare("SELECT height, hash FROM blocks WHERE height BETWEEN ? AND ? ORDER BY height DESC");
            $q->execute([$endHeight, $startHeight]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) { $hashesByH[(int)$r['height']] = (string)$r['hash']; }
        } else {
            $chainFile = '../../storage/blockchain/chain.json';
            if (file_exists($chainFile)) {
                $chain = json_decode(file_get_contents($chainFile), true);
                if (is_array($chain)) {
                    foreach ($chain as $blk) {
                        $h = (int)($blk['index'] ?? $blk['height'] ?? -1);
                        if ($h >= $endHeight && $h <= $startHeight) { $hashesByH[$h] = $blk['hash'] ?? ''; }
                    }
                }
            }
        }

        // Produce descending by height
        krsort($hashesByH, SORT_NUMERIC);
        $hashes = array_values($hashesByH);

        return [
            'success' => true,
            'data' => [
                'tip' => $tip,
                'offset' => $offset,
                'count' => $count,
                'hashes' => $hashes,
                'range' => ['from' => $startHeight, 'to' => $endHeight]
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => [ 'hashes' => [], 'tip' => null, 'offset' => $offset, 'count' => $count ]
        ];
    }
}

/**
 * Build a lightweight consensus report by comparing last N hashes across active nodes
 */
function getConsensusTipReport(PDO $pdo, string $network, int $count = 50): array {
    try {
        // Get nodes
        $nodesResp = getNodesListAPI($pdo, $network);
        $nodes = $nodesResp['data'] ?? [];
        $urls = [];
        foreach ($nodes as $n) {
            $proto = $n['protocol'] ?? 'https';
            $host = $n['domain'] ?? ($n['ip_address'] ?? null);
            if (!$host) { continue; }
            $url = $proto . '://' . $host;
            if (!empty($n['port']) && $n['port'] != 80 && $n['port'] != 443) { $url .= ':' . $n['port']; }
            $urls[] = rtrim($url, '/');
        }

        // Fetch each node's tip hashes
        $perNode = [];
        foreach ($urls as $url) {
            $endpoint = $url . '/api/explorer?action=get_tip_hashes&offset=0&count=' . (int)$count;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 6
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200 || $resp === false) { continue; }
            $json = json_decode($resp, true);
            if (isset($json['success']) && $json['success'] && isset($json['data']['hashes'])) {
                $perNode[$url] = $json['data']['hashes'];
            }
        }

        // Compute majority baseline (hash by position)
        $positionCounts = [];
        foreach ($perNode as $url => $hashes) {
            foreach ($hashes as $i => $h) {
                if (!isset($positionCounts[$i])) { $positionCounts[$i] = []; }
                $positionCounts[$i][$h] = ($positionCounts[$i][$h] ?? 0) + 1;
            }
        }
        $baseline = [];
        foreach ($positionCounts as $i => $map) {
            arsort($map);
            $baseline[$i] = key($map);
        }

        // Compute mismatch ratios per node
        $report = [];
        foreach ($perNode as $url => $hashes) {
            $mismatch = 0; $total = min(count($hashes), count($baseline));
            for ($i = 0; $i < $total; $i++) {
                if (($baseline[$i] ?? null) !== ($hashes[$i] ?? null)) { $mismatch++; }
            }
            $ratio = $total > 0 ? round(100.0 * $mismatch / $total, 2) : 0.0;
            $report[] = [ 'node' => $url, 'mismatch_pct' => $ratio, 'compared' => $total ];
        }

        return [ 'success' => true, 'data' => [ 'nodes' => array_keys($perNode), 'baseline_size' => count($baseline), 'report' => $report ] ];
    } catch (Exception $e) {
        return [ 'success' => false, 'error' => $e->getMessage() ];
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
            // Newer clients expect a flat array in data; older syncers expect data['staking'].
            'data' => $stakingRecords,
            'staking' => $stakingRecords,
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

/**
 * Monitor blockchain heights across network nodes
 */
function getHeightMonitorReport($pdo, $network = 'mainnet') {
    try {
        // Get local blockchain height
        $stmt = $pdo->query("SELECT MAX(height) as local_height FROM blocks");
        $localHeight = $stmt->fetchColumn() ?: 0;
        
        // Get active nodes
        $stmt = $pdo->prepare("SELECT node_id, metadata FROM nodes WHERE status = 'active'");
        $stmt->execute();
        $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $nodeHeights = [];
        $responsiveNodes = 0;
        $totalNodes = count($nodes);
        
        foreach ($nodes as $node) {
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
                        $nodeHeights[$domain] = [
                            'height' => $remoteHeight,
                            'node_id' => $node['node_id'],
                            'difference' => $remoteHeight - $localHeight,
                            'status' => 'responsive'
                        ];
                        $responsiveNodes++;
                    }
                }
            } catch (Exception $e) {
                $nodeHeights[$domain] = [
                    'height' => null,
                    'node_id' => $node['node_id'],
                    'difference' => null,
                    'status' => 'unresponsive',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        // Calculate statistics
        $heights = array_filter(array_column($nodeHeights, 'height'));
        $statistics = [];
        
        if (!empty($heights)) {
            $statistics = [
                'min_height' => min($heights),
                'max_height' => max($heights),
                'avg_height' => round(array_sum($heights) / count($heights), 2),
                'height_spread' => max($heights) - min($heights)
            ];
        }
        
        // Determine overall status
        $status = 'healthy';
        $alerts = [];
        
        if (!empty($heights)) {
            $heightSpread = max($heights) - min($heights);
            if ($heightSpread > 10) {
                $status = 'desync_detected';
                $alerts[] = "Network desync detected: height spread {$heightSpread}";
            }
            
            $maxHeight = max($heights);
            $localDesync = abs($localHeight - $maxHeight);
            if ($localDesync > 10) {
                $status = 'local_desync';
                $alerts[] = "Local node desync: {$localDesync} blocks behind";
            }
        }
        
        if ($responsiveNodes < $totalNodes * 0.5) {
            $status = 'network_issues';
            $alerts[] = "Only {$responsiveNodes}/{$totalNodes} nodes responsive";
        }
        
        return [
            'success' => true,
            'status' => $status,
            'local_height' => $localHeight,
            'node_heights' => $nodeHeights,
            'statistics' => $statistics,
            'responsive_nodes' => $responsiveNodes,
            'total_nodes' => $totalNodes,
            'alerts' => $alerts,
            'timestamp' => time()
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'status' => 'error'
        ];
    }
}

/**
 * Get detailed synchronization status
 */
function getSyncStatus($pdo, $network = 'mainnet') {
    try {
        // Get last sync time from logs or config
        $stmt = $pdo->prepare("SELECT value FROM config WHERE key_name = 'last_sync_time'");
        $stmt->execute();
        $lastSyncTime = $stmt->fetchColumn();
        
        // Get auto-sync configuration
        $stmt = $pdo->prepare("SELECT key_name, value FROM config WHERE key_name LIKE '%sync%' OR key_name LIKE '%monitor%'");
        $stmt->execute();
        $configData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Get recent sync activity from logs
        $recentActivity = [];
        $logFile = '../../logs/network_sync.log';
        if (file_exists($logFile)) {
            $lines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES), -10);
            foreach ($lines as $line) {
                if (strpos($line, '[') === 0) {
                    $recentActivity[] = trim($line);
                }
            }
        }
        
        // Get blockchain stats
        $stmt = $pdo->query("SELECT MAX(height) as height, COUNT(*) as total_blocks FROM blocks");
        $blockchainStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get mempool stats
        $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM mempool GROUP BY status");
        $mempoolStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        return [
            'success' => true,
            'last_sync_time' => $lastSyncTime,
            'sync_config' => $configData,
            'blockchain_stats' => $blockchainStats,
            'mempool_stats' => $mempoolStats,
            'recent_activity' => $recentActivity,
            'timestamp' => time()
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
?>

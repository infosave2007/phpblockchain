<?php
declare(strict_types=1);
namespace Blockchain\SyncService;

// Import global classes used within the namespace to avoid resolution attempts like Blockchain\SyncService\PDO
use PDO;
use Exception;

/**
 * Blockchain Synchronization Manager
 * Standalone service for syncing blockchain data from network nodes
 */

class SyncManager {
    private $pdo;
    private $config;
    private $logFile;
    private $isWebMode;
    
    public function __construct($webMode = false) {
        $this->isWebMode = $webMode;
        $this->logFile = '../logs/sync_service.log';
        $this->initializeDatabase();
        $this->loadConfig();
    }
    
    private function initializeDatabase() {
        try {
            // Load database configuration
            $envFile = '../config/.env';
            if (!file_exists($envFile)) {
                throw new Exception('Environment file not found');
            }
            
            $env = [];
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
                list($key, $value) = explode('=', $line, 2);
                $env[trim($key)] = trim($value);
            }
            
            $this->pdo = new PDO(
                "mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_DATABASE']};charset=utf8mb4",
                $env['DB_USERNAME'],
                $env['DB_PASSWORD'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            $this->log("Database connection established");
            
        } catch (Exception $e) {
            $this->log("Database connection failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function loadConfig() {
        try {
            // Try to load from installation config first
            $installConfig = '../config/installation.json';
            if (file_exists($installConfig)) {
                $configData = json_decode(file_get_contents($installConfig), true);
                if ($configData && isset($configData['network_nodes'])) {
                    $this->config = $configData;
                    $this->log("Configuration loaded from installation.json");
                    return;
                }
            }
            
            // Fallback to database config with improved network_nodes loading
            $stmt = $this->pdo->prepare("SELECT key_name, value FROM config WHERE key_name LIKE 'network.%' OR key_name LIKE 'node.%'");
            $stmt->execute();
            $configRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($configRows) {
                $configValues = [];
                foreach ($configRows as $row) {
                    $configValues[$row['key_name']] = $row['value'];
                }
                
                // Extract network nodes from new config structure
                $networkNodes = $configValues['network.nodes'] ?? '';
                $selectionStrategy = $configValues['node.selection_strategy'] ?? 'fastest_response';
                
                // If no network.nodes, try legacy fields
                if (empty($networkNodes)) {
                    // Try legacy format from old config table
                    $stmt = $this->pdo->prepare("SELECT * FROM config WHERE id = 1");
                    $stmt->execute();
                    $configRow = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($configRow) {
                        $settings = json_decode($configRow['settings'] ?? '{}', true);
                        
                        if (isset($settings['network_nodes'])) {
                            $networkNodes = $settings['network_nodes'];
                        } elseif (isset($configRow['network_nodes'])) {
                            $networkNodes = $configRow['network_nodes'];
                        } elseif (isset($configRow['genesis_node'])) {
                            $networkNodes = $configRow['genesis_node'];
                        }
                    }
                }
                
                $this->config = [
                    'network_nodes' => $networkNodes,
                    'node_selection_strategy' => $selectionStrategy
                ];
                
                $this->log("Configuration loaded from database (new format)");
                
                if (empty($networkNodes)) {
                    $this->log("Warning: No network nodes found in database config");
                }
            } else {
                throw new Exception("No network configuration found. Please configure network nodes first.");
            }
        } catch (Exception $e) {
            $this->log("Failed to load config: " . $e->getMessage());
            throw new Exception("Network configuration required for synchronization");
        }
    }
    
    public function syncAll() {
        $this->log("=== Starting Full Blockchain Synchronization ===");
        $startTime = microtime(true);
        
        try {
            // Step 1: Select best node
            $this->outputProgress(5, "Selecting best network node...");
            $bestNode = $this->selectBestNode();
            $this->log("Selected node: $bestNode");
            
            // Step 2: Get current state
            $this->outputProgress(10, "Checking current database state...");
            $currentState = $this->getCurrentDatabaseState();
            
            // Step 3: Sync network configuration
            $this->outputProgress(15, "Syncing network configuration...");
            $this->syncNetworkConfig($bestNode);
            
            // Step 4: Sync blocks
            $this->outputProgress(25, "Syncing blockchain blocks...");
            $newBlocks = $this->syncBlocks($bestNode, $currentState['latest_block']);
            
            // Step 5: Sync transactions
            $this->outputProgress(45, "Syncing transactions...");
            $newTransactions = $this->syncTransactions($bestNode, $currentState['latest_transaction']);
            
            // Step 6: Sync nodes and validators
            $this->outputProgress(65, "Syncing nodes and validators...");
            $newNodes = $this->syncNodesAndValidators($bestNode);
            
            // Step 7: Sync smart contracts
            $this->outputProgress(80, "Syncing smart contracts...");
            $newContracts = $this->syncSmartContracts($bestNode, $currentState['latest_contract']);
            
            // Step 8: Sync staking records
            $this->outputProgress(85, "Syncing staking records...");
            $newStaking = $this->syncStakingRecords($bestNode, $currentState['latest_staking']);
            
            // Step 9: Sync wallets
            $this->outputProgress(90, "Syncing wallets...");
            $newWallets = $this->syncWallets($bestNode);
            
            // Step 10: Sync mempool
            $this->outputProgress(95, "Syncing mempool...");
            $newMempool = $this->syncMempool($bestNode);
            
            // Complete
            $this->outputProgress(100, "Synchronization completed!");
            
            $totalTime = round(microtime(true) - $startTime, 2);
            $totalSynced = $newBlocks + $newTransactions + $newNodes + $newContracts + $newStaking + $newWallets + $newMempool;
            
            $result = [
                'status' => 'success',
                'node' => $bestNode,
                'new_blocks' => $newBlocks,
                'new_transactions' => $newTransactions,
                'new_nodes' => $newNodes,
                'new_contracts' => $newContracts,
                'new_staking' => $newStaking,
                'new_wallets' => $newWallets,
                'new_mempool' => $newMempool,
                'total_new_records' => $totalSynced,
                'sync_time' => $totalTime,
                'completion_time' => date('Y-m-d H:i:s')
            ];
            
            $this->log("Sync completed successfully! Total: $totalSynced new records in {$totalTime}s");
            return $result;
            
        } catch (Exception $e) {
            $this->log("Sync failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function selectBestNode() {
        $networkNodes = $this->config['network_nodes'] ?? '';
        
        if (empty($networkNodes)) {
            throw new Exception('No network nodes configured. Please add network nodes to your configuration.');
        }
        
        $nodeUrls = array_filter(array_map('trim', explode("\n", $networkNodes)));
        
        if (empty($nodeUrls)) {
            throw new Exception('No valid network nodes found in configuration');
        }
        
        $bestNode = null;
        $bestHeight = -1;
        $nodeResults = [];
        
        foreach ($nodeUrls as $node) {
            $startTime = microtime(true);
            
            // Test blockchain height instead of just connectivity
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $node . '/api/explorer/index.php?action=get_network_stats',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            
            $response = curl_exec($ch);
            $responseTime = microtime(true) - $startTime;
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                $nodeHeight = $data['current_height'] ?? 0;
                
                $nodeResults[] = [
                    'url' => $node,
                    'height' => $nodeHeight,
                    'response_time' => round($responseTime * 1000, 2)
                ];
                
                $this->log("Node $node: height $nodeHeight ({$nodeResults[count($nodeResults)-1]['response_time']}ms)");
                
                // Select node with highest blockchain (longest chain rule)
                if ($nodeHeight > $bestHeight) {
                    $bestHeight = $nodeHeight;
                    $bestNode = $node;
                }
            } else {
                $this->log("Node $node: unreachable");
            }
        }
        
        if (!$bestNode) {
            throw new Exception('No accessible network nodes found with valid blockchain data');
        }
        
        $this->log("Selected best node: $bestNode (height: $bestHeight, longest chain rule)");
        return $bestNode;
    }
    
    private function getCurrentDatabaseState() {
        $state = [
            'latest_block' => 0,
            'latest_transaction' => '',
            'latest_contract' => 0,
            'latest_staking' => 0
        ];
        
        try {
            // Get latest block
            $stmt = $this->pdo->query("SELECT MAX(height) as max_height FROM blocks");
            $result = $stmt->fetch();
            $state['latest_block'] = $result['max_height'] ?? 0;
            
            // Get latest transaction
            $stmt = $this->pdo->query("SELECT hash FROM transactions ORDER BY timestamp DESC LIMIT 1");
            $result = $stmt->fetch();
            $state['latest_transaction'] = $result['hash'] ?? '';
            
            // Get latest smart contract
            $stmt = $this->pdo->query("SELECT MAX(deployment_block) as max_block FROM smart_contracts");
            $result = $stmt->fetch();
            $state['latest_contract'] = $result['max_block'] ?? 0;
            
            // Get latest staking
            $stmt = $this->pdo->query("SELECT MAX(start_block) as max_block FROM staking");
            $result = $stmt->fetch();
            $state['latest_staking'] = $result['max_block'] ?? 0;
            
        } catch (Exception $e) {
            $this->log("Warning: Could not get current state: " . $e->getMessage());
        }
        
        return $state;
    }
    
    private function syncNetworkConfig($node) {
        try {
            $response = $this->makeApiRequest($node, 'get_network_config');
            if (isset($response['network_name'])) {
                // Update config in database if needed
                $this->log("Network config synced: " . $response['network_name']);
            }
        } catch (Exception $e) {
            $this->log("Warning: Could not sync network config: " . $e->getMessage());
        }
    }
    
    private function syncBlocks($node, $latestBlockId) {
        $newBlocks = 0;
        $page = 0;
        $limit = 100;
        
        try {
            do {
                $response = $this->makeApiRequest($node, 'get_all_blocks', ['page' => $page, 'limit' => $limit]);
                $blocks = $response['data'] ?? [];
                
                $this->log("Page $page: Found " . count($blocks) . " blocks from API");
                
                if (empty($blocks)) break;
                
                foreach ($blocks as $block) {
                    $blockHeight = $block['height'] ?? $block['id'] ?? 0;
                    
                    $this->log("Processing block height $blockHeight (latest local: $latestBlockId)");
                    
                    // Check if block already exists (by hash or height)
                    $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM blocks WHERE height = ? OR hash = ?");
                    $stmt->execute([$blockHeight, $block['hash']]);
                    if ($stmt->fetchColumn() > 0) {
                        $this->log("Block $blockHeight already exists in database");
                        continue;
                    }
                    
                    $stmt = $this->pdo->prepare("
                        INSERT INTO blocks (height, hash, parent_hash, merkle_root, timestamp, validator, signature, transactions_count, metadata)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $metadata = $block['metadata'] ?? '{}';
                    if (is_array($metadata)) {
                        $metadata = json_encode($metadata);
                    }
                    
                    $stmt->execute([
                        $blockHeight,
                        $block['hash'],
                        $block['parent_hash'] ?? $block['previous_hash'],
                        $block['merkle_root'] ?? '',
                        $block['timestamp'],
                        $block['validator'] ?? '',
                        $block['signature'] ?? '',
                        $block['transactions_count'] ?? 0,
                        $metadata
                    ]);
                    
                    if ($stmt->rowCount() > 0) {
                        $this->log("Successfully inserted block $blockHeight");
                        $newBlocks++;
                    }
                }
                
                $page++;
                
                // Check if there are more blocks
                $pagination = $response['pagination'] ?? [];
                $hasMore = $pagination['has_more'] ?? false;
                
            } while ($hasMore && $page < 50); // Safety limit
            
        } catch (Exception $e) {
            $this->log("Warning: Block sync error: " . $e->getMessage());
        }
        
        $this->log("Synced $newBlocks new blocks");
        return $newBlocks;
    }
    
    private function syncTransactions($node, $latestTxHash) {
        $newTransactions = 0;
        $page = 0;
        $limit = 100;
        
        try {
            do {
                $response = $this->makeApiRequest($node, 'get_all_transactions', ['page' => $page, 'limit' => $limit]);
                $transactions = $response['data'] ?? [];
                
                if (empty($transactions)) break;
                
                $foundLatest = false;
                foreach ($transactions as $tx) {
                    if ($tx['hash'] === $latestTxHash) {
                        $foundLatest = true;
                        break;
                    }
                    
                    // Check if transaction already exists
                    $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM transactions WHERE hash = ?");
                    $stmt->execute([$tx['hash']]);
                    if ($stmt->fetchColumn() > 0) continue;
                    
                    // Check if referenced block exists (to avoid foreign key constraint error)
                    $blockHash = $tx['block_hash'];
                    if (!empty($blockHash)) {
                        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM blocks WHERE hash = ?");
                        $stmt->execute([$blockHash]);
                        if ($stmt->fetchColumn() == 0) {
                            $this->log("Skipping transaction {$tx['hash']} - referenced block {$blockHash} not found");
                            continue;
                        }
                    }
                    
                    $stmt = $this->pdo->prepare("
                        INSERT INTO transactions (hash, from_address, to_address, amount, fee, gas_limit, gas_used, gas_price, nonce, data, signature, status, timestamp, block_height, block_hash)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $tx['hash'],
                        $tx['from_address'] ?? $tx['from'],
                        $tx['to_address'] ?? $tx['to'],
                        $tx['amount'],
                        $tx['fee'] ?? 0,
                        $tx['gas_limit'] ?? 21000,
                        $tx['gas_used'] ?? 0,
                        $tx['gas_price'] ?? 0,
                        $tx['nonce'] ?? 0,
                        $tx['data'] ?? '[]',
                        $tx['signature'] ?? '',
                        $tx['status'] ?? 'confirmed',
                        $tx['timestamp'],
                        $tx['block_height'] ?? $tx['block_index'] ?? 0,
                        $tx['block_hash']
                    ]);
                    
                    if ($stmt->rowCount() > 0) {
                        $newTransactions++;
                        $this->log("Synced transaction: " . $tx['hash']);
                    }
                }
                
                if ($foundLatest) break;
                
                $page++;
                
                // Check if there are more transactions
                $pagination = $response['pagination'] ?? [];
                $hasMore = $pagination['has_more'] ?? false;
                
            } while ($hasMore && $page < 50); // Safety limit
            
        } catch (Exception $e) {
            $this->log("Warning: Transaction sync error: " . $e->getMessage());
        }
        
        $this->log("Synced $newTransactions new transactions");
        return $newTransactions;
    }
    
    private function syncNodesAndValidators($node) {
        $newRecords = 0;
        
        try {
            // Sync nodes
            $response = $this->makeApiRequest($node, 'get_nodes');
            $nodes = $response['data'] ?? [];
            
            foreach ($nodes as $nodeData) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO nodes (node_id, ip_address, port, public_key, version, status, last_seen, reputation_score, metadata)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    last_seen = VALUES(last_seen),
                    status = VALUES(status),
                    reputation_score = VALUES(reputation_score),
                    public_key = VALUES(public_key),
                    version = VALUES(version)
                ");
                
                $stmt->execute([
                    $nodeData['node_id'] ?? '',
                    $nodeData['ip_address'] ?? '',
                    $nodeData['port'] ?? 80,
                    $nodeData['public_key'] ?? '',
                    $nodeData['version'] ?? '1.0.0',
                    $nodeData['status'] ?? 'active',
                    $nodeData['last_seen'] ?? date('Y-m-d H:i:s'),
                    $nodeData['reputation_score'] ?? 0,
                    json_encode($nodeData['metadata'] ?? [])
                ]);
                
                if ($stmt->rowCount() > 0) {
                    $newRecords++;
                    $this->log("Synced node: " . ($nodeData['node_id'] ?? 'unknown'));
                }
            }
            
            // Sync validators
            $response = $this->makeApiRequest($node, 'get_validators');
            $validators = $response['data'] ?? [];
            
            foreach ($validators as $validator) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO validators (address, public_key, stake, delegated_stake, commission_rate, status, blocks_produced, blocks_missed, last_active_block, metadata)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    stake = VALUES(stake),
                    delegated_stake = VALUES(delegated_stake),
                    status = VALUES(status),
                    blocks_produced = VALUES(blocks_produced),
                    blocks_missed = VALUES(blocks_missed),
                    public_key = VALUES(public_key)
                ");
                
                $metadata = $validator['metadata'] ?? null;
                if (is_array($metadata)) {
                    $metadata = json_encode($metadata);
                }
                
                $stmt->execute([
                    $validator['address'],
                    $validator['public_key'] ?? '',
                    $validator['stake'] ?? 0,
                    $validator['delegated_stake'] ?? 0,
                    $validator['commission_rate'] ?? 0,
                    $validator['status'] ?? 'active',
                    $validator['blocks_produced'] ?? 0,
                    $validator['blocks_missed'] ?? 0,
                    $validator['last_active_block'] ?? 0,
                    $metadata
                ]);
                
                if ($stmt->rowCount() > 0) {
                    $newRecords++;
                    $this->log("Synced validator: " . $validator['address']);
                }
            }
            
        } catch (Exception $e) {
            $this->log("Warning: Nodes/validators sync error: " . $e->getMessage());
        }
        
        $this->log("Synced $newRecords new nodes/validators");
        return $newRecords;
    }
    
    private function syncSmartContracts($node, $latestContractId) {
        $newContracts = 0;
        $page = 0;
        $limit = 100;
        
        try {
            do {
                $response = $this->makeApiRequest($node, 'get_smart_contracts', ['page' => $page, 'limit' => $limit]);
                $contracts = $response['data'] ?? [];
                
                if (empty($contracts)) break;
                
                foreach ($contracts as $contract) {
                    // Skip if we already have this contract
                    $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM smart_contracts WHERE address = ?");
                    $stmt->execute([$contract['address']]);
                    if ($stmt->fetchColumn() > 0) continue;
                    
                    $stmt = $this->pdo->prepare("
                        INSERT IGNORE INTO smart_contracts (address, creator, name, version, bytecode, abi, source_code, deployment_tx, deployment_block, gas_used, status, storage, metadata)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $abi = $contract['abi'] ?? [];
                    if (is_array($abi)) {
                        $abi = json_encode($abi);
                    }
                    
                    $storage = $contract['storage'] ?? [];
                    if (is_array($storage)) {
                        $storage = json_encode($storage);
                    }
                    
                    $metadata = $contract['metadata'] ?? [];
                    if (is_array($metadata)) {
                        $metadata = json_encode($metadata);
                    }
                    
                    $stmt->execute([
                        $contract['address'],
                        $contract['creator'] ?? '',
                        $contract['name'] ?? '',
                        $contract['version'] ?? '1.0',
                        $contract['bytecode'] ?? '',
                        $abi,
                        $contract['source_code'] ?? '',
                        $contract['deployment_tx'] ?? '',
                        $contract['deployment_block'] ?? 0,
                        $contract['gas_used'] ?? 0,
                        $contract['status'] ?? 'active',
                        $storage,
                        $metadata
                    ]);
                    
                    if ($stmt->rowCount() > 0) {
                        $newContracts++;
                    }
                }
                
                $page++;
                
            } while (count($contracts) == $limit && $page < 50);
            
        } catch (Exception $e) {
            $this->log("Warning: Smart contracts sync error: " . $e->getMessage());
        }
        
        $this->log("Synced $newContracts new smart contracts");
        return $newContracts;
    }
    
    private function syncStakingRecords($node, $latestStakingId) {
        $newStaking = 0;
        $page = 0;
        $limit = 100;
        
        try {
            do {
                $response = $this->makeApiRequest($node, 'get_staking_records', ['page' => $page, 'limit' => $limit]);
                $stakingRecords = $response['data'] ?? [];
                
                if (empty($stakingRecords)) break;
                
                foreach ($stakingRecords as $record) {
                    // Check if record already exists
                    $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM staking WHERE validator = ? AND staker = ? AND start_block = ?");
                    $stmt->execute([$record['validator'], $record['staker'], $record['start_block']]);
                    if ($stmt->fetchColumn() > 0) continue;
                    
                    $stmt = $this->pdo->prepare("
                        INSERT INTO staking (validator, staker, amount, reward_rate, start_block, end_block, status, rewards_earned, last_reward_block)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $record['validator'],
                        $record['staker'],
                        $record['amount'],
                        $record['reward_rate'] ?? 0,
                        $record['start_block'] ?? 0,
                        $record['end_block'],
                        $record['status'] ?? 'active',
                        $record['rewards_earned'] ?? 0,
                        $record['last_reward_block'] ?? 0
                    ]);
                    
                    $newStaking++;
                }
                
                $page++;
                
            } while (count($stakingRecords) == $limit && $page < 50);
            
        } catch (Exception $e) {
            $this->log("Warning: Staking sync error: " . $e->getMessage());
        }
        
        $this->log("Synced $newStaking new staking records");
        return $newStaking;
    }
    
    private function makeApiRequest($node, $action, $params = []) {
        $url = $node . '/api/explorer/index.php?action=' . $action;
        
        if (!empty($params)) {
            $url .= '&' . http_build_query($params);
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'SyncService/1.0',
            CURLOPT_ENCODING => 'gzip, deflate', // Support gzip compression
            CURLOPT_HTTPHEADER => [
                'Accept-Encoding: gzip, deflate',
                'Accept: application/json',
                'Content-Type: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentEncoding = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("API request failed: HTTP $httpCode");
        }
        
        // Log compression info for debugging
        $originalSize = strlen($response);
        $this->log("API Response size: {$originalSize} bytes" . 
                  (strpos($contentEncoding, 'gzip') !== false ? ' (gzip compressed)' : ''));
        
        $data = json_decode($response, true);
        if (!$data) {
            throw new Exception("Invalid JSON response from API");
        }
        
        return $data;
    }
    
    public function getStatus() {
        try {
            $status = [
                'sync_time' => date('Y-m-d H:i:s'),
                'tables' => []
            ];
            
            // Get table counts
            $tables = ['blocks', 'transactions', 'nodes', 'validators', 'smart_contracts', 'staking', 'wallets', 'mempool'];
            foreach ($tables as $table) {
                try {
                    $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM $table");
                    $result = $stmt->fetch();
                    $status['tables'][$table] = $result['count'] ?? 0;
                } catch (Exception $e) {
                    $status['tables'][$table] = 0;
                }
            }
            
            // Get latest block info
            try {
                $stmt = $this->pdo->query("SELECT height, timestamp FROM blocks ORDER BY height DESC LIMIT 1");
                $result = $stmt->fetch();
                $status['latest_block'] = $result['height'] ?? 0;
                $status['latest_timestamp'] = $result['timestamp'] ? date('Y-m-d H:i:s', $result['timestamp']) : 'Unknown';
            } catch (Exception $e) {
                $status['latest_block'] = 0;
                $status['latest_timestamp'] = 'Unknown';
            }
            
            return $status;
            
        } catch (Exception $e) {
            throw new Exception("Failed to get status: " . $e->getMessage());
        }
    }
    
    private function syncWallets($node) {
        $newWallets = 0;
        $page = 0;
        $limit = 100;
        
        try {
            do {
                $response = $this->makeApiRequest($node, 'get_wallets', ['page' => $page, 'limit' => $limit]);
                $wallets = $response['data'] ?? [];
                
                if (empty($wallets)) break;
                
                foreach ($wallets as $wallet) {
                    // Check if wallet already exists
                    $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM wallets WHERE address = ?");
                    $stmt->execute([$wallet['address']]);
                    if ($stmt->fetchColumn() > 0) {
                        // Update existing wallet
                        $stmt = $this->pdo->prepare("
                            UPDATE wallets 
                            SET balance = ?, staked_balance = ?, nonce = ?, updated_at = NOW()
                            WHERE address = ?
                        ");
                        $stmt->execute([
                            $wallet['balance'] ?? '0.00000000',
                            max(0, $wallet['staked_balance'] ?? '0.00000000'),
                            $wallet['nonce'] ?? 0,
                            $wallet['address']
                        ]);
                        continue;
                    }
                    
                    $stmt = $this->pdo->prepare("
                        INSERT INTO wallets (address, public_key, balance, staked_balance, nonce)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                        balance = VALUES(balance),
                        staked_balance = VALUES(staked_balance), 
                        nonce = VALUES(nonce),
                        updated_at = NOW()
                    ");
                    $stmt->execute([
                        $wallet['address'],
                        $wallet['public_key'] ?? '',
                        $wallet['balance'] ?? '0.00000000',
                        max(0, $wallet['staked_balance'] ?? '0.00000000'),
                        $wallet['nonce'] ?? 0
                    ]);
                    
                    if ($stmt->rowCount() > 0) {
                        $newWallets++;
                        $this->log("Synced wallet: " . $wallet['address']);
                    }
                }
                
                $page++;
                
            } while (count($wallets) == $limit && $page < 50);
            
        } catch (Exception $e) {
            $this->log("Warning: Wallets sync error: " . $e->getMessage());
        }
        
        $this->log("Synced $newWallets new wallets");
        return $newWallets;
    }
    
    private function syncMempool($node) {
        $newMempool = 0;
        
        try {
            $response = $this->makeApiRequest($node, 'get_mempool', ['limit' => 500]);
            $mempoolTxs = $response['data'] ?? [];
            
            if (empty($mempoolTxs)) {
                $this->log("No mempool transactions to sync");
                return 0;
            }
            
            foreach ($mempoolTxs as $tx) {
                // Check if transaction already exists in mempool (handle 0x and non-0x)
                $h = strtolower(trim((string)($tx['tx_hash'] ?? '')));
                $h0 = str_starts_with($h,'0x') ? $h : ('0x'.$h);
                $h1 = str_starts_with($h,'0x') ? substr($h,2) : $h;
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM mempool WHERE tx_hash = ? OR tx_hash = ?");
                $stmt->execute([$h0, $h1]);
                if ($stmt->fetchColumn() > 0) continue;
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO mempool (tx_hash, from_address, to_address, amount, fee, gas_price, gas_limit, nonce, data, signature, priority_score, status, node_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $h0,
                    $tx['from_address'],
                    $tx['to_address'],
                    $tx['amount'] ?? '0.00000000',
                    $tx['fee'] ?? '0.00000000',
                    $tx['gas_price'] ?? '0.00000000',
                    $tx['gas_limit'] ?? 0,
                    $tx['nonce'] ?? 0,
                    $tx['data'] ?? '',
                    $tx['signature'] ?? '',
                    $tx['priority_score'] ?? 0,
                    $tx['status'] ?? 'pending',
                    $tx['node_id'] ?? null
                ]);
                
                if ($stmt->rowCount() > 0) {
                    $newMempool++;
                    $this->log("Synced mempool tx: " . $tx['tx_hash']);
                }
            }
            
        } catch (Exception $e) {
            $this->log("Warning: Mempool sync error: " . $e->getMessage());
        }
        
        $this->log("Synced $newMempool new mempool transactions");
        return $newMempool;
    }
    
    private function outputProgress($percent, $message) {
        if ($this->isWebMode) {
            echo json_encode([
                'type' => 'progress',
                'percent' => $percent,
                'message' => $message
            ]) . "\n";
            flush();
        } else {
            echo "[$percent%] $message\n";
        }
    }
    
    private function log($message) {
        $logMessage = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        
        if (!is_dir(dirname($this->logFile))) {
            mkdir(dirname($this->logFile), 0755, true);
        }
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        
        if (!$this->isWebMode) {
            echo $logMessage;
        }
    }
}

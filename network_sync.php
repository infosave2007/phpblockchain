<?php
/**
 * Network Blockchain Synchronization Manager
 * Standalone script for syncing blockchain data from network nodes
 * Can be used for initial sync or recovery after failures
 */

require_once 'vendor/autoload.php';
require_once 'config/config.php';

class NetworkSyncManager {
    private $pdo;
    private $config;
    private $logFile;
    private $isWebMode;
    
    public function __construct($webMode = false) {
        $this->isWebMode = $webMode;
        $this->logFile = 'logs/network_sync.log';
        $this->initializeDatabase();
        $this->loadConfig();
    }
    
    private function initializeDatabase() {
        try {
            // Load environment variables from .env file
            $envFile = __DIR__ . '/config/.env';
            if (file_exists($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (strpos($line, '#') === 0) continue; // Skip comments
                    if (strpos($line, '=') !== false) {
                        list($key, $value) = explode('=', $line, 2);
                        $key = trim($key);
                        $value = trim($value);
                        if (!array_key_exists($key, $_ENV)) {
                            $_ENV[$key] = $value;
                        }
                    }
                }
            }
            
            $this->pdo = new PDO(
                "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_DATABASE']};charset=utf8mb4",
                $_ENV['DB_USERNAME'],
                $_ENV['DB_PASSWORD'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (Exception $e) {
            $this->log("Database connection failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function loadConfig() {
        try {
            // 1) Try installation.json (как в sync-service)
            $installConfig = __DIR__ . '/config/installation.json';
            if (file_exists($installConfig)) {
                $data = json_decode(file_get_contents($installConfig), true);
                if (is_array($data) && isset($data['network_nodes'])) {
                    $this->config = [
                        'network_nodes' => $data['network_nodes'],
                        'node_selection_strategy' => $data['node_selection_strategy'] ?? 'fastest_response'
                    ];
                    $this->log("Configuration loaded successfully from installation.json");
                    return;
                }
            }

            // 2) Новая модель конфигурации в таблице config (как в sync-service/SyncManager)
            $stmt = $this->pdo->prepare("SELECT key_name, value FROM config WHERE key_name LIKE 'network.%' OR key_name LIKE 'node.%'");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $values = [];
            foreach ($rows as $row) {
                $values[$row['key_name']] = $row['value'];
            }

            $networkNodes = $values['network.nodes'] ?? '';
            $selectionStrategy = $values['node.selection_strategy'] ?? 'fastest_response';

            // 3) Legacy-фоллбек: строка id=1 с колонкой settings JSON
            if (empty($networkNodes)) {
                $stmt = $this->pdo->prepare("SELECT * FROM config WHERE id = 1");
                $stmt->execute();
                $legacy = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($legacy) {
                    $settingsJson = $legacy['settings'] ?? '{}';
                    $settings = json_decode($settingsJson ?: '{}', true) ?: [];
                    if (!empty($settings['network_nodes'])) {
                        $networkNodes = $settings['network_nodes'];
                    } elseif (!empty($legacy['network_nodes'])) {
                        $networkNodes = $legacy['network_nodes'];
                    } elseif (!empty($legacy['genesis_node'])) {
                        $networkNodes = $legacy['genesis_node'];
                    }
                }
            }

            $this->config = [
                'network_nodes' => $networkNodes,
                'node_selection_strategy' => $selectionStrategy
            ];

            $this->log("Configuration loaded successfully" . (empty($networkNodes) ? " (warning: network_nodes is empty)" : ""));
            if (empty($networkNodes)) {
                $this->log("Warning: No network nodes configured. Please fill 'network.nodes' in config table or installation.json");
            }
        } catch (Exception $e) {
            $this->log("Failed to load config: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function syncAll() {
        $this->log("=== Starting Full Blockchain Synchronization ===");
        $totalSteps = 7;
        $currentStep = 0;
        
        try {
            // Step 1: Select best node
            $currentStep++;
            $this->updateProgress($currentStep, $totalSteps, "Selecting best network node...");
            $bestNode = $this->selectBestNode();
            
            // Step 2: Sync genesis block
            $currentStep++;
            $this->updateProgress($currentStep, $totalSteps, "Syncing genesis block...");
            $this->syncGenesisBlock($bestNode);
            
            // Step 3: Sync network configuration
            $currentStep++;
            $this->updateProgress($currentStep, $totalSteps, "Syncing network configuration...");
            $this->syncNetworkConfig($bestNode);
            
            // Step 4: Sync nodes and validators
            $currentStep++;
            $this->updateProgress($currentStep, $totalSteps, "Syncing nodes and validators...");
            $this->syncNodesAndValidators($bestNode);
            
            // Step 5: Sync blockchain blocks
            $currentStep++;
            $this->updateProgress($currentStep, $totalSteps, "Syncing blockchain blocks...");
            $blocksSynced = $this->syncBlocks($bestNode);
            
            // Step 6: Sync transactions
            $currentStep++;
            $this->updateProgress($currentStep, $totalSteps, "Syncing transactions...");
            $transactionsSynced = $this->syncTransactions($bestNode);
            
            // Step 7: Sync additional data (smart contracts, staking)
            $currentStep++;
            $this->updateProgress($currentStep, $totalSteps, "Syncing smart contracts and staking...");
            $this->syncAdditionalData($bestNode);
            
            $this->updateProgress($totalSteps, $totalSteps, "Synchronization completed successfully!");
            
            $summary = [
                'status' => 'success',
                'node' => $bestNode,
                'blocks_synced' => $blocksSynced,
                'transactions_synced' => $transactionsSynced,
                'completion_time' => date('Y-m-d H:i:s')
            ];
            
            $this->log("Sync completed successfully: " . json_encode($summary));
            return $summary;
            
        } catch (Exception $e) {
            $this->log("Sync failed: " . $e->getMessage());
            $this->updateProgress($currentStep, $totalSteps, "Sync failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function selectBestNode() {
        // 1) Попробовать получить активные узлы из таблицы nodes (источник после установки)
        $nodeUrls = [];
        try {
            $stmt = $this->pdo->prepare("SELECT ip_address, port, metadata FROM nodes WHERE status = 'active'");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $ip = trim($row['ip_address'] ?? '');
                $port = (int)($row['port'] ?? 80);
                $meta = $row['metadata'] ?? null;

                $metaArr = [];
                if (is_string($meta) && $meta !== '') {
                    $decoded = json_decode($meta, true);
                    if (is_array($decoded)) {
                        $metaArr = $decoded;
                    }
                } elseif (is_array($meta)) {
                    $metaArr = $meta;
                }

                $protocol = $metaArr['protocol'] ?? 'http';
                $domain = $metaArr['domain'] ?? '';
                $host = $domain !== '' ? $domain : $ip;
                if ($host === '') continue;

                $defaultPort = ($protocol === 'https') ? 443 : 80;
                $portPart = ($port > 0 && $port !== $defaultPort) ? (':' . $port) : '';
                $url = sprintf('%s://%s%s', $protocol, rtrim($host, '/'), $portPart);
                $nodeUrls[] = $url;
            }
        } catch (\Throwable $e) {
            $this->log("Warning: failed to read nodes table: " . $e->getMessage());
        }

        // 2) Фоллбек: конфиг network_nodes (многострочный или CSV)
        if (empty($nodeUrls)) {
            $rawNodes = $this->config['network_nodes'] ?? '';
            $candidates = preg_split('/[\r\n,]+/', (string)$rawNodes);
            $nodeUrls = array_values(array_filter(array_map('trim', $candidates)));
        }

        if (empty($nodeUrls)) {
            throw new Exception('No network nodes configured. Please add network nodes to your configuration.');
        }

        $strategy = $this->config['node_selection_strategy'] ?? 'fastest_response';
        $this->log("Testing " . count($nodeUrls) . " network nodes with strategy: $strategy");

        $nodeResults = [];
        foreach ($nodeUrls as $node) {
            $result = $this->testNode($node);
            $nodeResults[] = array_merge(['url' => $node], $result);
            $this->log("Node $node: " . ($result['accessible'] ? 'OK' : 'FAIL') .
                      ($result['accessible'] ? " ({$result['response_time']}ms)" : " - {$result['error']}"));
        }

        $accessibleNodes = array_filter($nodeResults, fn($n) => $n['accessible']);
        if (empty($accessibleNodes)) {
            throw new Exception("No accessible nodes found");
        }

        if ($strategy === 'fastest_response') {
            usort($accessibleNodes, fn($a, $b) => $a['response_time'] <=> $b['response_time']);
        }

        $bestNode = $accessibleNodes[0]['url'];
        $this->log("Selected best node: $bestNode");
        return $bestNode;
    }
    
    private function testNode($nodeUrl) {
        // Пробуем сначала get_network_config, если нет — get_network_stats
        $endpoints = [
            '/api/explorer/index.php?action=get_network_config',
            '/api/explorer/index.php?action=get_network_stats',
        ];
        $errors = [];
        $best = null;

        foreach ($endpoints as $suffix) {
            $testUrl = rtrim($nodeUrl, '/') . $suffix;
            $startTime = microtime(true);
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'method' => 'GET',
                    'header' => "User-Agent: NetworkSyncManager/1.1\r\nAccept: application/json\r\n"
                ]
            ]);
            $result = @file_get_contents($testUrl, false, $context);
            $responseTime = (microtime(true) - $startTime) * 1000;

            if ($result === false) {
                $errors[] = "Connection failed: $testUrl";
                continue;
            }

            $data = json_decode($result, true);
            if (!$data) {
                $errors[] = "Invalid JSON: $testUrl";
                continue;
            }

            // Принимаем оба формата: {success:true,data:{...}} или просто конфиг
            $ok = (isset($data['success']) && $data['success'] === true) || isset($data['network_name']) || isset($data['data']);
            if ($ok) {
                $best = [
                    'accessible' => true,
                    'response_time' => $responseTime,
                    'block_height' => $data['data']['block_height'] ?? null,
                    'error' => null
                ];
                break;
            } else {
                $errors[] = "Unexpected response format: $testUrl";
            }
        }

        if ($best) {
            return $best;
        }

        return [
            'accessible' => false,
            'response_time' => null,
            'error' => implode('; ', $errors) ?: 'Connection failed'
        ];
    }
    
    private function syncGenesisBlock($node) {
        $url = rtrim($node, '/') . '/api/explorer/index.php?action=get_block&block_id=0';
        $response = $this->makeApiCall($url);
        
        if (!$response || !isset($response['success']) || !$response['success']) {
            throw new Exception("Failed to fetch genesis block from $node");
        }
        
        $block = $response['data'];
        
        // Check if genesis block already exists
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM blocks WHERE height = 0");
        $stmt->execute();
        $exists = $stmt->fetchColumn() > 0;
        
        if (!$exists) {
            $stmt = $this->pdo->prepare("
                INSERT INTO blocks (height, hash, previous_hash, merkle_root, timestamp, nonce, difficulty, creator, signature)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                0,
                $block['hash'],
                '',
                $block['merkle_root'] ?? '',
                $block['timestamp'],
                $block['nonce'] ?? 0,
                $block['difficulty'] ?? 1,
                $block['creator'] ?? '',
                $block['signature'] ?? ''
            ]);
            $this->log("Genesis block synced: " . $block['hash']);
        } else {
            $this->log("Genesis block already exists, skipping");
        }
    }
    
    private function syncNetworkConfig($node) {
        $url = rtrim($node, '/') . '/api/explorer/index.php?action=get_network_config';
        $response = $this->makeApiCall($url);
        
        if (!$response || !isset($response['success']) || !$response['success']) {
            $this->log("Warning: Could not fetch network config from $node");
            return;
        }
        
        $config = $response['data'];
        $this->log("Network config synced: " . ($config['network_name'] ?? 'Unknown'));
    }
    
    private function syncNodesAndValidators($node) {
        // Sync nodes -> адаптация под вашу схему nodes (id, node_id, ip_address, port, public_key, version, status, last_seen, blocks_synced, ping_time, reputation_score, metadata, created_at, updated_at)
        $url = rtrim($node, '/') . '/api/explorer/index.php?action=get_nodes_list';
        $response = $this->makeApiCall($url);
        
        if ($response && isset($response['success']) && $response['success']) {
            $nodes = $response['data'] ?? [];
            $syncedNodes = 0;
            
            foreach ($nodes as $nodeData) {
                // Маппинг входных полей в вашу схему
                $nodeId = $nodeData['node_id'] ?? ($nodeData['address'] ?? '');
                $version = $nodeData['version'] ?? '1.0.0';
                $status = $nodeData['status'] ?? 'active';
                $lastSeen = $nodeData['last_seen'] ?? date('Y-m-d H:i:s');
                $reputation = $nodeData['reputation_score'] ?? 100;
                $publicKey = $nodeData['public_key'] ?? '';
                
                // Определяем ip/port/protocol/domain из url/metadata
                $ip = '';
                $port = 80;
                $meta = [];
                if (!empty($nodeData['metadata']) && is_array($nodeData['metadata'])) {
                    $meta = $nodeData['metadata'];
                }
                if (!empty($nodeData['url'])) {
                    $parts = parse_url($nodeData['url']);
                    if (is_array($parts)) {
                        $scheme = $parts['scheme'] ?? 'http';
                        $host = $parts['host'] ?? '';
                        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
                        // host может быть доменом или ip
                        if (filter_var($host, FILTER_VALIDATE_IP)) {
                            $ip = $host;
                            $meta['protocol'] = $scheme;
                        } else {
                            // домен
                            $meta['domain'] = $host;
                            $meta['protocol'] = $scheme;
                        }
                    }
                }
                // Если ip пустой, но есть явные поля
                if (empty($ip) && !empty($nodeData['ip_address'])) {
                    $ip = $nodeData['ip_address'];
                }
                if (!empty($nodeData['port'])) {
                    $port = (int)$nodeData['port'];
                }
                
                // Приводим metadata к JSON
                $metadataJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
                
                // Вставка/обновление по уникальному комбинационному ключу (ip_address, port) или node_id, если он есть
                if (!empty($nodeId)) {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO nodes (node_id, ip_address, port, public_key, version, status, last_seen, reputation_score, metadata)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            ip_address = VALUES(ip_address),
                            port = VALUES(port),
                            public_key = VALUES(public_key),
                            version = VALUES(version),
                            status = VALUES(status),
                            last_seen = VALUES(last_seen),
                            reputation_score = VALUES(reputation_score),
                            metadata = VALUES(metadata)
                    ");
                    $stmt->execute([
                        $nodeId,
                        $ip ?: ($meta['domain'] ?? ''),
                        $port,
                        $publicKey,
                        $version,
                        $status,
                        $lastSeen,
                        (int)$reputation,
                        $metadataJson
                    ]);
                } else {
                    // без node_id — используем уникальность ip+port
                    $stmt = $this->pdo->prepare("
                        INSERT INTO nodes (node_id, ip_address, port, public_key, version, status, last_seen, reputation_score, metadata)
                        VALUES (SHA2(CONCAT(?,':',?), 256), ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            public_key = VALUES(public_key),
                            version = VALUES(version),
                            status = VALUES(status),
                            last_seen = VALUES(last_seen),
                            reputation_score = VALUES(reputation_score),
                            metadata = VALUES(metadata)
                    ");
                    $stmt->execute([
                        $ip ?: ($meta['domain'] ?? 'unknown'),
                        (string)$port,
                        $ip ?: ($meta['domain'] ?? ''),
                        $port,
                        $publicKey,
                        $version,
                        $status,
                        $lastSeen,
                        (int)$reputation,
                        $metadataJson
                    ]);
                }
                
                $syncedNodes++;
            }
            $this->log("Synced $syncedNodes nodes (mapped to schema)");
        }
        
        // Sync validators -> схема validators по дампу:
        // (address, public_key, stake, delegated_stake, commission_rate, status, blocks_produced, blocks_missed, last_active_block, jail_until_block, metadata, created_at, updated_at)
        $url = rtrim($node, '/') . '/api/explorer/index.php?action=get_validators_list';
        $response = $this->makeApiCall($url);
        
        if ($response && isset($response['success']) && $response['success']) {
            $validators = $response['data'] ?? [];
            $syncedValidators = 0;
            
            foreach ($validators as $v) {
                $address         = $v['address'] ?? ($v['public_key'] ?? ($v['validator'] ?? ''));
                $publicKey       = $v['public_key'] ?? '';
                $stake           = $v['stake'] ?? 0;
                $delegatedStake  = $v['delegated_stake'] ?? 0;
                $commissionRate  = $v['commission_rate'] ?? 0;
                $status          = $v['status'] ?? 'inactive';
                $blocksProduced  = $v['blocks_produced'] ?? 0;
                $blocksMissed    = $v['blocks_missed'] ?? 0;
                $lastActiveBlock = $v['last_active_block'] ?? 0;
                $jailUntilBlock  = $v['jail_until_block'] ?? null;
                $metadata        = $v['metadata'] ?? null;
                if (is_array($metadata)) {
                    $metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE);
                }
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO validators
                        (address, public_key, stake, delegated_stake, commission_rate, status, blocks_produced, blocks_missed, last_active_block, jail_until_block, metadata)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        public_key = VALUES(public_key),
                        stake = VALUES(stake),
                        delegated_stake = VALUES(delegated_stake),
                        commission_rate = VALUES(commission_rate),
                        status = VALUES(status),
                        blocks_produced = VALUES(blocks_produced),
                        blocks_missed = VALUES(blocks_missed),
                        last_active_block = VALUES(last_active_block),
                        jail_until_block = VALUES(jail_until_block),
                        metadata = VALUES(metadata)
                ");
                $stmt->execute([
                    $address,
                    $publicKey,
                    $stake,
                    $delegatedStake,
                    $commissionRate,
                    $status,
                    $blocksProduced,
                    $blocksMissed,
                    $lastActiveBlock,
                    $jailUntilBlock,
                    $metadata
                ]);
                $syncedValidators++;
            }
            $this->log("Synced $syncedValidators validators (mapped to schema)");
        }
    }
    
    private function syncBlocks($node) {
        // Get current local height
        $stmt = $this->pdo->prepare("SELECT COALESCE(MAX(height), -1) as max_height FROM blocks");
        $stmt->execute();
        $localHeight = $stmt->fetchColumn();
        
        $this->log("Local blockchain height: $localHeight");
        
        $totalSynced = 0;
        $page = 0;
        $limit = 100;
        
        do {
            $url = rtrim($node, '/') . "/api/explorer/index.php?action=get_blocks&page=$page&limit=$limit";
            $response = $this->makeApiCall($url);
            
            if (!$response || !isset($response['success']) || !$response['success']) {
                $this->log("No more blocks to sync (page $page)");
                break;
            }
            
            $blocks = $response['data']['blocks'] ?? [];
            if (empty($blocks)) {
                $this->log("No blocks found on page $page");
                break;
            }
            
            $newBlocks = 0;
            foreach ($blocks as $block) {
                if ($block['height'] <= $localHeight) continue;
                
                $stmt = $this->pdo->prepare("
                    INSERT IGNORE INTO blocks (height, hash, previous_hash, merkle_root, timestamp, nonce, difficulty, creator, signature)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $result = $stmt->execute([
                    $block['height'],
                    $block['hash'],
                    $block['previous_hash'] ?? '',
                    $block['merkle_root'] ?? '',
                    $block['timestamp'],
                    $block['nonce'] ?? 0,
                    $block['difficulty'] ?? 1,
                    $block['creator'] ?? '',
                    $block['signature'] ?? ''
                ]);
                
                if ($result && $stmt->rowCount() > 0) {
                    $newBlocks++;
                }
            }
            
            $totalSynced += $newBlocks;
            $this->log("Page $page: synced $newBlocks new blocks");
            
            if ($this->isWebMode) {
                echo json_encode(['status' => 'progress', 'message' => "Synced $totalSynced blocks"]) . "\n";
                flush();
            }
            
            $page++;
            
        } while (count($blocks) == $limit);
        
        $this->log("Total blocks synced: $totalSynced");
        return $totalSynced;
    }
    
    private function syncTransactions($node) {
        $totalSynced = 0;
        $page = 0;
        $limit = 100;
        
        do {
            $url = rtrim($node, '/') . "/api/explorer/index.php?action=get_transactions&page=$page&limit=$limit";
            $response = $this->makeApiCall($url);
            
            if (!$response || !isset($response['success']) || !$response['success']) {
                break;
            }
            
            $transactions = $response['data']['transactions'] ?? [];
            if (empty($transactions)) break;
            
            $newTransactions = 0;
            foreach ($transactions as $tx) {
                $stmt = $this->pdo->prepare("
                    INSERT IGNORE INTO transactions (hash, from_address, to_address, amount, fee, timestamp, block_hash, status, nonce, gas_limit)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $result = $stmt->execute([
                    $tx['hash'],
                    $tx['from_address'] ?? '',
                    $tx['to_address'] ?? '',
                    $tx['amount'] ?? 0,
                    $tx['fee'] ?? 0,
                    $tx['timestamp'],
                    $tx['block_hash'] ?? '',
                    $tx['status'] ?? 'confirmed',
                    $tx['nonce'] ?? 0,
                    $tx['gas_limit'] ?? 21000
                ]);
                
                if ($result && $stmt->rowCount() > 0) {
                    $newTransactions++;
                }
            }
            
            $totalSynced += $newTransactions;
            $this->log("Page $page: synced $newTransactions new transactions");
            
            if ($this->isWebMode) {
                echo json_encode(['status' => 'progress', 'message' => "Synced $totalSynced transactions"]) . "\n";
                flush();
            }
            
            $page++;
            
        } while (count($transactions) == $limit);
        
        $this->log("Total transactions synced: $totalSynced");
        return $totalSynced;
    }
    
    private function syncAdditionalData($node) {
        // Sync smart contracts with proper pagination
        $contractsSynced = 0;
        $page = 0;
        $limit = 100;
        
        do {
            $url = rtrim($node, '/') . "/api/explorer/index.php?action=get_smart_contracts&page=$page&limit=$limit";
            $response = $this->makeApiCall($url);
            
            if (!$response || !isset($response['success']) || !$response['success']) {
                $this->log("No smart contracts data or end of pages reached (page $page)");
                break;
            }
            
            $contracts = $response['data']['contracts'] ?? [];
            if (empty($contracts)) {
                $this->log("No smart contracts found on page $page");
                break;
            }
            
            $newContracts = 0;
            foreach ($contracts as $contract) {
                $stmt = $this->pdo->prepare("
                    INSERT IGNORE INTO smart_contracts (address, creator, bytecode, abi, created_at, transaction_hash)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $result = $stmt->execute([
                    $contract['address'] ?? '',
                    $contract['creator'] ?? '',
                    $contract['bytecode'] ?? '',
                    $contract['abi'] ?? '',
                    $contract['created_at'] ?? date('Y-m-d H:i:s'),
                    $contract['transaction_hash'] ?? ''
                ]);
                
                if ($result && $stmt->rowCount() > 0) {
                    $newContracts++;
                }
            }
            
            $contractsSynced += $newContracts;
            $this->log("Page $page: synced $newContracts smart contracts");
            $page++;
            
        } while (count($contracts) == $limit);
        
        $this->log("Total smart contracts synced: $contractsSynced");
        
        // Sync staking records with proper pagination
        $stakesSynced = 0;
        $page = 0;
        
        do {
            $url = rtrim($node, '/') . "/api/explorer/index.php?action=get_staking_records&page=$page&limit=$limit";
            $response = $this->makeApiCall($url);
            
            if (!$response || !isset($response['success']) || !$response['success']) {
                $this->log("No staking data or end of pages reached (page $page)");
                break;
            }
            
            $stakes = $response['data']['staking'] ?? [];
            if (empty($stakes)) {
                $this->log("No staking records found on page $page");
                break;
            }
            
            $newStakes = 0;
            foreach ($stakes as $stake) {
                $stmt = $this->pdo->prepare("
                    INSERT IGNORE INTO staking (validator_address, staker_address, amount, status, staked_at, rewards)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $result = $stmt->execute([
                    $stake['validator_address'] ?? '',
                    $stake['staker_address'] ?? '',
                    $stake['amount'] ?? 0,
                    $stake['status'] ?? 'active',
                    $stake['staked_at'] ?? date('Y-m-d H:i:s'),
                    $stake['rewards'] ?? 0
                ]);
                
                if ($result && $stmt->rowCount() > 0) {
                    $newStakes++;
                }
            }
            
            $stakesSynced += $newStakes;
            $this->log("Page $page: synced $newStakes staking records");
            $page++;
            
        } while (count($stakes) == $limit);
        
        $this->log("Total staking records synced: $stakesSynced");
    }
    
    private function makeApiCall($url, $timeout = 30) {
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'method' => 'GET',
                'header' => "User-Agent: NetworkSyncManager/1.0\r\n"
            ]
        ]);
        
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            return null;
        }
        
        return json_decode($result, true);
    }
    
    private function updateProgress($current, $total, $message) {
        $percent = round(($current / $total) * 100);
        
        if ($this->isWebMode) {
            echo json_encode([
                'status' => 'progress',
                'percent' => $percent,
                'current' => $current,
                'total' => $total,
                'message' => $message
            ]) . "\n";
            flush();
        } else {
            echo sprintf("[%d/%d] %d%% - %s\n", $current, $total, $percent, $message);
        }
        
        $this->log("Progress: $current/$total ($percent%) - $message");
    }
    
    private function log($message) {
        $logEntry = date('Y-m-d H:i:s') . " - " . $message . "\n";
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        if (!$this->isWebMode) {
            echo $logEntry;
        }
    }
    
    public function getStatus() {
        // Get database statistics
        $tables = ['blocks', 'transactions', 'nodes', 'validators', 'smart_contracts', 'staking'];
        $stats = [];
        
        foreach ($tables as $table) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM $table");
            $stmt->execute();
            $stats[$table] = $stmt->fetchColumn();
        }
        
        // Get latest block info
        $stmt = $this->pdo->prepare("SELECT MAX(height) as height, MAX(timestamp) as latest FROM blocks");
        $stmt->execute();
        $blockInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'tables' => $stats,
            'latest_block' => $blockInfo['height'] ?? 0,
            'latest_timestamp' => $blockInfo['latest'] ?? null,
            'sync_time' => date('Y-m-d H:i:s')
        ];
    }
}

// CLI Mode
if (php_sapi_name() === 'cli') {
    echo "=== Network Blockchain Synchronization Manager ===\n";
    
    $command = $argv[1] ?? 'sync';
    
    try {
        $syncManager = new NetworkSyncManager(false);
        
        switch ($command) {
            case 'sync':
                echo "Starting full synchronization...\n\n";
                $result = $syncManager->syncAll();
                
                echo "\n=== Synchronization Summary ===\n";
                echo "Status: " . $result['status'] . "\n";
                echo "Node: " . $result['node'] . "\n";
                echo "Blocks synced: " . $result['blocks_synced'] . "\n";
                echo "Transactions synced: " . $result['transactions_synced'] . "\n";
                echo "Completed at: " . $result['completion_time'] . "\n";
                break;
                
            case 'status':
                echo "Getting synchronization status...\n\n";
                $status = $syncManager->getStatus();
                
                echo "=== Database Status ===\n";
                foreach ($status['tables'] as $table => $count) {
                    echo sprintf("%-20s: %d records\n", ucfirst($table), $count);
                }
                echo "\n";
                echo "Latest block: " . $status['latest_block'] . "\n";
                echo "Latest timestamp: " . ($status['latest_timestamp'] ?? 'Unknown') . "\n";
                echo "Check time: " . $status['sync_time'] . "\n";
                break;
                
            default:
                echo "Usage: php network_sync.php [sync|status]\n";
                echo "  sync   - Perform full blockchain synchronization\n";
                echo "  status - Show current database status\n";
                break;
        }
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Web Mode (for AJAX calls)
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        $syncManager = new NetworkSyncManager(true);
        
        switch ($_GET['action']) {
            case 'sync':
                $result = $syncManager->syncAll();
                echo json_encode($result);
                break;
                
            case 'status':
                $status = $syncManager->getStatus();
                echo json_encode(['status' => 'success', 'data' => $status]);
                break;
                
            default:
                echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
                break;
        }
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
}
?>

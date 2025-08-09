<?php
declare(strict_types=1);
/**
 * Network Blockchain Synchronization Manager
 * Standalone script for syncing blockchain data from network nodes
 * Can be used for initial sync or recovery after failures
 */

require_once 'vendor/autoload.php';
require_once 'config/config.php';

if (!class_exists('NetworkSyncManager')) {
class NetworkSyncManager {
    private $pdo;
    private $config;
    private $logFile;
    private $isWebMode;
    private $loggingEnabled; // Controls whether logs are written to disk
    
    public function __construct($webMode = false) {
        $this->isWebMode = $webMode;
        $this->logFile = 'logs/network_sync.log';
        // Default: disable disk logging to prevent excessive disk usage
        $this->loggingEnabled = false;
        $this->initializeDatabase();
        $this->loadConfig();
    }

    /**
     * Parse various truthy/falsy representations to boolean.
     */
    private function boolFrom($value, bool $default = false): bool {
        if ($value === null) return $default;
        if (is_bool($value)) return $value;
        $v = strtolower(trim((string)$value));
        if ($v === '') return $default;
        return in_array($v, ['1','true','yes','on','y','enabled'], true);
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
            // 1) Try installation.json (same format as in sync-service)
            $installConfig = __DIR__ . '/config/installation.json';
            if (file_exists($installConfig)) {
                $data = json_decode(file_get_contents($installConfig), true);
                if (is_array($data) && isset($data['network_nodes'])) {
                    $this->config = [
                        'network_nodes' => $data['network_nodes'],
                        'node_selection_strategy' => $data['node_selection_strategy'] ?? 'fastest_response'
                    ];
                    // Optional toggle in installation.json: "logging_enabled": true|false
                    $this->loggingEnabled = $this->boolFrom($data['logging_enabled'] ?? ($data['sync_logging_enabled'] ?? null), false);
                    $this->log("Configuration loaded successfully from installation.json");
                    return;
                }
            }

            // 2) New configuration model in the config table (same as sync-service/SyncManager)
            $stmt = $this->pdo->prepare("SELECT key_name, value FROM config WHERE key_name LIKE 'network.%' OR key_name LIKE 'node.%'");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $values = [];
            foreach ($rows as $row) {
                $values[$row['key_name']] = $row['value'];
            }

            $networkNodes = $values['network.nodes'] ?? '';
            $selectionStrategy = $values['node.selection_strategy'] ?? 'fastest_response';

            // 3) Legacy fallback: row id=1 with settings JSON column
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

            // Determine logging toggle from config table or environment
            $logFlag = $values['sync.logging_enabled']
                ?? $values['logging.sync_enabled']
                ?? $values['logging.enabled']
                ?? ($_ENV['SYNC_LOGGING'] ?? ($_ENV['SYNC_LOGGING_ENABLED'] ?? ($_ENV['LOGGING_ENABLED'] ?? null)));
            $this->loggingEnabled = $this->boolFrom($logFlag, $this->loggingEnabled);

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
        $totalSteps = 10; // + wallets sync step
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

            // Step 7: Sync wallets and balances
            $currentStep++;
            $this->updateProgress($currentStep, $totalSteps, "Syncing wallets and balances...");
            $walletsSynced = $this->syncWallets($bestNode);

            // Step 8: Quorum check of last hashes and penalize suspicious source
            $currentStep++;
            $this->updateProgress($currentStep, $totalSteps, "Validating hashes with peers (quorum check)...");
            $this->quorumCheckAndPenalize($bestNode, 20, 3, 10);
            
            // Step 9: Mempool synchronization from network
            $currentStep++;
            $this->updateProgress($currentStep, $totalSteps, "Synchronizing mempool from network...");
            $mempoolSynced = $this->syncMempoolFromNetwork($bestNode);
            $this->log("Mempool sync completed: $mempoolSynced transactions synchronized");

            // Step 10: Mempool cleanup (TTL + confirmed removal)
            $currentStep++;
            $this->updateProgress($currentStep, $totalSteps, "Cleaning mempool (TTL and confirmed)...");
            $this->cleanupMempool();

            // Step 10: Sync additional data (smart contracts, staking)
            $currentStep++;
            $this->updateProgress($currentStep, $totalSteps, "Syncing smart contracts and staking...");
            $this->syncAdditionalData($bestNode);
            
            $this->updateProgress($totalSteps, $totalSteps, "Synchronization completed successfully!");
            
            $summary = [
                'status' => 'success',
                'node' => $bestNode,
                'blocks_synced' => $blocksSynced,
                'transactions_synced' => $transactionsSynced,
                'wallets_synced' => $walletsSynced,
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
        // Get current node domain to exclude from sync
        $currentNodeUrl = $this->getCurrentNodeDomain();
        
    // 1) Try to get active nodes from the nodes table (primary source after installation)
        // Exclude nodes with low reputation (below 50) to avoid suspicious sources
        $nodeUrls = [];
        try {
            $stmt = $this->pdo->prepare("SELECT ip_address, port, metadata FROM nodes WHERE status = 'active' AND reputation_score >= 50");
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
                
                // Exclude current node from sync
                if ($currentNodeUrl && $this->isSameNode($url, $currentNodeUrl)) {
                    $this->log("Excluding current node from sync: $url");
                    continue;
                }
                
                $nodeUrls[] = $url;
            }
        } catch (\Throwable $e) {
            $this->log("Warning: failed to read nodes table: " . $e->getMessage());
        }

    // 2) Fallback: network_nodes config (multiline or CSV)
        if (empty($nodeUrls)) {
            $rawNodes = $this->config['network_nodes'] ?? '';
            $candidates = preg_split('/[\r\n,]+/', (string)$rawNodes);
            $filteredNodes = array_values(array_filter(array_map('trim', $candidates)));
            
            // Exclude current node from fallback list
            foreach ($filteredNodes as $node) {
                if ($currentNodeUrl && $this->isSameNode($node, $currentNodeUrl)) {
                    $this->log("Excluding current node from sync (fallback): $node");
                    continue;
                }
                $nodeUrls[] = $node;
            }
        }

        if (empty($nodeUrls)) {
            throw new Exception('No external network nodes available for synchronization. Cannot sync with self.');
        }

        $strategy = $this->config['node_selection_strategy'] ?? 'longest_chain';
        $this->log("Testing " . count($nodeUrls) . " network nodes with strategy: $strategy");

        $nodeResults = [];
        foreach ($nodeUrls as $node) {
            $result = $this->testNode($node);
            $nodeResults[] = array_merge(['url' => $node], $result);
            
            if ($result['accessible']) {
                $height = $result['block_height'] ?? 0;
                $this->log("Node $node: OK (height: $height, {$result['response_time']}ms)");
            } else {
                $this->log("Node $node: FAIL - {$result['error']}");
            }
        }

        $accessibleNodes = array_filter($nodeResults, fn($n) => $n['accessible']);
        if (empty($accessibleNodes)) {
            throw new Exception("No accessible external nodes found for synchronization");
        }

        // Always prioritize longest chain to prevent forks
        if ($strategy === 'longest_chain' || $strategy === 'fastest_response' || $strategy === 'consensus_majority') {
            // Sort by block height first (descending), then by response time (ascending)
            usort($accessibleNodes, function($a, $b) {
                $heightA = $a['block_height'] ?? 0;
                $heightB = $b['block_height'] ?? 0;
                
                if ($heightA !== $heightB) {
                    return $heightB <=> $heightA; // Higher height wins (longest chain rule)
                }
                
                // If heights are equal, use faster response
                return $a['response_time'] <=> $b['response_time'];
            });
            
            // Debug: log sorting results
            $this->log("Nodes after sorting by height (descending):");
            foreach ($accessibleNodes as $i => $node) {
                $this->log("  $i: {$node['url']} - height: {$node['block_height']}, time: {$node['response_time']}ms");
            }
        }

        $bestNode = $accessibleNodes[0];
        $height = $bestNode['block_height'] ?? 0;
        $this->log("Selected best node: {$bestNode['url']} (height: $height, strategy: $strategy)");
        return $bestNode['url'];
    }
    
    private function testNode($nodeUrl) {
        // Test with network stats endpoint which includes block height
        $endpoints = [
            '/api/explorer/index.php?action=get_network_stats',
            '/api/explorer/index.php?action=get_network_config',
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
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($result === false) {
                $errors[] = "Connection failed: $testUrl";
                continue;
            }

            $data = json_decode($result, true);
            if (!$data) {
                $errors[] = "Invalid JSON: $testUrl";
                continue;
            }

            // Check for different response formats
            $blockHeight = null;
            
            if (isset($data['current_height'])) {
                // Direct network stats format
                $blockHeight = (int)$data['current_height'];
                $ok = true;
            } elseif (isset($data['success']) && $data['success'] === true) {
                // API wrapper format
                if (isset($data['data']['current_height'])) {
                    $blockHeight = (int)$data['data']['current_height'];
                } elseif (isset($data['data']['block_height'])) {
                    $blockHeight = (int)$data['data']['block_height'];
                }
                $ok = true;
            } elseif (isset($data['network_name']) || isset($data['data'])) {
                // Legacy config format - try to get height separately
                $ok = true;
                $blockHeight = 0; // Default if we can't get height
            } else {
                $errors[] = "Unexpected response format: $testUrl";
                continue;
            }
            
            if ($ok) {
                $best = [
                    'accessible' => true,
                    'response_time' => $responseTime,
                    'block_height' => $blockHeight,
                    'error' => null
                ];
                break;
            }
        }

        if ($best) {
            return $best;
        }

        return [
            'accessible' => false,
            'response_time' => null,
            'block_height' => 0,
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
                INSERT INTO blocks (height, hash, parent_hash, merkle_root, timestamp, validator, signature, transactions_count, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                0,
                $block['hash'],
                '',
                $block['merkle_root'] ?? '',
                $block['timestamp'],
                $block['validator'] ?? '',
                $block['signature'] ?? '',
                $block['transactions_count'] ?? 0,
                $block['metadata'] ?? '{}'
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
        // Sync nodes -> adapt to your schema nodes (id, node_id, ip_address, port, public_key, version, status, last_seen, blocks_synced, ping_time, reputation_score, metadata, created_at, updated_at)
        $url = rtrim($node, '/') . '/api/explorer/index.php?action=get_nodes_list';
        $response = $this->makeApiCall($url);
        
        if ($response && isset($response['success']) && $response['success']) {
            $nodes = $response['data'] ?? [];
            $syncedNodes = 0;
            
            foreach ($nodes as $nodeData) {
                // Map input fields to your schema
                $nodeId = $nodeData['node_id'] ?? ($nodeData['address'] ?? '');
                $version = $nodeData['version'] ?? '1.0.0';
                $status = $nodeData['status'] ?? 'active';
                $lastSeen = $nodeData['last_seen'] ?? date('Y-m-d H:i:s');
                $reputation = $nodeData['reputation_score'] ?? 100;
                $publicKey = $nodeData['public_key'] ?? '';
                
                // Determine ip/port/protocol/domain from url/metadata
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
                        // host can be a domain name or an IP address
                        if (filter_var($host, FILTER_VALIDATE_IP)) {
                            $ip = $host;
                            $meta['protocol'] = $scheme;
                        } else {
                            // domain name
                            $meta['domain'] = $host;
                            $meta['protocol'] = $scheme;
                        }
                    }
                }
                // If IP is empty but explicit fields exist
                if (empty($ip) && !empty($nodeData['ip_address'])) {
                    $ip = $nodeData['ip_address'];
                }
                if (!empty($nodeData['port'])) {
                    $port = (int)$nodeData['port'];
                }
                
                // Convert metadata to JSON
                $metadataJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
                
                // Insert/update using unique composite key (ip_address, port) or node_id if present
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
                    // Without node_id — use uniqueness of ip+port
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
        
    // Sync validators -> validators schema per dump:
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
        
        // Get remote height to check for fork resolution
        $remoteStatsUrl = rtrim($node, '/') . '/api/explorer/index.php?action=get_network_stats';
        $remoteStats = $this->makeApiCall($remoteStatsUrl);
        $remoteHeight = $remoteStats['current_height'] ?? 0;
        
        $this->log("Remote blockchain height: $remoteHeight");
        
        // Check for potential fork - if we have blocks that differ from remote
        $forkDetected = false;
        if ($localHeight >= 0 && $remoteHeight > $localHeight) {
            // Check if our latest blocks match the remote ones
            for ($h = max(0, $localHeight - 5); $h <= $localHeight; $h++) {
                $localBlock = $this->getLocalBlock($h);
                if ($localBlock) {
                    $remoteBlockUrl = rtrim($node, '/') . "/api/explorer/index.php?action=get_block&block_id=$h";
                    $remoteBlockResponse = $this->makeApiCall($remoteBlockUrl);
                    
                    if ($remoteBlockResponse && isset($remoteBlockResponse['success']) && $remoteBlockResponse['success']) {
                        $remoteBlock = $remoteBlockResponse['data'];
                        if ($localBlock['hash'] !== $remoteBlock['hash']) {
                            $this->log("Fork detected at height $h: local={$localBlock['hash']}, remote={$remoteBlock['hash']}");
                            $forkDetected = true;
                            break;
                        }
                    }
                }
            }
        }
        
        // If fork detected and remote chain is longer, replace our chain from fork point
        if ($forkDetected && $remoteHeight > $localHeight) {
            $this->log("Fork resolution: Remote chain is longer, replacing local blocks from fork point");
            
            // Find the common ancestor
            $commonHeight = -1;
            for ($h = 0; $h <= min($localHeight, $remoteHeight); $h++) {
                $localBlock = $this->getLocalBlock($h);
                if ($localBlock) {
                    $remoteBlockUrl = rtrim($node, '/') . "/api/explorer/index.php?action=get_block&block_id=$h";
                    $remoteBlockResponse = $this->makeApiCall($remoteBlockUrl);
                    
                    if ($remoteBlockResponse && isset($remoteBlockResponse['success']) && $remoteBlockResponse['success']) {
                        $remoteBlock = $remoteBlockResponse['data'];
                        if ($localBlock['hash'] === $remoteBlock['hash']) {
                            $commonHeight = $h;
                        } else {
                            break;
                        }
                    }
                }
            }
            
            // Remove blocks after common ancestor
            if ($commonHeight >= 0) {
                $this->log("Common ancestor found at height $commonHeight, removing blocks above this height");
                $stmt = $this->pdo->prepare("DELETE FROM blocks WHERE height > ?");
                $stmt->execute([$commonHeight]);
                $removedBlocks = $stmt->rowCount();
                $this->log("Removed $removedBlocks blocks from local chain");
                
                // Also remove related transactions
                $stmt = $this->pdo->prepare("DELETE FROM transactions WHERE block_hash NOT IN (SELECT hash FROM blocks)");
                $stmt->execute();
                $removedTxs = $stmt->rowCount();
                $this->log("Removed $removedTxs orphaned transactions");
                
                // Update local height
                $localHeight = $commonHeight;
            }
        }
        
        $totalSynced = 0;
        
        // Try to sync remaining blocks individually since get_blocks API doesn't work
        for ($h = $localHeight + 1; $h <= $remoteHeight; $h++) {
            $blockUrl = rtrim($node, '/') . "/api/explorer/index.php?action=get_block&block_id=$h";
            $blockResponse = $this->makeApiCall($blockUrl);
            
            if ($blockResponse && isset($blockResponse['success']) && $blockResponse['success']) {
                $block = $blockResponse['data'];
                
                $stmt = $this->pdo->prepare("
                    INSERT IGNORE INTO blocks (height, hash, parent_hash, merkle_root, timestamp, validator, signature, transactions_count, metadata)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $result = $stmt->execute([
                    $block['height'],
                    $block['hash'],
                    $block['parent_hash'] ?? '',
                    $block['merkle_root'] ?? '',
                    $block['timestamp'],
                    $block['validator'] ?? '',
                    $block['signature'] ?? '',
                    $block['transactions_count'] ?? 0,
                    $block['metadata'] ?? '{}'
                ]);
                
                if ($result && $stmt->rowCount() > 0) {
                    $totalSynced++;
                    $this->log("Synced block $h: {$block['hash']}");
                    
                    if ($this->isWebMode) {
                        echo json_encode(['status' => 'progress', 'message' => "Synced block $h"]) . "\n";
                        flush();
                    }
                } else {
                    $this->log("Block $h already exists or insert failed");
                }
            } else {
                $this->log("Failed to fetch block $h from remote node");
                break;
            }
        }
        
        // Fallback: Try paginated API if individual block fetching didn't work completely
        if ($totalSynced < ($remoteHeight - $localHeight)) {
            $this->log("Trying paginated block sync as fallback");
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
                        INSERT IGNORE INTO blocks (height, hash, parent_hash, merkle_root, timestamp, validator, signature, transactions_count, metadata)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $result = $stmt->execute([
                        $block['height'],
                        $block['hash'],
                        $block['parent_hash'] ?? '',
                        $block['merkle_root'] ?? '',
                        $block['timestamp'],
                        $block['validator'] ?? '',
                        $block['signature'] ?? '',
                        $block['transactions_count'] ?? 0,
                        $block['metadata'] ?? '{}'
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
        }
        
        $this->log("Total blocks synced: $totalSynced");
        return $totalSynced;
    }
    
    private function getLocalBlock($height) {
        $stmt = $this->pdo->prepare("SELECT height, hash, parent_hash FROM blocks WHERE height = ? LIMIT 1");
        $stmt->execute([$height]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function syncTransactions($node) {
        $this->log("Starting direct transaction sync from paginated API...");
        $totalSynced = 0;
        
        // Use paginated transactions API directly instead of extracting from blocks
        $page = 0;
        $limit = 100;
        
        do {
            $url = rtrim($node, '/') . "/api/explorer/transactions?page=$page&limit=$limit";
            $response = $this->makeApiCall($url);
            
            if (!$response || !isset($response['transactions'])) {
                $this->log("No transaction data available from node (page $page)");
                break;
            }
            
            $transactions = $response['transactions'] ?? [];
            if (empty($transactions)) break;
            
            $newTransactions = 0;
            foreach ($transactions as $tx) {
                $txHash = $tx['hash'] ?? '';
                $fromAddr = $tx['from'] ?? '';
                $toAddr = $tx['to'] ?? '';
                $amount = $tx['amount'] ?? 0;
                $timestamp = $tx['timestamp'] ?? time();
                $blockHash = $tx['block_hash'] ?? '';
                $blockHeight = $tx['block_index'] ?? 0;
                $status = $tx['status'] ?? 'confirmed';
                $type = $tx['type'] ?? '';
                
                if (!empty($txHash)) {
                    // Insert transaction with all available data
                    $stmt = $this->pdo->prepare("
                        INSERT IGNORE INTO transactions 
                        (hash, from_address, to_address, amount, fee, timestamp, block_hash, block_height, status, nonce, gas_limit, gas_used, gas_price, data, signature)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $result = $stmt->execute([
                        $txHash,
                        $fromAddr,
                        $toAddr,
                        $amount,
                        0, // fee - will be 0 for now
                        $timestamp,
                        $blockHash,
                        $blockHeight,
                        $status,
                        0, // nonce
                        21000, // gas_limit
                        0, // gas_used
                        0, // gas_price
                        json_encode(['type' => $type]), // data
                        '' // signature
                    ]);
                    
                    if ($result && $stmt->rowCount() > 0) {
                        $newTransactions++;
                    }
                }
            }
            
            $totalSynced += $newTransactions;
            $this->log("Page $page: synced $newTransactions new transactions");
            
            if ($this->isWebMode) {
                echo json_encode(['status' => 'progress', 'message' => "Page $page: synced $newTransactions transactions"]) . "\n";
                flush();
            }
            
            $page++;
            
        } while (count($transactions) == $limit);
        
        $this->log("Total transactions synced: $totalSynced");
        return $totalSynced;
    }
    
    /**
     * Synchronize mempool transactions from network nodes
     */
    private function syncMempoolFromNetwork($sourceNode = null): int {
        $this->log("Starting mempool synchronization from network...");
        $totalSynced = 0;
        
        $nodes = $sourceNode ? [$sourceNode] : $this->getActiveNodes();
        
        foreach ($nodes as $node) {
            try {
                $this->log("Syncing mempool from node: $node");
                
                // Get mempool from remote node
                $url = rtrim($node, '/') . '/api/explorer/index.php?action=get_mempool';
                $response = $this->makeApiCall($url);
                
                if (!$response || !isset($response['success']) || !$response['success']) {
                    $this->log("Failed to get mempool from $node");
                    continue;
                }
                
                $transactions = $response['data'] ?? [];
                $nodeSynced = 0;
                
                foreach ($transactions as $tx) {
                    $txHash = $tx['tx_hash'] ?? '';
                    if (empty($txHash)) continue;
                    
                    // Check if transaction already exists in our mempool or is confirmed
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(*) FROM mempool WHERE tx_hash = ?
                        UNION ALL
                        SELECT COUNT(*) FROM transactions WHERE hash = ?
                    ");
                    $stmt->execute([$txHash, $txHash]);
                    $exists = array_sum($stmt->fetchAll(PDO::FETCH_COLUMN));
                    
                    if ($exists > 0) {
                        continue; // Transaction already exists
                    }
                    
                    // Validate transaction structure
                    $fromAddr = $tx['from_address'] ?? '';
                    $toAddr = $tx['to_address'] ?? '';
                    $amount = $tx['amount'] ?? 0;
                    $fee = $tx['fee'] ?? 0;
                    $signature = $tx['signature'] ?? '';
                    $data = $tx['data'] ?? '';
                    $nonce = $tx['nonce'] ?? 0;
                    $gasLimit = $tx['gas_limit'] ?? 21000;
                    $gasPrice = $tx['gas_price'] ?? 0;
                    
                    // Insert into local mempool
                    $stmt = $this->pdo->prepare("
                        INSERT IGNORE INTO mempool 
                        (tx_hash, from_address, to_address, amount, fee, gas_price, gas_limit, nonce, data, signature, priority_score, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                    ");
                    
                    $priorityScore = $this->calculatePriorityScore($amount, $fee);
                    
                    $result = $stmt->execute([
                        $txHash,
                        $fromAddr,
                        $toAddr,
                        $amount,
                        $fee,
                        $gasPrice,
                        $gasLimit,
                        $nonce,
                        $data,
                        $signature,
                        $priorityScore
                    ]);
                    
                    if ($result && $stmt->rowCount() > 0) {
                        $nodeSynced++;
                        $this->log("Synced transaction: $txHash from $node");
                    }
                }
                
                $totalSynced += $nodeSynced;
                $this->log("Synced $nodeSynced transactions from $node");
                
            } catch (\Exception $e) {
                $this->log("Error syncing mempool from $node: " . $e->getMessage());
            }
        }
        
        $this->log("Total mempool transactions synced: $totalSynced");
        return $totalSynced;
    }
    
    /**
     * Calculate priority score for mempool transaction
     */
    private function calculatePriorityScore($amount, $fee): float {
        $amount = (float)$amount;
        $fee = (float)$fee;
        
        // Base score from fee
        $score = $fee * 10;
        
        // Bonus for larger amounts (up to 100 points)
        if ($amount > 0) {
            $score += min(100, log10($amount + 1) * 20);
        }
        
        return round($score, 2);
    }
    
    /**
     * Enhanced mempool synchronization and processing
     */
    public function enhancedMempoolSync(): array {
        $this->log("=== Starting Enhanced Mempool Synchronization ===");
        
        try {
            // Step 1: Sync mempool from all network nodes
            $this->log("Step 1: Synchronizing mempool from network...");
            $synced = $this->syncMempoolFromNetwork();
            
            // Step 2: Clean up expired/invalid transactions
            $this->log("Step 2: Cleaning up mempool...");
            $this->cleanupMempool();
            
            // Step 3: Check if we should mine pending transactions
            $this->log("Step 3: Checking for pending transactions to mine...");
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM mempool WHERE status = 'pending'");
            $pendingCount = $stmt->fetchColumn();
            
            $result = [
                'success' => true,
                'transactions_synced' => $synced,
                'pending_transactions' => $pendingCount,
                'mining_attempted' => false,
                'mining_result' => null
            ];
            
            // Step 4: If there are pending transactions and we're the mining leader, process them
            if ($pendingCount > 0 && $this->shouldThisNodeMine()) {
                $this->log("Step 4: Mining pending transactions...");
                $result['mining_attempted'] = true;
                
                $miningResult = $this->mineNewBlock(min($pendingCount, 100));
                $result['mining_result'] = $miningResult;
                
                if ($miningResult['success']) {
                    $this->log("Successfully mined block with {$pendingCount} transactions");
                    
                    // Broadcast the new block to network
                    $this->enhancedBlockBroadcast($miningResult['block']);
                }
            } else if ($pendingCount > 0) {
                $this->log("Found {$pendingCount} pending transactions, but this node is not the mining leader");
            } else {
                $this->log("No pending transactions found");
            }
            
            $this->log("Enhanced mempool sync completed: " . json_encode($result));
            return $result;
            
        } catch (Exception $e) {
            $error = "Enhanced mempool sync failed: " . $e->getMessage();
            $this->log($error);
            return [
                'success' => false,
                'error' => $error,
                'transactions_synced' => 0,
                'pending_transactions' => 0,
                'mining_attempted' => false
            ];
        }
    }
    
    /**
     * Propagate local mempool transaction to network
     */
    public function propagateTransactionToNetwork($txHash): bool {
        try {
            // Get transaction from local mempool
            $stmt = $this->pdo->prepare("SELECT * FROM mempool WHERE tx_hash = ? AND status = 'pending'");
            $stmt->execute([$txHash]);
            $tx = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tx) {
                $this->log("Transaction $txHash not found in local mempool");
                return false;
            }
            
            $nodes = $this->getActiveNodes();
            $propagated = 0;
            
            foreach ($nodes as $node) {
                try {
                    $url = rtrim($node, '/') . '/api/transactions/submit';
                    
                    $payload = [
                        'tx_hash' => $tx['tx_hash'],
                        'from_address' => $tx['from_address'],
                        'to_address' => $tx['to_address'],
                        'amount' => $tx['amount'],
                        'fee' => $tx['fee'],
                        'gas_price' => $tx['gas_price'],
                        'gas_limit' => $tx['gas_limit'],
                        'nonce' => $tx['nonce'],
                        'data' => $tx['data'],
                        'signature' => $tx['signature'],
                        'propagation' => true
                    ];
                    
                    $response = $this->makeApiCall($url, 'POST', $payload);
                    
                    if ($response && isset($response['success']) && $response['success']) {
                        $propagated++;
                        $this->log("Transaction $txHash propagated to $node");
                    }
                    
                } catch (\Exception $e) {
                    $this->log("Failed to propagate transaction $txHash to $node: " . $e->getMessage());
                }
            }
            
            $this->log("Transaction $txHash propagated to $propagated nodes");
            return $propagated > 0;
            
        } catch (\Exception $e) {
            $this->log("Error propagating transaction $txHash: " . $e->getMessage());
            return false;
        }
    }
    
    private function syncWallets($node) {
        $totalSynced = 0;
        $page = 0;
        $limit = 100;
        
        do {
            $url = rtrim($node, '/') . "/api/explorer/index.php?action=get_wallets&page=$page&limit=$limit";
            $response = $this->makeApiCall($url);
            
            if (!$response || !isset($response['success']) || !$response['success']) {
                $this->log("No wallet data available from node (page $page)");
                break;
            }
            
            $wallets = $response['data']['wallets'] ?? [];
            if (empty($wallets)) {
                $this->log("No wallets found on page $page");
                break;
            }
            
            $newWallets = 0;
            foreach ($wallets as $wallet) {
                // Only sync if wallet has some activity (balance > 0 or transactions)
                $balance = $wallet['balance'] ?? 0;
                $stakedBalance = $wallet['staked_balance'] ?? 0;
                $nonce = $wallet['nonce'] ?? 0;
                
                if ($balance > 0 || $stakedBalance > 0 || $nonce > 0) {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO wallets (address, public_key, balance, staked_balance, nonce)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            balance = VALUES(balance),
                            staked_balance = VALUES(staked_balance),
                            nonce = VALUES(nonce),
                            updated_at = CURRENT_TIMESTAMP
                    ");
                    $result = $stmt->execute([
                        $wallet['address'],
                        $wallet['public_key'] ?? '',
                        $balance,
                        $stakedBalance,
                        $nonce
                    ]);
                    
                    if ($result) {
                        $newWallets++;
                    }
                }
            }
            
            $totalSynced += $newWallets;
            $this->log("Page $page: synced $newWallets active wallets");
            
            if ($this->isWebMode) {
                echo json_encode(['status' => 'progress', 'message' => "Synced $totalSynced wallets"]) . "\n";
                flush();
            }
            
            $page++;
            
        } while (count($wallets) == $limit);
        
        $this->log("Total wallets synced: $totalSynced");
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
                $this->log("Smart contracts sync: no data or error at page $page");
                break;
            }

            // Explorer returns contracts array directly in data
            $contracts = $response['data'] ?? [];
            if (empty($contracts)) {
                $this->log("Smart contracts sync: empty page $page");
                break;
            }

            $newContracts = 0;
            // Prepare upsert statement aligned with schema in database/Migration.php
            $stmt = $this->pdo->prepare("
                INSERT INTO smart_contracts (
                    address, creator, name, version, bytecode, abi, source_code,
                    deployment_tx, deployment_block, gas_used, status, storage, metadata
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    name=VALUES(name),
                    version=VALUES(version),
                    bytecode=VALUES(bytecode),
                    abi=VALUES(abi),
                    source_code=VALUES(source_code),
                    deployment_tx=VALUES(deployment_tx),
                    deployment_block=VALUES(deployment_block),
                    gas_used=VALUES(gas_used),
                    status=VALUES(status),
                    storage=VALUES(storage),
                    metadata=VALUES(metadata),
                    updated_at=CURRENT_TIMESTAMP
            ");

            foreach ($contracts as $contract) {
                // Normalize JSON fields
                $abi = $contract['abi'] ?? [];
                if (is_array($abi)) { $abi = json_encode($abi, JSON_UNESCAPED_SLASHES); }
                $storage = $contract['storage'] ?? [];
                if (is_array($storage)) { $storage = json_encode($storage, JSON_UNESCAPED_SLASHES); }
                $metadata = $contract['metadata'] ?? [];
                if (is_array($metadata)) { $metadata = json_encode($metadata, JSON_UNESCAPED_SLASHES); }

                $ok = $stmt->execute([
                    $contract['address'] ?? '',
                    $contract['creator'] ?? '',
                    $contract['name'] ?? '',
                    $contract['version'] ?? '1.0.0',
                    $contract['bytecode'] ?? '',
                    $abi ?: '[]',
                    $contract['source_code'] ?? '',
                    $contract['deployment_tx'] ?? '',
                    (int)($contract['deployment_block'] ?? 0),
                    (int)($contract['gas_used'] ?? 0),
                    $contract['status'] ?? 'active',
                    $storage ?: '{}',
                    $metadata ?: '{}'
                ]);

                if ($ok && $stmt->rowCount() > 0) {
                    $newContracts++;
                }
            }

            $contractsSynced += $newContracts;
            $this->log("Smart contracts sync: page $page, new $newContracts");

            $hasMore = (bool)($response['pagination']['has_more'] ?? (count($contracts) === $limit));
            $page++;
            if (!$hasMore) { break; }

        } while (true);

        $this->log("Smart contracts sync: total $contractsSynced");
        
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
        // In CLI, still echo for observability even when disk logging is disabled
        if (!$this->isWebMode) {
            echo $logEntry;
        }
        // Write to disk only if logging is explicitly enabled
        if ($this->loggingEnabled) {
            // Best-effort write; suppress errors if log directory is missing
            @file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        }
    }

    // Quorum check of latest hashes across peers, penalize outliers
    private function quorumCheckAndPenalize(string $sourceNodeUrl, int $depth = 20, int $peerSample = 3, int $penalty = 10): void {
        try {
            $this->log("Starting quorum check for source node: $sourceNodeUrl");
            
            // 1) Determine latest local height H
            $stmt = $this->pdo->query("SELECT MAX(height) AS h FROM blocks");
            $H = (int)$stmt->fetchColumn();
            if ($H <= 0) {
                $this->log("Quorum check skipped: no local blocks");
                return;
            }
            
            // Start from H backwards up to depth (but not below 0)
            $start = max(0, $H - $depth + 1);
            $this->log("Quorum check: validating blocks from height $start to $H");

            // 2) Get local hashes for the window [start..H]
            $stmt = $this->pdo->prepare("SELECT height, hash FROM blocks WHERE height BETWEEN ? AND ? ORDER BY height DESC");
            $stmt->execute([$start, $H]);
            $local = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $local[(int)$row['height']] = $row['hash'];
            }
            if (empty($local)) {
                $this->log("Quorum check skipped: no local hashes in range");
                return;
            }

            // 3) Build peer list (excluding source node in normal networks)
            $peers = $this->collectActivePeerUrls($excludeUrl = $sourceNodeUrl);
            if (empty($peers)) {
                $this->log("Quorum check: no peers available for cross-check - skipping validation");
                $this->log("Note: This may happen in single-node networks or network discovery issues");
                return;
            }
            
            // Check if source node is included (happens in micro networks)
            $sourceIncluded = false;
            foreach ($peers as $peer) {
                if (rtrim($peer, '/') === rtrim($sourceNodeUrl, '/')) {
                    $sourceIncluded = true;
                    break;
                }
            }
            
            if ($sourceIncluded) {
                $this->log("Note: Source node included in validation due to micro network size");
                $this->log("Warning: Self-validation provides limited security but maintains network operation");
            }
            
            // For very small networks, we can still do some validation even with limited peers
            if (count($peers) < 2) {
                $this->log("Warning: Only " . count($peers) . " peer(s) available for quorum check");
                $this->log("Small network detected - performing limited validation");
                // In small networks, even one peer agreement can be valuable
                $peerSample = min($peerSample, count($peers));
            }
            
            // Limit sample size and randomize for fairness
            shuffle($peers);
            $peers = array_slice($peers, 0, $peerSample);
            $this->log("Quorum check: querying " . count($peers) . " peer nodes");

            // 4) For each peer, fetch hash at heights and compute agreement score
            $agreement = 0;
            $asked = 0;
            $detailedResults = [];
            
            foreach ($peers as $peer) {
                $asked++;
                // Check multiple strategic heights to get better confidence
                $heightsToCheck = [$H, max(0, $H-1), max(0, $H-2), max(0, $H-5), max(0, $H-10)];
                $peerAgrees = false;
                $peerDetails = ['peer' => $peer, 'checks' => []];
                
                foreach (array_unique($heightsToCheck) as $h) {
                    if (!isset($local[$h])) continue; // Skip if we don't have this block locally
                    
                    $url = rtrim($peer, '/') . "/api/explorer/index.php?action=get_block&block_id=" . $h;
                    $resp = $this->makeApiCall($url, 15);
                    
                    if ($resp && isset($resp['success']) && $resp['success'] && isset($resp['data']['hash'])) {
                        $peerHash = $resp['data']['hash'];
                        $localHash = $local[$h];
                        $matches = hash_equals($localHash, $peerHash);
                        
                        $peerDetails['checks'][] = [
                            'height' => $h,
                            'local_hash' => substr($localHash, 0, 16) . '...',
                            'peer_hash' => substr($peerHash, 0, 16) . '...',
                            'matches' => $matches
                        ];
                        
                        if ($matches) {
                            $peerAgrees = true;
                            break; // One match is enough to trust this peer
                        }
                    } else {
                        $peerDetails['checks'][] = [
                            'height' => $h,
                            'error' => 'Failed to fetch or invalid response'
                        ];
                    }
                }
                
                $detailedResults[] = $peerDetails;
                if ($peerAgrees) {
                    $agreement++;
                    $this->log("Peer $peer: AGREES (found matching hash)");
                } else {
                    $this->log("Peer $peer: DISAGREES (no matching hashes found)");
                }
            }

            // 5) If disagreement exceeds threshold, penalize the source node's reputation_score
            if ($asked > 0) {
                $ratio = $agreement / $asked;
                $this->log(sprintf("Quorum check result: agreement %.0f%% (%d/%d peers agree)", $ratio*100, $agreement, $asked));
                
                // Adjust threshold based on network size
                $threshold = 0.51; // Default threshold for larger networks
                if ($asked == 1) {
                    // With only 1 peer, we need 100% agreement to penalize
                    $threshold = 1.0;
                    $this->log("Single peer validation - requiring 100% agreement");
                } elseif ($asked == 2) {
                    // With 2 peers, we need both to agree to penalize
                    $threshold = 1.0;
                    $this->log("Two peer validation - requiring 100% agreement");
                }
                
                if ($ratio < $threshold) {
                    $this->log("Quorum check: SUSPICIOUS SOURCE detected! Applying reputation penalty of $penalty points");
                    $penaltyApplied = $this->applyReputationPenalty($sourceNodeUrl, $penalty);
                    
                    if ($penaltyApplied) {
                        $this->log("Reputation penalty successfully applied to $sourceNodeUrl");
                    } else {
                        $this->log("Failed to apply reputation penalty to $sourceNodeUrl - node not found in database");
                    }
                } else {
                    $this->log("Quorum check: source node appears trustworthy");
                }
            }
        } catch (\Throwable $e) {
            $this->log("Quorum check failed: " . $e->getMessage());
        }
    }

    private function collectActivePeerUrls(?string $excludeUrl = null): array {
        $urls = [];
        $excludeUrls = [];
        
        // First, collect all available nodes to determine network size
        $allActiveNodes = [];
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
                    if (is_array($decoded)) $metaArr = $decoded;
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
                $allActiveNodes[] = $url;
            }
        } catch (\Throwable $e) {
            $this->log("Warning: Failed to collect all active nodes: " . $e->getMessage());
        }
        
        $totalNodes = count($allActiveNodes);
        $this->log("Total active nodes in network: $totalNodes");
        
        // Smart exclusion logic based on network size
        if ($totalNodes <= 2) {
            // In very small networks (2 or fewer nodes), don't exclude anyone
            // This allows some validation even in minimal setups
            $this->log("Micro network detected ($totalNodes nodes) - including all nodes for validation");
            $this->log("Note: Source node validation in micro networks provides limited security but better than none");
            $urls = $allActiveNodes;
        } else {
            // In larger networks, apply exclusion rules based on network size
            
            // Get current node configuration
            $currentNodeDomain = null;
            try {
                $currentNodeDomain = $this->getCurrentNodeDomain();
            } catch (\Throwable $e) {
                $this->log("Warning: Could not determine current node domain: " . $e->getMessage());
            }
            
            if ($totalNodes == 3) {
                // In 3-node networks, exclude only the source node to avoid self-sync
                // but keep current node for validation to have at least 2 peers
                $this->log("Small network detected ($totalNodes nodes) - excluding only source node for optimal validation");
                
                if ($excludeUrl) {
                    $excludeUrls[] = rtrim($excludeUrl, '/');
                    $this->log("Excluding source node from peer list: $excludeUrl");
                }
                
                if ($currentNodeDomain) {
                    $this->log("Keeping current node for validation: $currentNodeDomain");
                }
            } else {
                // In networks with 4+ nodes, exclude both source and current node
                $this->log("Medium/large network detected ($totalNodes nodes) - excluding source and current nodes");
                
                if ($excludeUrl) {
                    $excludeUrls[] = rtrim($excludeUrl, '/');
                    $this->log("Excluding source node from peer list: $excludeUrl");
                }
                
                if ($currentNodeDomain) {
                    $excludeUrls[] = rtrim($currentNodeDomain, '/');
                    $this->log("Excluding current node from peer list: $currentNodeDomain");
                }
            }
            
            // Filter nodes based on exclusion list
            foreach ($allActiveNodes as $url) {
                $shouldExclude = false;
                foreach ($excludeUrls as $excludeUrl) {
                    if (rtrim($url, '/') === $excludeUrl) {
                        $shouldExclude = true;
                        break;
                    }
                }
                
                if (!$shouldExclude) {
                    $urls[] = $url;
                } else {
                    $this->log("Excluding peer from list: $url");
                }
            }
        }
        
        $this->log("Collected " . count($urls) . " active peer URLs for quorum check");
        return array_values(array_unique($urls));
    }
    
    private function getCurrentNodeDomain(): ?string {
        try {
            // 1) Try to get from config table node.domain and node.protocol
            $stmt = $this->pdo->prepare("SELECT value FROM config WHERE key_name = 'node.domain' LIMIT 1");
            $stmt->execute();
            $domain = $stmt->fetchColumn();
            
            if ($domain) {
                // Get protocol from config
                $stmt = $this->pdo->prepare("SELECT value FROM config WHERE key_name = 'node.protocol' LIMIT 1");
                $stmt->execute();
                $protocol = $stmt->fetchColumn() ?: 'https';
                
                return "$protocol://$domain";
            }
            
            // 2) Try legacy config with id=1
            $stmt = $this->pdo->prepare("SELECT settings FROM config WHERE id = 1 LIMIT 1");
            $stmt->execute();
            $settingsJson = $stmt->fetchColumn();
            
            if ($settingsJson) {
                $settings = json_decode($settingsJson, true);
                if (is_array($settings)) {
                    // Check various possible field names
                    $domainField = $settings['node_domain'] ?? $settings['domain'] ?? $settings['node_url'] ?? null;
                    if ($domainField) {
                        // If it's already a full URL, return as is
                        if (strpos($domainField, 'http') === 0) {
                            return $domainField;
                        }
                        // Otherwise add protocol
                        $protocol = (!empty($settings['ssl_enabled'])) ? 'https' : 'http';
                        return "$protocol://" . $domainField;
                    }
                }
            }
            
            // 3) Try to get from environment or server variables
            if (isset($_SERVER['HTTP_HOST'])) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                return "$protocol://" . $_SERVER['HTTP_HOST'];
            }
            
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Check if two URLs represent the same node
     */
    private function isSameNode(string $url1, string $url2): bool {
        $parsed1 = parse_url($url1);
        $parsed2 = parse_url($url2);
        
        if (!$parsed1 || !$parsed2) {
            return false;
        }
        
        // Compare hosts (case insensitive)
        $host1 = strtolower($parsed1['host'] ?? '');
        $host2 = strtolower($parsed2['host'] ?? '');
        
        if ($host1 !== $host2) {
            return false;
        }
        
        // Compare ports (use default if not specified)
        $port1 = $parsed1['port'] ?? (($parsed1['scheme'] ?? 'http') === 'https' ? 443 : 80);
        $port2 = $parsed2['port'] ?? (($parsed2['scheme'] ?? 'http') === 'https' ? 443 : 80);
        
        return $port1 === $port2;
    }

    // Public method to sync blocks only from a specific source
    public function syncBlocksOnly($sourceNode = null) {
        $this->log("=== Starting Blocks-Only Synchronization ===");
        
        try {
            if (!$sourceNode) {
                $sourceNode = $this->selectBestNode();
            }
            
            $this->log("Syncing blocks from: $sourceNode");
            $blocksSynced = $this->syncBlocks($sourceNode);
            
            $result = [
                'status' => 'success',
                'source_node' => $sourceNode,
                'blocks_synced' => $blocksSynced,
                'completion_time' => date('Y-m-d H:i:s')
            ];
            
            $this->log("Blocks sync completed: " . json_encode($result));
            return $result;
            
        } catch (Exception $e) {
            $this->log("Blocks sync failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    // Public method to sync transactions only from a specific source
    public function syncTransactionsOnly($sourceNode = null) {
        $this->log("=== Starting Transactions-Only Synchronization ===");
        
        try {
            if (!$sourceNode) {
                $sourceNode = $this->selectBestNode();
            }
            
            $this->log("Syncing transactions from: $sourceNode");
            $transactionsSynced = $this->syncTransactions($sourceNode);
            
            $result = [
                'status' => 'success',
                'source_node' => $sourceNode,
                'transactions_synced' => $transactionsSynced,
                'completion_time' => date('Y-m-d H:i:s')
            ];
            
            $this->log("Transactions sync completed: " . json_encode($result));
            return $result;
            
        } catch (Exception $e) {
            $this->log("Transactions sync failed: " . $e->getMessage());
            throw $e;
        }
    }

    // Periodic mempool maintenance - can be called independently
    public function runMempoolMaintenance(): array {
        $this->log("=== Starting Mempool Maintenance ===");
        
        try {
            // Get initial stats
            $stmt = $this->pdo->query("SELECT COUNT(*) as total, status, COUNT(*) as count FROM mempool GROUP BY status");
            $beforeStats = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $beforeStats[$row['status']] = $row['count'];
            }
            
            // Run cleanup
            $this->cleanupMempool();
            
            // Get final stats
            $stmt = $this->pdo->query("SELECT COUNT(*) as total, status, COUNT(*) as count FROM mempool GROUP BY status");
            $afterStats = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $afterStats[$row['status']] = $row['count'];
            }
            
            $result = [
                'status' => 'success',
                'before' => $beforeStats,
                'after' => $afterStats,
                'maintenance_time' => date('Y-m-d H:i:s')
            ];
            
            $this->log("Mempool maintenance completed: " . json_encode($result));
            return $result;
            
        } catch (\Throwable $e) {
            $this->log("Mempool maintenance failed: " . $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'maintenance_time' => date('Y-m-d H:i:s')
            ];
        }
    }

    private function applyReputationPenalty(string $sourceNodeUrl, int $penalty): bool {
        // Find node by domain/ip+port in nodes table and reduce reputation_score (min 0)
        try {
            $parts = parse_url($sourceNodeUrl);
            if (!$parts || empty($parts['host'])) {
                $this->log("Invalid URL format for reputation penalty: $sourceNodeUrl");
                return false;
            }
            
            $host = $parts['host'];
            $port = $parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80);

            // Try match by domain in metadata first, then by IP
            $stmt = $this->pdo->prepare("
                SELECT id, reputation_score, metadata, ip_address 
                FROM nodes 
                WHERE (ip_address = ? OR JSON_EXTRACT(metadata,'$.domain') = ?) 
                  AND port = ? 
                LIMIT 1
            ");
            $stmt->execute([$host, $host, $port]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                $this->log("Node not found in database for penalty: $sourceNodeUrl (host=$host, port=$port)");
                return false;
            }

            $oldScore = (int)$row['reputation_score'];
            $newScore = max(0, $oldScore - $penalty);
            
            $upd = $this->pdo->prepare("UPDATE nodes SET reputation_score = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$newScore, (int)$row['id']]);
            
            $this->log("Reputation penalty applied to {$row['ip_address']}:$port - score: $oldScore → $newScore (-$penalty)");
            return true;
            
        } catch (\Throwable $e) {
            $this->log("Failed to apply reputation penalty: " . $e->getMessage());
            return false;
        }
    }

    // Remove stale mempool entries and those already confirmed
    private function cleanupMempool(int $ttlHours = 24): void {
        try {
            $this->log("Starting mempool cleanup...");
            
            // 1) Remove entries that have expired (expires_at < now) or older than TTL and still pending/failed
            $stmt = $this->pdo->prepare("
                DELETE FROM mempool
                WHERE (expires_at IS NOT NULL AND expires_at < NOW())
                   OR (created_at < (NOW() - INTERVAL ? HOUR) AND status IN ('pending','failed'))
            ");
            $stmt->execute([$ttlHours]);
            $removed1 = $stmt->rowCount();

            // 2) Remove entries that are already confirmed in transactions
            $stmt = $this->pdo->prepare("
                DELETE m FROM mempool m
                INNER JOIN transactions t ON t.hash = m.tx_hash
                WHERE t.status = 'confirmed'
            ");
            $stmt->execute();
            $removed2 = $stmt->rowCount();

            // 3) Remove entries with duplicate nonce from same address (keep only latest)
            $stmt = $this->pdo->prepare("
                DELETE m1 FROM mempool m1
                INNER JOIN mempool m2
                WHERE m1.from_address = m2.from_address
                  AND m1.nonce = m2.nonce
                  AND m1.created_at < m2.created_at
            ");
            $stmt->execute();
            $removed3 = $stmt->rowCount();

            // 4) Mark hanging processing transactions as failed
            $stmt = $this->pdo->prepare("
                UPDATE mempool
                SET status='failed'
                WHERE status='processing' 
                  AND (last_retry_at IS NOT NULL AND last_retry_at < (NOW() - INTERVAL 1 HOUR))
            ");
            $stmt->execute();
            $marked = $stmt->rowCount();

            // 5) Clean up very old failed transactions (older than 7 days)
            $stmt = $this->pdo->prepare("
                DELETE FROM mempool
                WHERE status = 'failed' AND created_at < (NOW() - INTERVAL 7 DAY)
            ");
            $stmt->execute();
            $removed4 = $stmt->rowCount();

            $this->log("Mempool cleanup completed: expired/ttl=$removed1, confirmed=$removed2, duplicates=$removed3, old_failed=$removed4, marked_failed=$marked");
        } catch (\Throwable $e) {
            $this->log("Mempool cleanup failed: " . $e->getMessage());
        }
    }
    
    // Sync transactions from source node with exact data replication
    public function syncTransactionsFromSource($sourceNode = null) {
        $this->log("=== Starting Exact Transaction Synchronization ===");
        
        try {
            if (!$sourceNode) {
                $sourceNode = $this->selectBestNode();
            }
            
            // First, clear existing transactions to ensure clean sync
            $stmt = $this->pdo->prepare("DELETE FROM transactions");
            $stmt->execute();
            $cleared = $stmt->rowCount();
            $this->log("Cleared $cleared existing transactions for clean sync");
            
            // Get source transactions via direct database sync call
            $syncUrl = rtrim($sourceNode, '/') . '/sync_web.php?action=export_transactions';
            $response = $this->makeApiCall($syncUrl);
            
            if ($response && isset($response['status']) && $response['status'] === 'success') {
                $transactions = $response['transactions'] ?? [];
                $totalSynced = 0;
                
                foreach ($transactions as $tx) {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO transactions (hash, from_address, to_address, amount, fee, timestamp, block_hash, status, nonce, gas_limit)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $result = $stmt->execute([
                        $tx['hash'],
                        $tx['from_address'],
                        $tx['to_address'],
                        $tx['amount'],
                        $tx['fee'],
                        $tx['timestamp'],
                        $tx['block_hash'],
                        $tx['status'],
                        $tx['nonce'],
                        $tx['gas_limit']
                    ]);
                    
                    if ($result) {
                        $totalSynced++;
                    }
                }
                
                $this->log("Synced $totalSynced exact transactions from source");
                
                // Update block transaction counts
                $this->recalculateStats();
                
                $result = [
                    'status' => 'success',
                    'source_node' => $sourceNode,
                    'transactions_synced' => $totalSynced,
                    'completion_time' => date('Y-m-d H:i:s')
                ];
                
                return $result;
            } else {
                throw new Exception("Failed to export transactions from source node");
            }
            
        } catch (Exception $e) {
            $this->log("Exact transaction sync failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    // Export all transactions for replication
    public function exportTransactions() {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM transactions ORDER BY timestamp, hash");
            $stmt->execute();
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'status' => 'success',
                'transactions' => $transactions,
                'count' => count($transactions),
                'export_time' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    // Generate test transactions based on block transaction counts
    public function generateTestTransactions() {
        $this->log("=== Generating Test Transactions ===");
        
        try {
            $stmt = $this->pdo->prepare("SELECT height, hash, transactions_count, timestamp FROM blocks ORDER BY height");
            $stmt->execute();
            $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $totalGenerated = 0;
            
            foreach ($blocks as $block) {
                $txCount = (int)$block['transactions_count'];
                $blockHash = $block['hash'];
                $height = $block['height'];
                $timestamp = $block['timestamp'];
                
                for ($i = 0; $i < $txCount; $i++) {
                    $txHash = hash('sha256', $blockHash . $i . time());
                    $fromAddr = '0x' . substr(hash('sha256', 'from' . $height . $i), 0, 40);
                    $toAddr = '0x' . substr(hash('sha256', 'to' . $height . $i), 0, 40);
                    $amount = rand(1, 1000);
                    $fee = rand(1, 10);
                    
                    $stmt = $this->pdo->prepare("
                        INSERT IGNORE INTO transactions (hash, from_address, to_address, amount, fee, timestamp, block_hash, status, nonce, gas_limit)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $result = $stmt->execute([
                        $txHash,
                        $fromAddr,
                        $toAddr,
                        $amount,
                        $fee,
                        $timestamp,
                        $blockHash,
                        'confirmed',
                        $i,
                        21000
                    ]);
                    
                    if ($result && $stmt->rowCount() > 0) {
                        $totalGenerated++;
                    }
                }
                
                if ($txCount > 0) {
                    $this->log("Generated $txCount test transactions for block $height");
                }
            }
            
            $result = [
                'status' => 'success',
                'transactions_generated' => $totalGenerated,
                'generation_time' => date('Y-m-d H:i:s')
            ];
            
            $this->log("Test transactions generation completed: " . json_encode($result));
            return $result;
            
        } catch (Exception $e) {
            $this->log("Test transactions generation failed: " . $e->getMessage());
            throw $e;
        }
    }

    // Recalculate transaction counts and fix database inconsistencies
    public function recalculateStats() {
        $this->log("=== Starting Statistics Recalculation ===");
        
        try {
            // 1. Update transaction counts in blocks table
            $stmt = $this->pdo->prepare("
                UPDATE blocks b 
                SET transactions_count = (
                    SELECT COUNT(*) 
                    FROM transactions t 
                    WHERE t.block_hash = b.hash
                )
            ");
            $stmt->execute();
            $blocksUpdated = $stmt->rowCount();
            $this->log("Updated transaction counts for $blocksUpdated blocks");
            
            // 2. Clean up orphaned transactions (no matching block)
            $stmt = $this->pdo->prepare("
                DELETE FROM transactions 
                WHERE block_hash NOT IN (SELECT hash FROM blocks)
                AND block_hash != ''
            ");
            $stmt->execute();
            $orphanedTxs = $stmt->rowCount();
            $this->log("Removed $orphanedTxs orphaned transactions");
            
            // 3. Update wallet balances and nonces from transaction history
            $stmt = $this->pdo->prepare("
                UPDATE wallets w SET 
                nonce = (
                    SELECT COALESCE(MAX(t.nonce), 0) + 1
                    FROM transactions t 
                    WHERE t.from_address = w.address 
                    AND t.status = 'confirmed'
                )
                WHERE w.address IN (SELECT DISTINCT from_address FROM transactions WHERE status = 'confirmed')
            ");
            $stmt->execute();
            $walletsUpdated = $stmt->rowCount();
            $this->log("Updated nonces for $walletsUpdated wallets");
            
            $result = [
                'status' => 'success',
                'blocks_updated' => $blocksUpdated,
                'orphaned_transactions_removed' => $orphanedTxs,
                'wallets_updated' => $walletsUpdated,
                'recalc_time' => date('Y-m-d H:i:s')
            ];
            
            $this->log("Statistics recalculation completed: " . json_encode($result));
            return $result;
            
        } catch (Exception $e) {
            $this->log("Statistics recalculation failed: " . $e->getMessage());
            throw $e;
        }
    }

    public function getStatus() {
        // Get database statistics
        $tables = ['blocks', 'transactions', 'nodes', 'validators', 'smart_contracts', 'staking', 'wallets'];
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

    /**
     * PoS Block Mining with Network Coordination
     * Ensures only one node mines at a time and coordinates with quick_sync
     */
    public function startCoordinatedMining($intervalSeconds = 45, $maxTransactionsPerBlock = 100) {
        $this->log("=== Starting Coordinated PoS Mining Process ===");
        $this->log("Mining interval: {$intervalSeconds} seconds");
        $this->log("Max transactions per block: {$maxTransactionsPerBlock}");
        $this->log("Quick sync runs every 60 seconds - mining coordinated to avoid conflicts");
        $this->log("Press Ctrl+C to stop mining");
        
        $lastBlockTime = 0;
        $miningCount = 0;
        
        while (true) {
            try {
                $currentTime = time();
                
                // Avoid mining during quick_sync windows (55-65 seconds of each minute)
                $secondInMinute = $currentTime % 60;
                if ($secondInMinute >= 55 || $secondInMinute <= 5) {
                    $this->log("⏸️  Pausing mining for quick_sync window (second {$secondInMinute}/60)");
                    sleep(5);
                    continue;
                }
                
                // Check if this node should mine (leader election)
                if (!$this->shouldThisNodeMine()) {
                    $this->log("🔄 Another node is designated as miner, staying in sync mode");
                    sleep(10);
                    continue;
                }
                
                // Check if enough time has passed since last block
                if ($currentTime - $lastBlockTime >= $intervalSeconds) {
                    $result = $this->coordinatedMineBlock($maxTransactionsPerBlock);
                    if ($result['success']) {
                        $lastBlockTime = $currentTime;
                        $miningCount++;
                        $this->log("✅ Block #{$result['block_height']} mined successfully");
                        $this->log("   Hash: {$result['block_hash']}");
                        $this->log("   Transactions: {$result['transactions_count']}");
                        $this->log("   Validator: {$result['validator']}");
                        $this->log("   Total blocks mined this session: {$miningCount}");
                        
                        // Wait a bit for network propagation before next mining attempt
                        sleep(5);
                    } else {
                        $this->log("ℹ️  {$result['message']}");
                    }
                } else {
                    $remaining = $intervalSeconds - ($currentTime - $lastBlockTime);
                    $this->log("⏳ Waiting {$remaining}s until next mining opportunity...");
                }
                
                sleep(5); // Check every 5 seconds for more responsive coordination
                
            } catch (Exception $e) {
                $this->log("Mining error: " . $e->getMessage());
                sleep(30); // Wait longer on errors
            }
        }
    }
    
    /**
     * Determine if this node should mine (simple leader election)
     */
    private function shouldThisNodeMine() {
        try {
            // Get current domain
            $currentDomain = $this->getCurrentNodeDomain();
            if (!$currentDomain) {
                return true; // If can't determine, assume yes
            }
            
            // Get all active nodes
            $nodes = $this->getNetworkNodesForBroadcast();
            $nodes[] = $currentDomain; // Include self
            $nodes = array_unique($nodes);
            sort($nodes); // Deterministic order
            
            if (empty($nodes) || count($nodes) == 1) {
                return true; // Single node network
            }
            
            // Simple time-based rotation: change leader every 5 minutes
            $timeSlot = floor(time() / 300); // 5-minute slots
            $leaderIndex = $timeSlot % count($nodes);
            $designatedLeader = $nodes[$leaderIndex];
            
            $isLeader = $this->isSameNode($currentDomain, $designatedLeader);
            
            if ($isLeader) {
                $this->log("🎯 This node is designated as mining leader for current time slot");
            } else {
                $this->log("👥 Mining leader is: {$designatedLeader}");
            }
            
            return $isLeader;
            
        } catch (Exception $e) {
            $this->log("Leader election failed: " . $e->getMessage());
            return false; // Be conservative on errors
        }
    }
    
    /**
     * Mine block with additional coordination checks
     */
    private function coordinatedMineBlock($maxTransactionsPerBlock) {
        try {
            // Double-check network state before mining
            $this->ensureNetworkSyncBeforeMining();
            
            // Proceed with normal mining
            $result = $this->mineNewBlock($maxTransactionsPerBlock);
            
            if ($result['success']) {
                // Enhanced broadcasting with verification
                $this->enhancedBlockBroadcast($result);
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Coordinated mining failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Ensure network sync before mining to avoid forks
     */
    private function ensureNetworkSyncBeforeMining() {
        $this->log("🔍 Checking network sync status before mining...");
        
        try {
            // Get our current height
            $stmt = $this->pdo->prepare("SELECT MAX(height) as height FROM blocks");
            $stmt->execute();
            $localHeight = $stmt->fetchColumn() ?? 0;
            
            // Check heights on other nodes
            $networkNodes = $this->getNetworkNodesForBroadcast();
            $networkHeights = [];
            
            foreach ($networkNodes as $nodeUrl) {
                $url = rtrim($nodeUrl, '/') . '/api/explorer/index.php?action=get_network_stats';
                $response = $this->makeApiCall($url, 5); // Short timeout
                
                if ($response && isset($response['current_height'])) {
                    $networkHeights[] = (int)$response['current_height'];
                }
            }
            
            if (!empty($networkHeights)) {
                $maxNetworkHeight = max($networkHeights);
                
                if ($maxNetworkHeight > $localHeight) {
                    $this->log("⚠️  Network ahead by " . ($maxNetworkHeight - $localHeight) . " blocks, syncing first...");
                    
                    // Quick sync to catch up
                    $this->syncBlocks($this->selectBestNode());
                    
                    $this->log("✅ Network sync completed before mining");
                } else {
                    $this->log("✅ Network sync verified, ready to mine");
                }
            }
            
        } catch (Exception $e) {
            $this->log("Network sync check failed: " . $e->getMessage());
            // Continue anyway - don't block mining on sync check failures
        }
    }
    
    /**
     * Enhanced block broadcast with verification
     */
    private function enhancedBlockBroadcast($blockResult) {
        $this->log("📡 Starting enhanced block broadcast...");
        
        // Standard broadcast
        $this->broadcastNewBlock($blockResult);
        
        // Wait for propagation
        sleep(3);
        
        // Verify broadcast success by checking if nodes received the block
        $networkNodes = $this->getNetworkNodesForBroadcast();
        $successCount = 0;
        
        foreach ($networkNodes as $nodeUrl) {
            try {
                $url = rtrim($nodeUrl, '/') . '/api/explorer/index.php?action=get_network_stats';
                $response = $this->makeApiCall($url, 5);
                
                if ($response && isset($response['current_height'])) {
                    $nodeHeight = (int)$response['current_height'];
                    if ($nodeHeight >= $blockResult['block_height']) {
                        $successCount++;
                        $this->log("✅ Node {$nodeUrl} confirmed block reception (height: {$nodeHeight})");
                    } else {
                        $this->log("⚠️  Node {$nodeUrl} not yet synced (height: {$nodeHeight})");
                    }
                }
                
            } catch (Exception $e) {
                $this->log("❌ Failed to verify broadcast on {$nodeUrl}: " . $e->getMessage());
            }
        }
        
        $totalNodes = count($networkNodes);
        $successRate = $totalNodes > 0 ? ($successCount / $totalNodes) * 100 : 100;
        
        $this->log("📊 Broadcast verification: {$successCount}/{$totalNodes} nodes confirmed ({$successRate}%)");
        
        if ($successRate < 50 && $totalNodes > 0) {
            $this->log("⚠️  Low broadcast success rate, network may need manual sync");
        }
    }
    
    /**
     * Simple PoS Block Mining - Original implementation without coordination
     * Use this for single-node networks or testing
     */
    public function startBlockMining($intervalSeconds = 30, $maxTransactionsPerBlock = 100) {
        $this->log("=== Starting Simple PoS Block Mining Process ===");
        $this->log("Block interval: {$intervalSeconds} seconds");
        $this->log("Max transactions per block: {$maxTransactionsPerBlock}");
        $this->log("Press Ctrl+C to stop mining");
        
        $lastBlockTime = 0;
        $miningCount = 0;
        
        while (true) {
            try {
                $currentTime = time();
                
                // Check if enough time has passed since last block
                if ($currentTime - $lastBlockTime >= $intervalSeconds) {
                    $result = $this->mineNewBlock($maxTransactionsPerBlock);
                    if ($result['success']) {
                        $lastBlockTime = $currentTime;
                        $miningCount++;
                        $this->log("✅ Block #{$result['block_height']} mined successfully");
                        $this->log("   Hash: {$result['block_hash']}");
                        $this->log("   Transactions: {$result['transactions_count']}");
                        $this->log("   Validator: {$result['validator']}");
                        $this->log("   Total blocks mined this session: {$miningCount}");
                    } else {
                        $this->log("ℹ️  {$result['message']}");
                    }
                } else {
                    $remaining = $intervalSeconds - ($currentTime - $lastBlockTime);
                    $this->log("Waiting {$remaining}s until next block opportunity...");
                }
                
                sleep(10); // Check every 10 seconds
                
            } catch (Exception $e) {
                $this->log("Mining error: " . $e->getMessage());
                sleep(30); // Wait longer on errors
            }
        }
    }
    
    /**
     * Mine a single new block from mempool transactions
     */
    public function mineNewBlock($maxTransactionsPerBlock = 100) {
        try {
            // Get pending transactions from mempool
            $stmt = $this->pdo->prepare("
                SELECT * FROM mempool 
                WHERE status = 'pending' 
                AND (expires_at IS NULL OR expires_at > NOW())
                ORDER BY priority_score DESC, fee DESC, created_at ASC 
                LIMIT " . (int)$maxTransactionsPerBlock
            );
            $stmt->execute();
            $pendingTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($pendingTransactions)) {
                return [
                    'success' => false,
                    'message' => 'No pending transactions in mempool'
                ];
            }
            
            // Get current blockchain state
            $stmt = $this->pdo->prepare("SELECT * FROM blocks ORDER BY height DESC LIMIT 1");
            $stmt->execute();
            $latestBlock = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $nextHeight = $latestBlock ? ($latestBlock['height'] + 1) : 1;
            $previousHash = $latestBlock ? $latestBlock['hash'] : '0000000000000000000000000000000000000000000000000000000000000000';
            
            // Select validator using PoS algorithm
            $validator = $this->selectValidatorForBlock($nextHeight, $previousHash);
            if (!$validator) {
                return [
                    'success' => false,
                    'message' => 'No validator available for block creation'
                ];
            }
            
            // Create new block
            $blockData = $this->createNewBlock($nextHeight, $previousHash, $pendingTransactions, $validator);
            
            if ($blockData) {
                // Remove processed transactions from mempool
                $txHashes = array_column($pendingTransactions, 'tx_hash');
                $this->removeFromMempool($txHashes);
                
                // Broadcast new block to network nodes
                $this->broadcastNewBlock($blockData);
                
                return [
                    'success' => true,
                    'block_height' => $nextHeight,
                    'block_hash' => $blockData['hash'],
                    'transactions_count' => count($pendingTransactions),
                    'validator' => $validator['address'],
                    'broadcast_status' => 'sent'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to create block'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Mining failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Select validator for block creation using PoS algorithm
     */
    private function selectValidatorForBlock($blockHeight, $previousHash) {
        // Get active validators with stake
        $stmt = $this->pdo->prepare("
            SELECT v.*, w.balance as stake 
            FROM validators v 
            LEFT JOIN wallets w ON v.address = w.address 
            WHERE v.status = 'active' 
            AND w.balance >= 1000
            ORDER BY w.balance DESC
        ");
        $stmt->execute();
        $validators = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($validators)) {
            // Create system validator if none exist
            return $this->createSystemValidator();
        }
        
        // Simple PoS selection: weighted by stake
        $totalStake = array_sum(array_column($validators, 'stake'));
        $seed = hexdec(substr(hash('sha256', $previousHash . $blockHeight), 0, 8));
        $randomValue = ($seed % 1000000) / 1000000; // 0 to 1
        $targetValue = $randomValue * $totalStake;
        
        $cumulativeStake = 0;
        foreach ($validators as $validator) {
            $cumulativeStake += $validator['stake'];
            if ($targetValue <= $cumulativeStake) {
                return $validator;
            }
        }
        
        // Fallback: return first validator
        return $validators[0];
    }
    
    /**
     * Create system validator if none exist
     */
    private function createSystemValidator() {
        try {
            // Load ValidatorManager
            require_once __DIR__ . '/core/Consensus/ValidatorManager.php';
            $validatorManager = new \Blockchain\Core\Consensus\ValidatorManager($this->pdo);
            
            return $validatorManager->createSystemValidator();
        } catch (Exception $e) {
            $this->log("Failed to create system validator: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create a new block with transactions
     */
    private function createNewBlock($height, $previousHash, $transactions, $validator) {
        try {
            $this->pdo->beginTransaction();
            
            // Create block data
            $timestamp = time();
            $merkleRoot = $this->calculateMerkleRoot($transactions);
            $blockHash = hash('sha256', $height . $timestamp . $previousHash . $merkleRoot . $validator['address']);
            
            // Sign block with validator
            $signature = hash('sha256', $blockHash . $validator['address']);
            
            // Insert block
            $stmt = $this->pdo->prepare("
                INSERT INTO blocks (hash, parent_hash, height, timestamp, validator, signature, merkle_root, transactions_count, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $metadata = json_encode([
                'miner' => 'NetworkSyncManager',
                'pos_algorithm' => 'stake_weighted',
                'created_at' => date('c'),
                'validator_stake' => $validator['stake']
            ]);
            
            $stmt->execute([
                $blockHash,
                $previousHash,
                $height,
                $timestamp,
                $validator['address'],
                $signature,
                $merkleRoot,
                count($transactions),
                $metadata
            ]);
            
            // Insert transactions
            foreach ($transactions as $tx) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO transactions (hash, block_hash, block_height, from_address, to_address, amount, fee, gas_limit, gas_used, gas_price, nonce, data, signature, status, timestamp)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?)
                    ON DUPLICATE KEY UPDATE 
                    block_hash = VALUES(block_hash),
                    block_height = VALUES(block_height),
                    status = 'confirmed'
                ");
                
                $stmt->execute([
                    $tx['tx_hash'],
                    $blockHash,
                    $height,
                    $tx['from_address'],
                    $tx['to_address'],
                    $tx['amount'],
                    $tx['fee'],
                    $tx['gas_limit'] ?? 21000,
                    $tx['gas_limit'] ?? 21000,
                    $tx['gas_price'] ?? 0,
                    $tx['nonce'],
                    $tx['data'] ?? '',
                    $tx['signature'],
                    $timestamp
                ]);
                
                // Process transaction effects (wallet balances)
                $this->processTransactionEffects($tx, $height);
            }
            
            $this->pdo->commit();
            
            return [
                'hash' => $blockHash,
                'height' => $height,
                'transactions_count' => count($transactions),
                'validator' => $validator['address']
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->log("Block creation failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Calculate Merkle root for transactions
     */
    private function calculateMerkleRoot($transactions) {
        if (empty($transactions)) {
            return hash('sha256', '');
        }
        
        $hashes = array_map(function($tx) {
            return $tx['tx_hash'];
        }, $transactions);
        
        while (count($hashes) > 1) {
            $newHashes = [];
            for ($i = 0; $i < count($hashes); $i += 2) {
                $left = $hashes[$i];
                $right = $hashes[$i + 1] ?? $hashes[$i];
                $newHashes[] = hash('sha256', $left . $right);
            }
            $hashes = $newHashes;
        }
        
        return $hashes[0];
    }
    
    /**
     * Process transaction effects on wallet balances
     */
    private function processTransactionEffects($transaction, $blockHeight) {
        // Update wallet balances for transfers
        if ($transaction['from_address'] !== 'genesis' && $transaction['from_address'] !== 'genesis_address') {
            // Deduct from sender
            $stmt = $this->pdo->prepare("
                UPDATE wallets 
                SET balance = balance - ?, 
                    nonce = nonce + 1,
                    updated_at = NOW()
                WHERE address = ?
            ");
            $stmt->execute([$transaction['amount'] + $transaction['fee'], $transaction['from_address']]);
        }
        
        // Add to receiver
        $stmt = $this->pdo->prepare("
            INSERT INTO wallets (address, balance, created_at, updated_at)
            VALUES (?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
            balance = balance + ?,
            updated_at = NOW()
        ");
        $stmt->execute([
            $transaction['to_address'],
            $transaction['amount'],
            $transaction['amount']
        ]);
    }
    
    /**
     * Remove transactions from mempool
     */
    private function removeFromMempool($txHashes) {
        if (empty($txHashes)) return;
        
        $placeholders = str_repeat('?,', count($txHashes) - 1) . '?';
        $stmt = $this->pdo->prepare("DELETE FROM mempool WHERE tx_hash IN ($placeholders)");
        $stmt->execute($txHashes);
        
        $this->log("Removed " . count($txHashes) . " transactions from mempool");
    }
    
    /**
     * Broadcast newly mined block to all network nodes
     */
    private function broadcastNewBlock($blockData) {
        try {
            $this->log("Broadcasting new block {$blockData['hash']} to network nodes...");
            
            // Get all active network nodes (excluding current node)
            $networkNodes = $this->getNetworkNodesForBroadcast();
            
            if (empty($networkNodes)) {
                $this->log("No network nodes available for broadcast");
                return false;
            }
            
            $successCount = 0;
            $totalNodes = count($networkNodes);
            
            foreach ($networkNodes as $nodeUrl) {
                if ($this->broadcastToNode($nodeUrl, $blockData)) {
                    $successCount++;
                    $this->log("✅ Block broadcast successful: $nodeUrl");
                } else {
                    $this->log("❌ Block broadcast failed: $nodeUrl");
                }
            }
            
            $this->log("Block broadcast completed: {$successCount}/{$totalNodes} nodes notified");
            
            // If we successfully broadcast to majority of nodes, trigger sync on them
            if ($successCount > 0) {
                $this->triggerNetworkSync($networkNodes);
            }
            
            return $successCount > 0;
            
        } catch (Exception $e) {
            $this->log("Block broadcast failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get network nodes for broadcasting (excluding current node)
     */
    private function getNetworkNodesForBroadcast() {
        $nodes = [];
        
        try {
            // Get nodes from database
            $stmt = $this->pdo->prepare("
                SELECT ip_address, port, metadata 
                FROM nodes 
                WHERE status = 'active' 
                AND reputation_score >= 50
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($rows as $row) {
                $ip = trim($row['ip_address'] ?? '');
                $port = (int)($row['port'] ?? 80);
                $meta = $row['metadata'] ?? null;
                
                $metaArr = [];
                if (is_string($meta) && $meta !== '') {
                    $decoded = json_decode($meta, true);
                    if (is_array($decoded)) $metaArr = $decoded;
                } elseif (is_array($meta)) {
                    $metaArr = $meta;
                }
                
                $protocol = $metaArr['protocol'] ?? 'https';
                $domain = $metaArr['domain'] ?? '';
                $host = $domain !== '' ? $domain : $ip;
                if ($host === '') continue;
                
                $defaultPort = ($protocol === 'https') ? 443 : 80;
                $portPart = ($port > 0 && $port !== $defaultPort) ? (':' . $port) : '';
                $url = sprintf('%s://%s%s', $protocol, rtrim($host, '/'), $portPart);
                
                // Exclude current node
                $currentDomain = $this->getCurrentNodeDomain();
                if ($currentDomain && $this->isSameNode($url, $currentDomain)) {
                    continue;
                }
                
                $nodes[] = $url;
            }
            
            // Fallback to config nodes if database is empty
            if (empty($nodes)) {
                $configNodes = $this->config['network_nodes'] ?? '';
                $candidates = preg_split('/[\r\n,]+/', (string)$configNodes);
                $nodes = array_values(array_filter(array_map('trim', $candidates)));
            }
            
        } catch (Exception $e) {
            $this->log("Failed to get network nodes: " . $e->getMessage());
        }
        
        return $nodes;
    }
    
    /**
     * Broadcast block to a specific node
     */
    private function broadcastToNode($nodeUrl, $blockData) {
        try {
            // Try to notify node about new block via sync endpoint
            $syncUrl = rtrim($nodeUrl, '/') . '/network_sync.php?action=sync_new_block';
            
            $postData = json_encode([
                'block_hash' => $blockData['hash'],
                'block_height' => $blockData['height'],
                'source_node' => $this->getCurrentNodeDomain(),
                'timestamp' => time()
            ]);
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n" .
                               "Content-Length: " . strlen($postData) . "\r\n",
                    'content' => $postData,
                    'timeout' => 10
                ]
            ]);
            
            $result = @file_get_contents($syncUrl, false, $context);
            
            if ($result !== false) {
                $response = json_decode($result, true);
                return isset($response['status']) && $response['status'] === 'success';
            }
            
            return false;
            
        } catch (Exception $e) {
            $this->log("Broadcast to $nodeUrl failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Trigger sync on network nodes after broadcasting
     */
    private function triggerNetworkSync($nodes) {
        foreach ($nodes as $nodeUrl) {
            try {
                // Trigger async sync on remote node
                $syncTriggerUrl = rtrim($nodeUrl, '/') . '/network_sync.php?action=trigger_sync';
                
                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'timeout' => 5, // Short timeout for trigger
                        'header' => "User-Agent: BlockMiner-SyncTrigger/1.0\r\n"
                    ]
                ]);
                
                // Fire and forget - don't wait for response
                @file_get_contents($syncTriggerUrl, false, $context);
                
            } catch (Exception $e) {
                // Ignore errors for sync triggers
            }
        }
        
        $this->log("Sync triggers sent to " . count($nodes) . " nodes");
    }
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
                
            case 'mempool':
                echo "Running mempool maintenance...\n\n";
                $result = $syncManager->runMempoolMaintenance();
                
                echo "=== Mempool Maintenance Result ===\n";
                echo "Status: " . $result['status'] . "\n";
                if ($result['status'] === 'success') {
                    echo "\nBefore cleanup:\n";
                    foreach ($result['before'] as $status => $count) {
                        echo sprintf("  %-12s: %d\n", $status, $count);
                    }
                    echo "\nAfter cleanup:\n";
                    foreach ($result['after'] as $status => $count) {
                        echo sprintf("  %-12s: %d\n", $status, $count);
                    }
                } else {
                    echo "Error: " . ($result['message'] ?? 'Unknown error') . "\n";
                }
                break;
                
            case 'sync-mempool':
                echo "Running enhanced mempool synchronization...\n\n";
                $result = $syncManager->enhancedMempoolSync();
                
                echo "=== Enhanced Mempool Sync Result ===\n";
                echo "Status: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . "\n";
                echo "Transactions synced: " . $result['transactions_synced'] . "\n";
                echo "Pending transactions: " . $result['pending_transactions'] . "\n";
                echo "Mining attempted: " . ($result['mining_attempted'] ? 'YES' : 'NO') . "\n";
                
                if ($result['mining_attempted'] && isset($result['mining_result'])) {
                    $miningResult = $result['mining_result'];
                    echo "Mining result: " . ($miningResult['success'] ? 'SUCCESS' : 'FAILED') . "\n";
                    if ($miningResult['success']) {
                        echo "New block height: " . $miningResult['block']['height'] . "\n";
                        echo "Block hash: " . $miningResult['block']['hash'] . "\n";
                        echo "Transactions processed: " . count($miningResult['block']['transactions']) . "\n";
                    } else {
                        echo "Mining error: " . ($miningResult['message'] ?? 'Unknown error') . "\n";
                    }
                }
                
                if (!$result['success']) {
                    echo "Error: " . ($result['error'] ?? 'Unknown error') . "\n";
                }
                echo "Completed at: " . $result['maintenance_time'] . "\n";
                break;
                
            case 'enhanced-mempool':
                // Enhanced mempool synchronization and processing
                echo "Starting enhanced mempool synchronization...\n";
                $result = $syncManager->enhancedMempoolSync();
                
                echo "Results:\n";
                echo "Success: " . ($result['success'] ? 'YES' : 'NO') . "\n";
                echo "Transactions synced: " . $result['transactions_synced'] . "\n";
                echo "Pending transactions: " . $result['pending_transactions'] . "\n";
                echo "Mining attempted: " . ($result['mining_attempted'] ? 'YES' : 'NO') . "\n";
                
                if ($result['mining_attempted'] && isset($result['mining_result'])) {
                    $miningResult = $result['mining_result'];
                    echo "Mining result: " . ($miningResult['success'] ? 'SUCCESS' : 'FAILED') . "\n";
                    if ($miningResult['success']) {
                        echo "New block height: " . $miningResult['block']['height'] . "\n";
                        echo "Block hash: " . $miningResult['block']['hash'] . "\n";
                        echo "Transactions processed: " . count($miningResult['block']['transactions']) . "\n";
                    } else {
                        echo "Mining error: " . ($miningResult['message'] ?? 'Unknown error') . "\n";
                    }
                }
                
                if (!$result['success']) {
                    echo "Error: " . ($result['error'] ?? 'Unknown error') . "\n";
                }
                break;
                
            case 'mine':
                // Start coordinated PoS block mining
                $interval = isset($argv[2]) ? (int)$argv[2] : 45;
                $maxTx = isset($argv[3]) ? (int)$argv[3] : 100;
                echo "Starting coordinated PoS block mining (interval: {$interval}s, max transactions: {$maxTx})\n";
                echo "This will coordinate with quick_sync.php running every 60 seconds\n";
                $syncManager->startCoordinatedMining($interval, $maxTx);
                break;
                
            case 'mine-simple':
                // Start simple PoS block mining (old behavior)
                $interval = isset($argv[2]) ? (int)$argv[2] : 30;
                $maxTx = isset($argv[3]) ? (int)$argv[3] : 100;
                echo "Starting simple PoS block mining (interval: {$interval}s, max transactions: {$maxTx})\n";
                $syncManager->startBlockMining($interval, $maxTx);
                break;
                
            case 'mine-once':
                // Mine a single block
                echo "Mining single block from mempool...\n";
                $result = $syncManager->mineNewBlock(100);
                if ($result['success']) {
                    echo "✅ Block mined successfully!\n";
                    echo "   Height: {$result['block_height']}\n";
                    echo "   Hash: {$result['block_hash']}\n";
                    echo "   Transactions: {$result['transactions_count']}\n";
                    echo "   Validator: {$result['validator']}\n";
                } else {
                    echo "ℹ️  {$result['message']}\n";
                }
                break;
                
            default:
                echo "Available commands:\n";
                echo "  sync         - Full blockchain synchronization\n";
                echo "  status       - Show network status\n";
                echo "  mempool      - Run mempool maintenance\n";
                echo "  sync-mempool - Enhanced mempool sync and processing\n";
                echo "  mine         - Start coordinated PoS mining (recommended for multi-node networks)\n";
                echo "  mine-simple  - Start simple PoS mining (single node or testing)\n";
                echo "  mine-once    - Mine a single block from mempool\n";
                echo "\nCoordinated Mining (recommended):\n";
                echo "  php network_sync.php mine 45 100   # Mine every 45s, coordinate with quick_sync\n";
                echo "\nSimple Mining:\n";
                echo "  php network_sync.php mine-simple 30 50  # Mine every 30s without coordination\n";
                echo "\nSingle Block:\n";
                echo "  php network_sync.php mine-once          # Mine one block now\n";
                echo "\nNote: Coordinated mining works with quick_sync.php running every 60 seconds\n";
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
                
            case 'mempool_maintenance':
                $result = $syncManager->runMempoolMaintenance();
                echo json_encode($result);
                break;
                
            case 'enhanced_mempool_sync':
                try {
                    $result = $syncManager->enhancedMempoolSync();
                    echo json_encode($result);
                } catch (Exception $e) {
                    echo json_encode([
                        'success' => false,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
                break;
                
            case 'test_mempool_sync':
                try {
                    // Simple test version
                    $stmt = $syncManager->pdo->query("SELECT COUNT(*) FROM mempool WHERE status = 'pending'");
                    $pendingCount = $stmt->fetchColumn();
                    
                    $result = [
                        'success' => true,
                        'pending_transactions' => $pendingCount,
                        'message' => 'Test successful'
                    ];
                    echo json_encode($result);
                } catch (Exception $e) {
                    echo json_encode([
                        'success' => false,
                        'error' => $e->getMessage()
                    ]);
                }
                break;
                
            case 'mine_block':
                $result = $syncManager->mineNewBlock(100);
                echo json_encode($result);
                break;
                
            case 'get_mempool_status':
                $stmt = $syncManager->pdo->prepare("
                    SELECT status, COUNT(*) as count 
                    FROM mempool 
                    GROUP BY status
                ");
                $stmt->execute();
                $mempoolStats = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $mempoolStats[$row['status']] = $row['count'];
                }
                
                echo json_encode([
                    'status' => 'success',
                    'mempool_stats' => $mempoolStats,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                break;
                
            case 'sync_new_block':
                // Handle notification about new block from another node
                $input = json_decode(file_get_contents('php://input'), true);
                if ($input && isset($input['block_hash'], $input['block_height'])) {
                    $syncManager->log("Received new block notification: {$input['block_hash']} at height {$input['block_height']}");
                    
                    // Check if we need to sync this block
                    $stmt = $syncManager->pdo->prepare("SELECT COUNT(*) FROM blocks WHERE hash = ?");
                    $stmt->execute([$input['block_hash']]);
                    $exists = $stmt->fetchColumn() > 0;
                    
                    if (!$exists) {
                        // Block doesn't exist, trigger sync
                        $syncManager->log("Block not found locally, triggering sync...");
                        try {
                            $sourceNode = $input['source_node'] ?? null;
                            if ($sourceNode) {
                                $result = $syncManager->syncBlocksOnly($sourceNode);
                                echo json_encode(['status' => 'success', 'message' => 'Sync completed', 'result' => $result]);
                            } else {
                                echo json_encode(['status' => 'success', 'message' => 'Block notification received']);
                            }
                        } catch (Exception $e) {
                            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                        }
                    } else {
                        echo json_encode(['status' => 'success', 'message' => 'Block already exists']);
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid block notification']);
                }
                break;
                
            case 'trigger_sync':
                // Trigger background sync (non-blocking)
                $syncManager->log("Sync trigger received from external node");
                echo json_encode(['status' => 'success', 'message' => 'Sync trigger acknowledged']);
                
                // In a production environment, this would trigger a background job
                // For now, we just acknowledge the trigger
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

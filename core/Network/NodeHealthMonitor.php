<?php
/**
 * Node Health Monitor - fast health check on every request
 * Node health monitoring system for budget hosting environments
 */

namespace Blockchain\Core\Network;

use Blockchain\Core\Storage\BlockchainBinaryStorage;
use Blockchain\Core\Storage\SelectiveBlockchainSyncManager;
use Blockchain\Core\Recovery\BlockchainRecoveryManager;
use PDO;
use Exception;

class NodeHealthMonitor
{
    private BlockchainBinaryStorage $binaryStorage;
    private ?BlockchainRecoveryManager $recoveryManager;
    private PDO $database;
    private array $config;
    private string $nodeId;
    private array $knownNodes;
    private int $lastHealthCheck = 0;
    private bool $isRecovering = false;
    
    // Node statuses
    const STATUS_HEALTHY = 'healthy';
    const STATUS_DEGRADED = 'degraded';
    const STATUS_RECOVERING = 'recovering';
    const STATUS_OFFLINE = 'offline';
    const STATUS_ERROR = 'error';
    
    // Check intervals
    const QUICK_CHECK_INTERVAL = 30; // Quick check every 30 seconds
    const FULL_CHECK_INTERVAL = 300; // Full check every 5 minutes
    const RECOVERY_TIMEOUT = 600; // Recovery timeout 10 minutes
    
    public function __construct(
        BlockchainBinaryStorage $binaryStorage,
        PDO $database,
        array $config,
        ?SelectiveBlockchainSyncManager $syncManager = null
    ) {
        $this->binaryStorage = $binaryStorage;
        $this->database = $database;
        $this->config = $config;
        $this->nodeId = $this->generateNodeId();
        $this->knownNodes = $this->loadKnownNodes();
        
        // Create recovery manager if sync manager is provided
        if ($syncManager !== null) {
            $this->recoveryManager = new BlockchainRecoveryManager($database, $binaryStorage, $syncManager, $this->nodeId, $config);
        }
        
        // Create node status table if not exists
        $this->createNodeStatusTable();
    }
    
    /**
     * Quick health check on every request to the node
     * Should execute as fast as possible (< 100ms)
     */
    public function quickHealthCheck(): array
    {
        $startTime = microtime(true);
        $status = [
            'healthy' => true,
            'status' => self::STATUS_HEALTHY,
            'checks' => [],
            'errors' => [],
            'check_time' => 0,
            'node_id' => $this->nodeId,
            'timestamp' => time()
        ];
        
        try {
            // Check 1: Binary file exists
            $binaryExists = file_exists($this->binaryStorage->getBinaryFilePath());
            $status['checks']['binary_file'] = $binaryExists;
            
            if (!$binaryExists) {
                $status['errors'][] = 'Binary blockchain file missing';
                $status['healthy'] = false;
                $status['status'] = self::STATUS_ERROR;
            }
            
            // Check 2: Database availability (simple query)
            try {
                $this->database->query("SELECT 1");
                $status['checks']['database'] = true;
            } catch (Exception $e) {
                $status['checks']['database'] = false;
                $status['errors'][] = 'Database connection failed: ' . $e->getMessage();
                $status['healthy'] = false;
                $status['status'] = self::STATUS_ERROR;
            }
            
            // Check 3: Binary file size (should be > 8 bytes)
            if ($binaryExists) {
                $fileSize = filesize($this->binaryStorage->getBinaryFilePath());
                $status['checks']['binary_size'] = $fileSize > 8;
                
                if ($fileSize <= 8) {
                    $status['errors'][] = 'Binary file too small or corrupted';
                    $status['healthy'] = false;
                    $status['status'] = self::STATUS_ERROR;
                }
            }
            
            // Check 4: Check if recovery is in progress
            if ($this->isRecovering) {
                $status['healthy'] = false;
                $status['status'] = self::STATUS_RECOVERING;
                $status['errors'][] = 'Node is currently recovering';
            }
            
            // If there are errors - notify the network
            if (!$status['healthy']) {
                $this->notifyNetworkOfStatus($status);
                
                // If not in recovery process - start it
                if (!$this->isRecovering && $status['status'] !== self::STATUS_RECOVERING) {
                    $this->startRecoveryProcess();
                }
            }
            
        } catch (Exception $e) {
            $status['healthy'] = false;
            $status['status'] = self::STATUS_ERROR;
            $status['errors'][] = 'Health check failed: ' . $e->getMessage();
            $this->notifyNetworkOfStatus($status);
        }
        
        $status['check_time'] = round((microtime(true) - $startTime) * 1000, 2);
        
        // Update last health check
        $this->lastHealthCheck = time();
        
        return $status;
    }
    
    /**
     * Full health check (more detailed but slower)
     */
    public function fullHealthCheck(): array
    {
        $startTime = microtime(true);
        $quickCheck = $this->quickHealthCheck();
        
        if (!$quickCheck['healthy']) {
            return $quickCheck; // If quick check failed, no point continuing
        }
        
        $status = $quickCheck;
        $status['full_check'] = true;
        
        try {
            // Check binary file integrity
            $binaryValidation = $this->binaryStorage->validateBinaryFile();
            $status['checks']['binary_integrity'] = $binaryValidation['valid'];
            
            if (!$binaryValidation['valid']) {
                $status['healthy'] = false;
                $status['status'] = self::STATUS_DEGRADED;
                $status['errors'] = array_merge($status['errors'], $binaryValidation['errors']);
            }
            
            // Check database synchronization
            $syncCheck = $this->checkDatabaseSync();
            $status['checks']['database_sync'] = $syncCheck['synced'];
            
            if (!$syncCheck['synced']) {
                $status['healthy'] = false;
                $status['status'] = self::STATUS_DEGRADED;
                $status['errors'] = array_merge($status['errors'], $syncCheck['errors']);
            }
            
            // Check connectivity to other nodes
            $networkCheck = $this->checkNetworkConnectivity();
            $status['checks']['network'] = $networkCheck['healthy'];
            $status['network_nodes'] = $networkCheck['nodes'];
            
            if (!$networkCheck['healthy']) {
                $status['status'] = self::STATUS_DEGRADED;
                $status['errors'][] = 'Limited network connectivity';
            }
            
            // Additional detailed checks
            
            // Check binary file integrity
            try {
                $validation = $this->binaryStorage->validateBinaryFile();
                $status['checks']['binary_integrity'] = $validation['valid'];
                
                if (!$validation['valid']) {
                    $status['errors'] = array_merge($status['errors'], $validation['errors']);
                    $status['healthy'] = false;
                    $status['status'] = self::STATUS_ERROR;
                }
            } catch (Exception $e) {
                $status['checks']['binary_integrity'] = false;
                $status['errors'][] = 'Binary file validation failed: ' . $e->getMessage();
                $status['healthy'] = false;
                $status['status'] = self::STATUS_ERROR;
            }
            
            // Check database table integrity
            try {
                $tables = ['blocks', 'transactions', 'wallets', 'mempool', 'nodes'];
                foreach ($tables as $table) {
                    $stmt = $this->database->query("SELECT COUNT(*) FROM {$table}");
                    $stmt->fetch();
                }
                $status['checks']['database_tables'] = true;
            } catch (Exception $e) {
                $status['checks']['database_tables'] = false;
                $status['errors'][] = 'Database table check failed: ' . $e->getMessage();
                $status['healthy'] = false;
                $status['status'] = self::STATUS_ERROR;
            }
            
            // Check storage space
            $freeSpace = disk_free_space(dirname($this->binaryStorage->getBinaryFilePath()));
            $requiredSpace = 100 * 1024 * 1024; // 100MB minimum
            $status['checks']['storage_space'] = $freeSpace > $requiredSpace;
            
            if ($freeSpace <= $requiredSpace) {
                $status['errors'][] = 'Insufficient storage space';
                $status['healthy'] = false;
                $status['status'] = self::STATUS_DEGRADED;
            }
            
            // Check memory usage
            $memoryUsage = memory_get_usage(true);
            $memoryLimit = ini_get('memory_limit');
            
            if ($memoryLimit !== '-1') {
                $limitBytes = $this->parseMemoryLimit($memoryLimit);
                $status['checks']['memory_usage'] = $memoryUsage < ($limitBytes * 0.9);
                
                if ($memoryUsage >= ($limitBytes * 0.9)) {
                    $status['errors'][] = 'High memory usage';
                    $status['healthy'] = false;
                    $status['status'] = self::STATUS_DEGRADED;
                }
            } else {
                $status['checks']['memory_usage'] = true;
            }
            
            // Add detailed information
            $status['details'] = [
                'binary_file_size' => file_exists($this->binaryStorage->getBinaryFilePath()) 
                    ? filesize($this->binaryStorage->getBinaryFilePath()) : 0,
                'memory_usage' => $memoryUsage,
                'memory_peak' => memory_get_peak_usage(true),
                'disk_free' => $freeSpace,
                'php_version' => PHP_VERSION,
                'block_count' => $this->binaryStorage->getBlockCount()
            ];
            
        } catch (Exception $e) {
            $status['healthy'] = false;
            $status['status'] = self::STATUS_ERROR;
            $status['errors'][] = 'Full health check failed: ' . $e->getMessage();
        }
        
        $status['check_time'] = round((microtime(true) - $startTime) * 1000, 2);
        
        return $status;
    }
    
    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $number = (int) $limit;
        
        switch ($last) {
            case 'g':
                $number *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $number *= 1024 * 1024;
                break;
            case 'k':
                $number *= 1024;
                break;
        }
        
        return $number;
    }
    
    /**
     * Start recovery process in background
     */
    private function startRecoveryProcess(): void
    {
        if ($this->isRecovering) {
            return; // Already recovering
        }
        
        $this->isRecovering = true;
        
        // Notify network about starting recovery
        $this->notifyNetworkOfStatus([
            'healthy' => false,
            'status' => self::STATUS_RECOVERING,
            'node_id' => $this->nodeId,
            'timestamp' => time(),
            'message' => 'Starting recovery process'
        ]);
        
        try {
            // Log recovery start
            error_log("Node {$this->nodeId}: Starting recovery process");
            
            // Perform recovery through recovery manager
            $recoveryResult = $this->recoveryManager->performAutoRecovery();
            
            if ($recoveryResult['success']) {
                // Recovery successful
                $this->isRecovering = false;
                
                // Notify network about successful recovery
                $this->notifyNetworkOfStatus([
                    'healthy' => true,
                    'status' => self::STATUS_HEALTHY,
                    'node_id' => $this->nodeId,
                    'timestamp' => time(),
                    'message' => 'Recovery completed successfully'
                ]);
                
                error_log("Node {$this->nodeId}: Recovery completed successfully");
                
            } else {
                // Recovery failed
                $this->isRecovering = false;
                
                $this->notifyNetworkOfStatus([
                    'healthy' => false,
                    'status' => self::STATUS_ERROR,
                    'node_id' => $this->nodeId,
                    'timestamp' => time(),
                    'message' => 'Recovery failed: ' . implode(', ', $recoveryResult['errors'])
                ]);
                
                error_log("Node {$this->nodeId}: Recovery failed: " . implode(', ', $recoveryResult['errors']));
            }
            
        } catch (Exception $e) {
            $this->isRecovering = false;
            
            $this->notifyNetworkOfStatus([
                'healthy' => false,
                'status' => self::STATUS_ERROR,
                'node_id' => $this->nodeId,
                'timestamp' => time(),
                'message' => 'Recovery exception: ' . $e->getMessage()
            ]);
            
            error_log("Node {$this->nodeId}: Recovery exception: " . $e->getMessage());
        }
    }
    
    /**
     * Notify network about node status using MultiCurl
     */
    private function notifyNetworkOfStatus(array $status): void
    {
        try {
            $notification = [
                'type' => 'node_status_update',
                'node_id' => $this->nodeId,
                'status' => $status,
                'timestamp' => time(),
                'network_id' => $this->config['network_id'] ?? 'default'
            ];
            
            // Prepare URLs for notification
            $urls = [];
            foreach ($this->knownNodes as $node) {
                if ($node['id'] !== $this->nodeId && $node['status'] !== self::STATUS_OFFLINE) {
                    $urls[] = $node['url'] . '/api/node/status-update';
                }
            }
            
            if (empty($urls)) {
                return; // No available nodes to notify
            }
            
            // Use MultiCurl for fast notification to all nodes
            $multiCurl = new \Blockchain\Core\Network\MultiCurl();
            
            $requests = [];
            foreach ($urls as $url) {
                $requests[] = [
                    'url' => $url,
                    'method' => 'POST',
                    'data' => json_encode($notification),
                    'headers' => [
                        'Content-Type: application/json',
                        'X-Node-ID: ' . $this->nodeId
                    ],
                    'timeout' => 5 // Fast timeout for notifications
                ];
            }
            
            $responses = $multiCurl->executeBatch($requests);
            
            // Update node status based on responses
            $this->updateNodeStatusFromResponses($responses, $urls);
            
            // Log notification
            error_log("Node {$this->nodeId}: Notified " . count($urls) . " nodes about status: {$status['status']}");
            
        } catch (Exception $e) {
            error_log("Node {$this->nodeId}: Failed to notify network: " . $e->getMessage());
        }
    }
    
    /**
     * Handle status notification from another node
     */
    public function handleStatusUpdate(array $notification): array
    {
        try {
            // Validate notification
            if (!$this->validateStatusNotification($notification)) {
                return ['success' => false, 'error' => 'Invalid notification'];
            }
            
            // Store the status update
            $this->storeNodeStatus($notification);
            
            // If another node is unhealthy, we might need to help with recovery
            if ($notification['status'] === 'unhealthy') {
                $this->handlePeerUnhealthy($notification);
            }
            
            return ['success' => true, 'message' => 'Status update processed'];
            
        } catch (Exception $e) {
            error_log("Failed to handle status update: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Check database synchronization
     */
    private function checkDatabaseSync(): array
    {
        try {
            // Simple check: compare block count in file and DB
            $binaryBlocks = 0;
            $dbBlocks = 0;
            
            // Count blocks in binary file
            $validation = $this->binaryStorage->validateBinaryFile();
            $binaryBlocks = $validation['blocks_count'] ?? 0;
            
            // Count blocks in DB
            $stmt = $this->database->query("SELECT COUNT(*) as count FROM blocks");
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            $dbBlocks = $result['count'] ?? 0;
            
            $synced = abs($binaryBlocks - $dbBlocks) <= 1; // Allow difference of 1 block
            
            return [
                'synced' => $synced,
                'binary_blocks' => $binaryBlocks,
                'db_blocks' => $dbBlocks,
                'errors' => $synced ? [] : ["Block count mismatch: binary={$binaryBlocks}, db={$dbBlocks}"]
            ];
            
        } catch (Exception $e) {
            return [
                'synced' => false,
                'errors' => ['Database sync check failed: ' . $e->getMessage()]
            ];
        }
    }
    
    /**
     * Check network connectivity to other nodes
     */
    private function checkNetworkConnectivity(): array
    {
        $healthyNodes = 0;
        $totalNodes = count($this->knownNodes);
        $nodeStatus = [];
        
        if ($totalNodes === 0) {
            return [
                'healthy' => true, // If no other nodes, consider it ok
                'nodes' => []
            ];
        }
        
        try {
            $multiCurl = new \Blockchain\Core\Network\MultiCurl();
            $requests = [];
            
            foreach ($this->knownNodes as $node) {
                if ($node['id'] !== $this->nodeId) {
                    $requests[] = [
                        'url' => $node['url'] . '/api/health',
                        'method' => 'GET',
                        'timeout' => 3
                    ];
                }
            }
            
            if (empty($requests)) {
                return ['healthy' => true, 'nodes' => []];
            }
            
            $responses = $multiCurl->executeBatch($requests);
            
            foreach ($responses as $i => $response) {
                $node = array_values(array_filter($this->knownNodes, fn($n) => $n['id'] !== $this->nodeId))[$i];
                
                if ($response['success'] && $response['http_code'] === 200) {
                    $healthyNodes++;
                    $nodeStatus[] = [
                        'id' => $node['id'],
                        'url' => $node['url'],
                        'status' => 'healthy',
                        'response_time' => $response['response_time'] ?? 0
                    ];
                } else {
                    $nodeStatus[] = [
                        'id' => $node['id'],
                        'url' => $node['url'],
                        'status' => 'unreachable',
                        'error' => $response['error'] ?? 'Unknown error'
                    ];
                }
            }
            
        } catch (Exception $e) {
            error_log("Network connectivity check failed: " . $e->getMessage());
        }
        
        $connectivityRatio = $totalNodes > 0 ? $healthyNodes / ($totalNodes - 1) : 1;
        
        return [
            'healthy' => $connectivityRatio >= 0.5, // Consider network healthy if >= 50% nodes available
            'nodes' => $nodeStatus,
            'healthy_nodes' => $healthyNodes,
            'total_nodes' => $totalNodes - 1
        ];
    }
    
    /**
     * Create node status table
     */
    private function createNodeStatusTable(): void
    {
        try {
            $this->database->exec("
                CREATE TABLE IF NOT EXISTS node_status (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    node_id VARCHAR(64) NOT NULL,
                    status VARCHAR(32) NOT NULL,
                    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    health_data JSON,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_node (node_id),
                    INDEX idx_status (status),
                    INDEX idx_last_seen (last_seen)
                )
            ");
        } catch (Exception $e) {
            error_log("Failed to create node_status table: " . $e->getMessage());
        }
    }
    
    /**
     * Update node status in DB
     */
    private function updateNodeStatus(string $nodeId, array $status, int $timestamp): void
    {
        try {
            $stmt = $this->database->prepare("
                INSERT INTO node_status (node_id, status, health_data, last_seen)
                VALUES (?, ?, ?, FROM_UNIXTIME(?))
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    health_data = VALUES(health_data),
                    last_seen = VALUES(last_seen)
            ");
            
            $stmt->execute([
                $nodeId,
                $status['status'] ?? self::STATUS_UNKNOWN,
                json_encode($status),
                $timestamp
            ]);
            
        } catch (Exception $e) {
            error_log("Failed to update node status: " . $e->getMessage());
        }
    }
    
    /**
     * Mark node as healthy
     */
    private function markNodeAsHealthy(string $nodeId): void
    {
        foreach ($this->knownNodes as &$node) {
            if ($node['id'] === $nodeId) {
                $node['status'] = self::STATUS_HEALTHY;
                break;
            }
        }
    }
    
    /**
     * Update node status based on responses
     */
    private function updateNodeStatusFromResponses(array $responses, array $urls): void
    {
        foreach ($responses as $i => $response) {
            if (isset($urls[$i])) {
                $nodeUrl = $urls[$i];
                
                foreach ($this->knownNodes as &$node) {
                    if (strpos($nodeUrl, $node['url']) !== false) {
                        $node['status'] = $response['success'] ? self::STATUS_HEALTHY : self::STATUS_OFFLINE;
                        break;
                    }
                }
            }
        }
    }
    
    /**
     * Load list of known nodes
     */
    private function loadKnownNodes(): array
    {
        try {
            $stmt = $this->database->query("
                SELECT node_id as id, url, status 
                FROM nodes 
                WHERE status != 'disabled'
                ORDER BY last_seen DESC
            ");
            
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            
        } catch (Exception $e) {
            error_log("Failed to load known nodes: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Generate node ID
     */
    private function generateNodeId(): string
    {
        $nodeId = $this->config['node_id'] ?? null;
        
        if (!$nodeId) {
            $nodeId = hash('sha256', gethostname() . $_SERVER['SERVER_NAME'] ?? 'localhost' . time());
        }
        
        return $nodeId;
    }
    
    /**
     * Get current node status
     */
    public function getCurrentStatus(): string
    {
        if ($this->isRecovering) {
            return self::STATUS_RECOVERING;
        }
        
        $quickCheck = $this->quickHealthCheck();
        return $quickCheck['status'];
    }
    
    /**
     * Check if health check is needed
     */
    public function needsHealthCheck(): bool
    {
        return (time() - $this->lastHealthCheck) > self::QUICK_CHECK_INTERVAL;
    }
    
    /**
     * Get network statistics
     */
    public function getNetworkStats(): array
    {
        try {
            $stmt = $this->database->query("
                SELECT status, COUNT(*) as count 
                FROM node_status 
                WHERE updated_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                GROUP BY status
            ");
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Failed to get network stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Validate status notification from peer
     */
    private function validateStatusNotification(array $notification): bool
    {
        $required = ['node_id', 'status', 'timestamp'];
        
        foreach ($required as $field) {
            if (!isset($notification[$field])) {
                return false;
            }
        }
        
        // Check timestamp is recent (within 5 minutes)
        if (abs(time() - $notification['timestamp']) > 300) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Store node status in database
     */
    private function storeNodeStatus(array $notification): void
    {
        try {
            $stmt = $this->database->prepare("
                INSERT INTO node_status (node_id, status, health_data, last_seen)
                VALUES (?, ?, ?, FROM_UNIXTIME(?))
                ON DUPLICATE KEY UPDATE 
                    status = VALUES(status),
                    health_data = VALUES(health_data),
                    last_seen = VALUES(last_seen)
            ");
            
            $status = $notification['status'];
            $statusStr = is_array($status) ? ($status['status'] ?? 'unknown') : $status;
            
            $stmt->execute([
                $notification['node_id'],
                $statusStr,
                json_encode($notification),
                $notification['timestamp']
            ]);
            
        } catch (Exception $e) {
            error_log("Failed to store node status: " . $e->getMessage());
        }
    }
    
    /**
     * Handle peer node unhealthy state
     */
    private function handlePeerUnhealthy(array $notification): void
    {
        // Log that a peer is unhealthy
        error_log("Peer node unhealthy: " . $notification['node_id'] . " at " . $notification['node_url']);
        
        // Here we could implement peer recovery assistance
        // For example, offering to share our blockchain data
    }
}

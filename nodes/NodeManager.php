<?php
declare(strict_types=1);

namespace Blockchain\Nodes;

use Blockchain\Core\Contracts\NodeInterface;
use Blockchain\Core\Contracts\BlockInterface;
use Blockchain\Core\Contracts\TransactionInterface;
use Blockchain\Core\Network\MultiCurl;
use Blockchain\Core\Database\DatabaseManager;
use Blockchain\Core\Events\EventDispatcher;
use Blockchain\Core\Logging\LoggerInterface;
use Exception;

/**
 * Node manager with multi-curl support for efficient network interaction
 */
class NodeManager
{
    private array $nodes;
    private MultiCurl $multiCurl;
    private EventDispatcher $eventDispatcher;
    private LoggerInterface $logger;
    private array $nodeStatuses;
    private int $maxConnections;
    private int $connectionTimeout;
    private int $requestTimeout;
    private array $bannedNodes;
    private array $trustedNodes;
    private array $config;

    public function __construct(
        MultiCurl $multiCurl,
        EventDispatcher $eventDispatcher,
        LoggerInterface $logger,
        array $config = [],
        int $maxConnections = 50
    ) {
        $this->nodes = [];
        $this->multiCurl = $multiCurl;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
        $this->nodeStatuses = [];
        $this->maxConnections = $maxConnections;
        $this->connectionTimeout = 5;
        $this->requestTimeout = 30;
        $this->bannedNodes = [];
        $this->trustedNodes = [];
        $this->config = $config;
    }

    /**
     * Get User-Agent string from configuration
     */
    private function getUserAgent(): string
    {
        return $this->config['network']['user_agent'] ?? 'BlockchainNode/2.0';
    }

    /**
     * Add node to network
     */
    public function addNode($node): void
    {
        $nodeId = $node->getId();
        $this->nodes[$nodeId] = $node;
        $this->nodeStatuses[$nodeId] = [
            'status' => 'connecting',
            'lastSeen' => time(),
            'version' => $node->getVersion(),
            'errors' => 0,
            'latency' => 0
        ];

        $this->logger->info("Node added: {$nodeId}", [
            'address' => $node->getAddress(),
            'port' => $node->getPort()
        ]);
    }

    /**
     * Remove node from network
     */
    public function removeNode(string $nodeId): void
    {
        if (isset($this->nodes[$nodeId])) {
            unset($this->nodes[$nodeId]);
            unset($this->nodeStatuses[$nodeId]);
            
            $this->logger->info("Node removed: {$nodeId}");
        }
    }

    /**
     * Broadcast block to all nodes with enhanced processing
     */
    public function broadcastBlock(BlockInterface $block): array
    {
        $activeNodes = $this->getActiveNodes();
        
        if (empty($activeNodes)) {
            $this->logger->warning('No active nodes available for block broadcast');
            return [];
        }

        // Try enhanced broadcast first
        try {
            require_once __DIR__ . '/../core/Sync/EnhancedSyncManager.php';
            require_once __DIR__ . '/../core/Logging/NullLogger.php';
            
            $enhancedSync = new \Blockchain\Core\Sync\EnhancedSyncManager([
                'batch_processing' => true,
                'load_balancing' => true
            ], new \Blockchain\Core\Logging\NullLogger());
            
            // Process as batch event
            $eventResult = $enhancedSync->processSyncEvent('block.added', [
                'block_hash' => $block->getHash(),
                'block_height' => method_exists($block, 'getIndex') ? $block->getIndex() : 0,
                'broadcast_nodes' => array_keys($activeNodes)
            ], $this->getCurrentNodeId(), 1);
            
            if ($eventResult['success']) {
                $this->logger->info('Enhanced block broadcast queued', [
                    'block_hash' => $block->getHash(),
                    'processing_method' => $eventResult['processing_method']
                ]);
                
                // Return success result for enhanced processing
                return [
                    'enhanced' => true,
                    'success' => true,
                    'processing_method' => $eventResult['processing_method'],
                    'nodes_count' => count($activeNodes)
                ];
            }
        } catch (Exception $e) {
            $this->logger->warning('Enhanced broadcast failed, falling back to traditional: ' . $e->getMessage());
        }

        // Fallback to traditional broadcast
        $requests = [];
        $currentNodeId = $this->getCurrentNodeId();
        $secret = $this->getBroadcastSecret();
        $payload = [
            'block_hash' => $block->getHash(),
            'block_height' => method_exists($block, 'getIndex') ? $block->getIndex() : 0,
            'source_node' => $currentNodeId,
            'timestamp' => time(),
        ];
        $payload['event_id'] = hash('sha256', $payload['block_hash'] . '|' . $payload['block_height'] . '|' . $payload['timestamp']);
        $body = json_encode($payload);

        foreach ($activeNodes as $nodeId => $node) {
            $url = rtrim($node->getApiUrl(), '/') . '/network_sync.php?action=block';
            $headers = [
                'Content-Type: application/json',
                'User-Agent: ' . $this->getUserAgent(),
                'X-Node-Id: ' . $currentNodeId
            ];
            if ($secret !== '') {
                $sig = hash_hmac('sha256', (string)$body, $secret);
                $headers[] = 'X-Broadcast-Signature: sha256=' . $sig;
            }
            $requests[$nodeId] = [
                'url' => $url,
                'method' => 'POST',
                'data' => $body,
                'headers' => $headers
            ];
        }

        $results = $this->multiCurl->executeRequests($requests);
        $this->processNodeResponses($results, 'block_broadcast');

        $this->logger->info('Traditional block broadcast completed', [
            'block_hash' => $block->getHash(),
            'nodes_count' => count($activeNodes),
            'successful' => count(array_filter($results, fn($r) => $r['success']))
        ]);

        return array_merge($results, ['enhanced' => false]);
    }

    /**
     * Resolve shared HMAC secret for inter-node broadcast from config/env
     */
    private function getBroadcastSecret(): string
    {
        // 1) Database config has highest priority
        try {
            $pdo = DatabaseManager::getConnection();
            $stmt = $pdo->prepare("SELECT value FROM config WHERE key_name = 'network.broadcast_secret' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if (is_string($val) && $val !== '') {
                return (string)$val;
            }
        } catch (\Throwable $e) {
            // ignore and fallback
        }

        // 2) App config
        if (!empty($this->config['network']['broadcast_secret']) && is_string($this->config['network']['broadcast_secret'])) {
            return (string)$this->config['network']['broadcast_secret'];
        }

        // 3) Environment variables
        $candidates = [
            $_ENV['BROADCAST_SECRET'] ?? null,
            $_ENV['NETWORK_BROADCAST_SECRET'] ?? null,
            getenv('BROADCAST_SECRET') ?: null,
            getenv('NETWORK_BROADCAST_SECRET') ?: null,
        ];
        foreach ($candidates as $val) {
            if (is_string($val) && $val !== '') {
                return $val;
            }
        }
        return '';
    }

    /**
     * Broadcast transaction to all nodes
     */
    public function broadcastTransaction(TransactionInterface $transaction): array
    {
        $activeNodes = $this->getActiveNodes();
        
        if (empty($activeNodes)) {
            $this->logger->warning('No active nodes available for transaction broadcast');
            return [];
        }

        $requests = [];
        foreach ($activeNodes as $nodeId => $node) {
            $requests[$nodeId] = [
                'url' => $node->getApiUrl() . '/transaction',
                'method' => 'POST',
                'data' => json_encode($transaction->toArray()),
                'headers' => [
                    'Content-Type: application/json',
                    'User-Agent: ' . $this->getUserAgent(),
                    'X-Node-Id: ' . $this->getCurrentNodeId()
                ]
            ];
        }

        $results = $this->multiCurl->executeRequests($requests);
        $this->processNodeResponses($results, 'transaction_broadcast');

        $this->logger->info('Transaction broadcast completed', [
            'transaction_hash' => $transaction->getHash(),
            'nodes_count' => count($activeNodes),
            'successful' => count(array_filter($results, fn($r) => $r['success']))
        ]);

        return $results;
    }

    /**
     * Synchronize with network
     */
    public function synchronizeWithNetwork(): array
    {
        $activeNodes = $this->getActiveNodes();
        $syncResults = [];

        if (empty($activeNodes)) {
            $this->logger->warning('No active nodes available for synchronization');
            return [];
        }

        // 1. Get blockchain information from all nodes
        $chainInfoRequests = [];
        foreach ($activeNodes as $nodeId => $node) {
            $chainInfoRequests[$nodeId] = [
                'url' => $node->getApiUrl() . '/blockchain/info',
                'method' => 'GET',
                'headers' => [
                    'User-Agent: ' . $this->getUserAgent(),
                    'X-Node-Id: ' . $this->getCurrentNodeId()
                ]
            ];
        }

        $chainInfoResults = $this->multiCurl->executeRequests($chainInfoRequests);
        $this->processNodeResponses($chainInfoResults, 'chain_info');

        // 2. Find node with longest chain
        $longestChainNode = $this->findLongestChainNode($chainInfoResults);
        
        if (!$longestChainNode) {
            $this->logger->error('Failed to find valid chain from network nodes');
            return [];
        }

        // 3. Synchronize blocks
        $syncResults = $this->synchronizeBlocks($longestChainNode);

        $this->logger->info('Network synchronization completed', [
            'longest_chain_node' => $longestChainNode['nodeId'],
            'blocks_synced' => $syncResults['blocks_synced'] ?? 0
        ]);

        return $syncResults;
    }

    /**
     * Search for new nodes in network
     */
    public function discoverNodes(): array
    {
        $activeNodes = $this->getActiveNodes();
        $discoveredNodes = [];

        $peerRequests = [];
        foreach ($activeNodes as $nodeId => $node) {
            $peerRequests[$nodeId] = [
                'url' => $node->getApiUrl() . '/peers',
                'method' => 'GET',
                'headers' => [
                    'User-Agent: ' . $this->getUserAgent(),
                    'X-Node-Id: ' . $this->getCurrentNodeId()
                ]
            ];
        }

        $peerResults = $this->multiCurl->executeRequests($peerRequests);
        
        foreach ($peerResults as $nodeId => $result) {
            if ($result['success'] && isset($result['data']['peers'])) {
                foreach ($result['data']['peers'] as $peerInfo) {
                    $peerId = $peerInfo['id'] ?? null;
                    
                    if ($peerId && !isset($this->nodes[$peerId]) && !in_array($peerId, $this->bannedNodes)) {
                        $discoveredNodes[] = $peerInfo;
                    }
                }
            }
        }

        $this->logger->info('Node discovery completed', [
            'discovered_count' => count($discoveredNodes)
        ]);

        return $discoveredNodes;
    }

    /**
     * Check status of all nodes
     */
    public function checkNodesHealth(): array
    {
        $healthRequests = [];
        
        foreach ($this->nodes as $nodeId => $node) {
            $healthRequests[$nodeId] = [
                'url' => $node->getApiUrl() . '/health',
                'method' => 'GET',
                'timeout' => $this->connectionTimeout,
                'headers' => [
                    'User-Agent: ' . $this->getUserAgent(),
                    'X-Node-Id: ' . $this->getCurrentNodeId()
                ]
            ];
        }

        $results = $this->multiCurl->executeRequests($healthRequests);
        
        foreach ($results as $nodeId => $result) {
            $this->updateNodeStatus($nodeId, $result);
        }

        $this->logger->debug('Node health check completed', [
            'total_nodes' => count($this->nodes),
            'active_nodes' => count($this->getActiveNodes()),
            'failed_nodes' => count($this->getFailedNodes())
        ]);

        return $this->nodeStatuses;
    }

    /**
     * Get active nodes
     */
    public function getActiveNodes(): array
    {
        return array_filter($this->nodes, function($nodeId) {
            $status = $this->nodeStatuses[$nodeId] ?? null;
            return $status && $status['status'] === 'active' && !in_array($nodeId, $this->bannedNodes);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Get failed nodes
     */
    public function getFailedNodes(): array
    {
        return array_filter($this->nodes, function($nodeId) {
            $status = $this->nodeStatuses[$nodeId] ?? null;
            return $status && $status['status'] === 'failed';
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Process responses from nodes
     */
    private function processNodeResponses(array $results, string $operation): void
    {
        foreach ($results as $nodeId => $result) {
            if ($result['success']) {
                $this->handleSuccessfulResponse($nodeId, $result, $operation);
            } else {
                $this->handleFailedResponse($nodeId, $result, $operation);
            }
        }
    }

    /**
     * Handle successful response from node
     */
    private function handleSuccessfulResponse(string $nodeId, array $result, string $operation): void
    {
        if (isset($this->nodeStatuses[$nodeId])) {
            $this->nodeStatuses[$nodeId]['status'] = 'active';
            $this->nodeStatuses[$nodeId]['lastSeen'] = time();
            $this->nodeStatuses[$nodeId]['errors'] = 0;
            $this->nodeStatuses[$nodeId]['latency'] = $result['time'] ?? 0;
        }

        $this->logger->debug("Successful response from node {$nodeId}", [
            'operation' => $operation,
            'latency' => $result['time'] ?? 0
        ]);
    }

    /**
     * Handle failed response from node
     */
    private function handleFailedResponse(string $nodeId, array $result, string $operation): void
    {
        if (isset($this->nodeStatuses[$nodeId])) {
            $this->nodeStatuses[$nodeId]['errors']++;
            
            if ($this->nodeStatuses[$nodeId]['errors'] >= 3) {
                $this->nodeStatuses[$nodeId]['status'] = 'failed';
            }
        }

        $this->logger->warning("Failed response from node {$nodeId}", [
            'operation' => $operation,
            'error' => $result['error'] ?? 'Unknown error',
            'error_count' => $this->nodeStatuses[$nodeId]['errors'] ?? 0
        ]);

        // Ban node if too many errors
        if (($this->nodeStatuses[$nodeId]['errors'] ?? 0) >= 10) {
            $this->banNode($nodeId, 'Too many errors');
        }
    }

    /**
     * Find node with the longest chain
     */
    private function findLongestChainNode(array $chainInfoResults): ?array
    {
        $longestChain = null;
        $maxHeight = -1;

        foreach ($chainInfoResults as $nodeId => $result) {
            if ($result['success'] && isset($result['data']['height'])) {
                $height = (int)$result['data']['height'];
                
                if ($height > $maxHeight) {
                    $maxHeight = $height;
                    $longestChain = [
                        'nodeId' => $nodeId,
                        'height' => $height,
                        'data' => $result['data']
                    ];
                }
            }
        }

        return $longestChain;
    }

    /**
     * Detect synchronization needs and provide sync recommendations
     * NOTE: Actual sync should be handled by NetworkSyncManager
     */
    private function synchronizeBlocks(array $longestChainNode): array
    {
        $nodeId = $longestChainNode['nodeId'];
        $targetHeight = $longestChainNode['height'];
        $nodeData = $longestChainNode['data'] ?? [];
        
        $this->logger->info("NodeManager detected sync opportunity", [
            'target_node' => $nodeId,
            'target_height' => $targetHeight,
            'node_data' => $nodeData
        ]);
        
        // Provide detailed sync recommendation
        $recommendation = [
                'success' => true,
            'action' => 'sync_recommended',
            'sync_source' => [
                'node_id' => $nodeId,
                'height' => $targetHeight,
                'url' => $this->nodes[$nodeId]->getApiUrl() ?? 'unknown'
            ],
            'recommendation' => 'Use NetworkSyncManager for blockchain synchronization',
            'blocks_behind' => max(0, $targetHeight - ($nodeData['current_height'] ?? 0)),
            'priority' => $targetHeight > 1000 ? 'high' : 'normal'
        ];
        
        // Dispatch sync recommendation event if available
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch('sync.recommended', $recommendation);
        }
        
        return $recommendation;
    }

    /**
     * Update node status
     */
    private function updateNodeStatus(string $nodeId, array $result): void
    {
        if (!isset($this->nodeStatuses[$nodeId])) {
            return;
        }

        if ($result['success']) {
            $this->nodeStatuses[$nodeId]['status'] = 'active';
            $this->nodeStatuses[$nodeId]['lastSeen'] = time();
            $this->nodeStatuses[$nodeId]['errors'] = 0;
            $this->nodeStatuses[$nodeId]['latency'] = $result['time'] ?? 0;
        } else {
            $this->nodeStatuses[$nodeId]['errors']++;
            
            if ($this->nodeStatuses[$nodeId]['errors'] >= 3) {
                $this->nodeStatuses[$nodeId]['status'] = 'failed';
            }
        }
    }

    /**
     * Ban node
     */
    public function banNode(string $nodeId, string $reason): void
    {
        $this->bannedNodes[] = $nodeId;
        
        $this->logger->warning("Node banned: {$nodeId}", [
            'reason' => $reason
        ]);
    }

    /**
     * Unban node
     */
    public function unbanNode(string $nodeId): void
    {
        $this->bannedNodes = array_filter($this->bannedNodes, fn($id) => $id !== $nodeId);
        
        $this->logger->info("Node unbanned: {$nodeId}");
    }

    /**
     * Add trusted node
     */
    public function addTrustedNode(string $nodeId): void
    {
        if (!in_array($nodeId, $this->trustedNodes)) {
            $this->trustedNodes[] = $nodeId;
        }
    }

    /**
     * Get current node ID
     */
    private function getCurrentNodeId(): string
    {
        // Try to get from config first
        if (!empty($this->config['node']['id'])) {
            return (string)$this->config['node']['id'];
        }
        
        // Try to get from environment
        $envNodeId = $_ENV['NODE_ID'] ?? getenv('NODE_ID');
        if ($envNodeId) {
            return (string)$envNodeId;
        }
        
        // Fallback to hostname-based ID
        return 'node_' . gethostname() . '_' . substr(md5(gethostname()), 0, 8);
    }

    /**
     * Returns base URLs of active nodes for synchronization
     * Format: [nodeId => baseUrl]
     */
    public function getActiveNodeUrls(): array
    {
        $urls = [];
        foreach ($this->getActiveNodes() as $nodeId => $node) {
            // Prefer explicit getApiUrl() method from NodeInterface
            if (is_object($node) && method_exists($node, 'getApiUrl')) {
                $urls[$nodeId] = rtrim($node->getApiUrl(), '/');
                continue;
            }
            // Compatibility: if the node is stored as an array
            if (is_array($node) && isset($node['url'])) {
                $urls[$nodeId] = rtrim($node['url'], '/');
            }
        }
        return $urls;
    }

    /**
     * Get network statistics
     */
    public function getNetworkStats(): array
    {
        $activeCount = count($this->getActiveNodes());
        $totalCount = count($this->nodes);
        $bannedCount = count($this->bannedNodes);
        
        $avgLatency = 0;
        if ($activeCount > 0) {
            $latencies = array_map(fn($status) => $status['latency'], $this->nodeStatuses);
            $avgLatency = array_sum($latencies) / count($latencies);
        }

        return [
            'total_nodes' => $totalCount,
            'active_nodes' => $activeCount,
            'failed_nodes' => $totalCount - $activeCount,
            'banned_nodes' => $bannedCount,
            'trusted_nodes' => count($this->trustedNodes),
            'average_latency' => round($avgLatency, 2),
            'max_connections' => $this->maxConnections
        ];
    }

    /**
     * Request specific block from node using MultiCurl
     * Adapt endpoint to explorer API compatible with network_sync.php
     */
    private function requestBlockFromNode(string $nodeId, int $height): ?array
    {
        if (!isset($this->nodes[$nodeId])) {
            return null;
        }

        $node = $this->nodes[$nodeId];

        // Get base URL
        $baseUrl = null;
        if (is_object($node) && method_exists($node, 'getApiUrl')) {
            $baseUrl = rtrim($node->getApiUrl(), '/');
        } elseif (is_array($node) && isset($node['url'])) {
            $baseUrl = rtrim($node['url'], '/');
        }
        if (!$baseUrl) {
            return null;
        }

        // Unified block retrieval endpoint
        // Use explorer API: /api/explorer/index.php?action=get_block&block_id={height}
        $url = $baseUrl . '/api/explorer/index.php?action=get_block&block_id=' . $height;

        try {
            $result = $this->multiCurl->get($url, [
                'User-Agent: ' . $this->getUserAgent(),
                'X-Node-Id: ' . $this->getCurrentNodeId(),
                'Accept: application/json'
            ]);
            if (($result['success'] ?? false) && isset($result['data'])) {
                // If API returns wrapper {success, data}, extract data
                if (isset($result['data']['success']) && $result['data']['success'] && isset($result['data']['data'])) {
                    return $result['data']['data'];
                }
                return $result['data'];
            }

            // Try to parse "response" if 'data' is empty
            if (!empty($result['response'])) {
                $decoded = json_decode($result['response'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (isset($decoded['success']) && $decoded['success'] && isset($decoded['data'])) {
                        return $decoded['data'];
                    }
                    return $decoded;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning("Failed to request block from node $nodeId: " . $e->getMessage());
        }

        return null;
    }
    



}

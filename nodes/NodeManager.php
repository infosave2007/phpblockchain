<?php
declare(strict_types=1);

namespace Blockchain\Nodes;

use Blockchain\Core\Contracts\NodeInterface;
use Blockchain\Core\Contracts\BlockInterface;
use Blockchain\Core\Contracts\TransactionInterface;
use Blockchain\Core\Network\MultiCurl;
use Blockchain\Core\Events\EventDispatcher;
use Psr\Log\LoggerInterface;

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
    public function addNode(NodeInterface $node): void
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
     * Broadcast block to all nodes
     */
    public function broadcastBlock(BlockInterface $block): array
    {
        $activeNodes = $this->getActiveNodes();
        
        if (empty($activeNodes)) {
            $this->logger->warning('No active nodes available for block broadcast');
            return [];
        }

        $requests = [];
        foreach ($activeNodes as $nodeId => $node) {
            $requests[$nodeId] = [
                'url' => $node->getApiUrl() . '/block',
                'method' => 'POST',
                'data' => json_encode($block->toArray()),
                'headers' => [
                    'Content-Type: application/json',
                    'User-Agent: ' . $this->getUserAgent(),
                    'X-Node-Id: ' . $this->getCurrentNodeId()
                ]
            ];
        }

        $results = $this->multiCurl->executeRequests($requests);
        $this->processNodeResponses($results, 'block_broadcast');

        $this->logger->info('Block broadcast completed', [
            'block_hash' => $block->getHash(),
            'nodes_count' => count($activeNodes),
            'successful' => count(array_filter($results, fn($r) => $r['success']))
        ]);

        return $results;
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
     * Translate неисправных узлов
     */
    public function getFailedNodes(): array
    {
        return array_filter($this->nodes, function($nodeId) {
            $status = $this->nodeStatuses[$nodeId] ?? null;
            return $status && $status['status'] === 'failed';
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Обработка ответов от узлов
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
     * Обработка успешного ответа от узла
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
     * Обработка неудачного ответа от узла
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

        // Банним узел если слишком много ошибок
        if (($this->nodeStatuses[$nodeId]['errors'] ?? 0) >= 10) {
            $this->banNode($nodeId, 'Too many errors');
        }
    }

    /**
     * Поиск узла с самой длинной цепью
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
     * Professional block synchronization with selected node
     */
    private function synchronizeBlocks(array $longestChainNode): array
    {
        $nodeId = $longestChainNode['nodeId'];
        $node = $this->nodes[$nodeId];
        $targetHeight = $longestChainNode['height'];
        $currentHeight = $this->blockchain->getBlockHeight();
        
        try {
            $blocksSynced = 0;
            
            // Sync blocks one by one from current height + 1 to target height
            for ($height = $currentHeight + 1; $height <= $targetHeight; $height++) {
                $blockData = $this->requestBlockFromNode($nodeId, $height);
                
                if (!$blockData || !$this->validateBlockData($blockData)) {
                    throw new Exception("Invalid block data received for height $height");
                }
                
                // Create block object and add to blockchain
                $block = $this->createBlockFromData($blockData);
                
                if ($this->blockchain->addBlock($block)) {
                    $blocksSynced++;
                } else {
                    throw new Exception("Failed to add block at height $height");
                }
                
                // Add small delay to prevent overwhelming the network
                usleep(10000); // 10ms delay
            }
            
            return [
                'success' => true,
                'blocks_synced' => $blocksSynced,
                'target_height' => $targetHeight,
                'current_height' => $this->blockchain->getBlockHeight()
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'blocks_synced' => $blocksSynced ?? 0,
                'target_height' => $targetHeight
            ];
        }
    }

    /**
     * Обновление статуса узла
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
     * Бан узла
     */
    public function banNode(string $nodeId, string $reason): void
    {
        $this->bannedNodes[] = $nodeId;
        
        $this->logger->warning("Node banned: {$nodeId}", [
            'reason' => $reason
        ]);
    }

    /**
     * Разбан узла
     */
    public function unbanNode(string $nodeId): void
    {
        $this->bannedNodes = array_filter($this->bannedNodes, fn($id) => $id !== $nodeId);
        
        $this->logger->info("Node unbanned: {$nodeId}");
    }

    /**
     * Добавление доверенного узла
     */
    public function addTrustedNode(string $nodeId): void
    {
        if (!in_array($nodeId, $this->trustedNodes)) {
            $this->trustedNodes[] = $nodeId;
        }
    }

    /**
     * Translate текущего ID узла
     */
    private function getCurrentNodeId(): string
    {
        // Здесь должна быть логика получения ID текущего узла
        return 'current_node_' . gethostname();
    }

    /**
     * Translate статистики сети
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
     * Request specific block from node
     */
    private function requestBlockFromNode(string $nodeId, int $height): ?array
    {
        if (!isset($this->nodes[$nodeId])) {
            return null;
        }
        
        $node = $this->nodes[$nodeId];
        $url = $node['url'] . '/api/block/' . $height;
        
        try {
            $response = $this->httpClient->get($url, [
                'timeout' => 10,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'BlockchainNode/1.0'
                ]
            ]);
            
            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody()->getContents(), true);
            }
            
        } catch (Exception $e) {
            $this->logger->warning("Failed to request block from node $nodeId: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Validate received block data
     */
    private function validateBlockData(array $blockData): bool
    {
        // Check required fields
        $requiredFields = ['index', 'previousHash', 'timestamp', 'transactions', 'hash', 'nonce'];
        
        foreach ($requiredFields as $field) {
            if (!isset($blockData[$field])) {
                return false;
            }
        }
        
        // Validate hash format
        if (!preg_match('/^[a-fA-F0-9]{64}$/', $blockData['hash'])) {
            return false;
        }
        
        // Validate timestamp
        if (!is_numeric($blockData['timestamp']) || $blockData['timestamp'] <= 0) {
            return false;
        }
        
        // Validate transactions format
        if (!is_array($blockData['transactions'])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Create block object from received data
     */
    private function createBlockFromData(array $blockData): \Blockchain\Core\Blockchain\Block
    {
        $transactions = [];
        
        // Convert transaction data to transaction objects
        foreach ($blockData['transactions'] as $txData) {
            $transaction = new \Blockchain\Core\Transaction\Transaction(
                $txData['from'],
                $txData['to'],
                $txData['amount'],
                $txData['fee'] ?? 0,
                $txData['data'] ?? ''
            );
            
            if (isset($txData['signature'])) {
                $transaction->setSignature($txData['signature']);
            }
            
            $transactions[] = $transaction;
        }
        
        // Create block
        $block = new \Blockchain\Core\Blockchain\Block(
            $blockData['index'],
            $blockData['previousHash'],
            $blockData['timestamp'],
            $transactions,
            $blockData['nonce']
        );
        
        // Set metadata if available
        if (isset($blockData['metadata'])) {
            foreach ($blockData['metadata'] as $key => $value) {
                $block->addMetadata($key, $value);
            }
        }
        
        return $block;
    }
}

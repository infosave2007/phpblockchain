<?php
declare(strict_types=1);

namespace Blockchain\Core\Sync;

use Blockchain\Core\Database\DatabaseManager;
use Blockchain\Core\Events\EventDispatcher;
use Blockchain\Core\Logging\LoggerInterface;
use PDO;
use Exception;

/**
 * Enhanced Event-Driven Synchronization Manager
 * Improves real-time synchronization without changing database structure
 */
class EnhancedEventSync
{
    private PDO $pdo;
    private EventDispatcher $eventDispatcher;
    private LoggerInterface $logger;
    private array $config;
    private array $pendingEvents = [];
    private array $eventSubscribers = [];
    private float $lastHeartbeat;
    private string $nodeId;
    
    // Event priority constants
    const PRIORITY_CRITICAL = 1;    // Block events, fork detection
    const PRIORITY_HIGH = 2;        // Transaction broadcasts
    const PRIORITY_NORMAL = 3;      // Mempool updates
    const PRIORITY_LOW = 4;         // Status updates, metrics
    
    // Performance tracking
    private array $performanceMetrics = [];
    private int $processedEvents = 0;
    private float $lastMetricsReset;
    
    // Enhanced reliability features
    private array $failedNodes = [];
    private array $eventQueue = [];
    private int $maxRetryAttempts = 3;
    private float $backoffMultiplier = 1.5;

    public function __construct(
        EventDispatcher $eventDispatcher,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->pdo = DatabaseManager::getConnection();
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->lastHeartbeat = microtime(true);
        $this->lastMetricsReset = microtime(true);
        $this->nodeId = $this->getCurrentNodeId();
        
        $this->initializeEventListeners();
        $this->initializePerformanceMonitoring();
    }

    /**
     * Get default configuration with enhanced settings
     */
    private function getDefaultConfig(): array
    {
        return [
            'heartbeat_interval' => 30.0,       // seconds
            'event_batch_size' => 50,           // increased batch size for better throughput
            'max_retry_attempts' => 5,          // more retry attempts for reliability
            'propagation_timeout' => 3.0,       // reduced timeout for faster failure detection
            'enable_compression' => true,        // compress event payloads
            'priority_processing' => true,       // process high priority first
            'dead_node_threshold' => 90,        // faster dead node detection
            'sync_cascade_delay' => 0.5,        // faster cascade for better performance
            'performance_monitoring' => true,    // enable performance tracking
            'adaptive_timeouts' => true,         // adjust timeouts based on node performance
            'connection_pooling' => true,        // reuse connections for better performance
            'event_deduplication' => true,      // prevent duplicate event processing
            'metrics_reset_interval' => 300,    // reset performance metrics every 5 minutes
            'max_concurrent_connections' => 10,  // limit concurrent network connections
        ];
    }

    /**
     * Initialize core event listeners
     */
    private function initializeEventListeners(): void
    {
        // Block events
        $this->eventDispatcher->on('block.mined', function($data) {
            $this->handleBlockMined($data);
        });
        
        $this->eventDispatcher->on('block.received', function($data) {
            $this->logger->info('Block received event: ' . json_encode($data));
            // Process block received logic
        });
        
        $this->eventDispatcher->on('fork.detected', function($data) {
            $this->logger->warning('Fork detected: ' . json_encode($data));
            // Process fork detection logic
        });
        
        // Transaction events  
        $this->eventDispatcher->on('transaction.broadcast', function($data) {
            $this->logger->info('Transaction broadcast event: ' . json_encode($data));
            // Process transaction broadcast logic
        });
        
        $this->eventDispatcher->on('mempool.updated', function($data) {
            $this->logger->info('Mempool updated event: ' . json_encode($data));
            // Process mempool update logic
        });
        
        // Network events
        $this->eventDispatcher->on('node.joined', function($data) {
            $this->logger->info('Node joined event: ' . json_encode($data));
            // Process node joined logic
        });
        
        $this->eventDispatcher->on('node.left', function($data) {
            $this->logger->info('Node left event: ' . json_encode($data));
            // Process node left logic
        });
        
        $this->eventDispatcher->on('heartbeat.missed', function($data) {
            $this->logger->warning('Heartbeat missed event: ' . json_encode($data));
            // Process heartbeat missed logic
        });
    }
    
    /**
     * Initialize performance monitoring system
     */
    private function initializePerformanceMonitoring(): void
    {
        if (!$this->config['performance_monitoring']) {
            return;
        }
        
        $this->performanceMetrics = [
            'events_processed' => 0,
            'events_failed' => 0,
            'network_requests' => 0,
            'network_failures' => 0,
            'average_response_time' => 0.0,
            'total_response_time' => 0.0,
            'peak_memory_usage' => 0,
            'start_time' => microtime(true)
        ];
    }
    
    /**
     * Enhanced event processing with performance tracking
     */
    public function processEvent(string $eventType, array $data, int $priority = self::PRIORITY_NORMAL): bool
    {
        $startTime = microtime(true);
        $this->processedEvents++;
        
        try {
            // Check if event should be deduplicated
            if ($this->config['event_deduplication'] && $this->isDuplicateEvent($eventType, $data)) {
                $this->logger->debug("Skipping duplicate event: {$eventType}");
                return true;
            }
            
            // Add to event queue for batch processing
            $event = [
                'type' => $eventType,
                'data' => $data,
                'priority' => $priority,
                'timestamp' => microtime(true),
                'retry_count' => 0,
                'id' => $this->generateEventId($eventType, $data)
            ];
            
            $this->eventQueue[] = $event;
            
            // Process queue if batch size reached or high priority event
            if (count($this->eventQueue) >= $this->config['event_batch_size'] || $priority <= self::PRIORITY_HIGH) {
                return $this->processEventQueue();
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->logger->error("Event processing failed for {$eventType}: " . $e->getMessage());
            $this->recordPerformanceMetric('events_failed', 1);
            return false;
        } finally {
            $processingTime = microtime(true) - $startTime;
            $this->recordPerformanceMetric('total_response_time', $processingTime);
            $this->updateMemoryUsage();
        }
    }

    /**
     * Process event queue with priority ordering and batch efficiency
     */
    private function processEventQueue(): bool
    {
        if (empty($this->eventQueue)) {
            return true;
        }
        
        // Sort events by priority (lower number = higher priority)
        if ($this->config['priority_processing']) {
            usort($this->eventQueue, function($a, $b) {
                if ($a['priority'] === $b['priority']) {
                    return $a['timestamp'] <=> $b['timestamp']; // FIFO for same priority
                }
                return $a['priority'] <=> $b['priority'];
            });
        }
        
        $processedCount = 0;
        $failedCount = 0;
        
        foreach ($this->eventQueue as $index => $event) {
            try {
                $success = $this->processIndividualEvent($event);
                
                if ($success) {
                    $processedCount++;
                    unset($this->eventQueue[$index]);
                } else {
                    $failedCount++;
                    // Increment retry count for failed events
                    $this->eventQueue[$index]['retry_count']++;
                    
                    // Remove events that exceeded retry limit
                    if ($this->eventQueue[$index]['retry_count'] >= $this->config['max_retry_attempts']) {
                        $this->logger->warning("Event {$event['type']} exceeded retry limit, removing from queue");
                        unset($this->eventQueue[$index]);
                    }
                }
                
            } catch (Exception $e) {
                $this->logger->error("Failed to process event {$event['type']}: " . $e->getMessage());
                $failedCount++;
                unset($this->eventQueue[$index]);
            }
        }
        
        // Reindex array after unsetting elements
        $this->eventQueue = array_values($this->eventQueue);
        
        $this->recordPerformanceMetric('events_processed', $processedCount);
        $this->recordPerformanceMetric('events_failed', $failedCount);
        
        $this->logger->info("Processed event batch: {$processedCount} success, {$failedCount} failed, " . count($this->eventQueue) . " remaining");
        
        return $processedCount > 0;
    }
    
    /**
     * Process individual event with enhanced error handling
     */
    private function processIndividualEvent(array $event): bool
    {
        switch ($event['type']) {
            case 'block.added':
            case 'block.mined':
                return $this->propagateBlockEvent($event);
                
            case 'transaction.propagate':
            case 'transaction.broadcast':
                return $this->propagateTransactionEvent($event);
                
            case 'fork.detected':
                return $this->propagateForkEvent($event);
                
            case 'heartbeat':
                return $this->propagateHeartbeatEvent($event);
                
            case 'sync.gap_detected':
                return $this->handleSyncGap($event);
                
            default:
                $this->logger->warning("Unknown event type: {$event['type']}");
                return false;
        }
    }

    /**
     * Enhanced real-time block event handling
     */
    public function handleBlockMined(array $data): void
    {
        $block = $data['block'] ?? null;
        if (!$block) {
            $this->logger->warning('Block mined event received without block data');
            return;
        }

        $blockHash = method_exists($block, 'getHash') ? $block->getHash() : ($data['hash'] ?? '');
        $blockHeight = method_exists($block, 'getIndex') ? $block->getIndex() : ($data['height'] ?? 0);

        $this->logger->info("Processing block mined event: {$blockHash} at height {$blockHeight}");

        // Create high-priority network event
        $event = [
            'type' => 'block.added',
            'priority' => self::PRIORITY_CRITICAL,
            'data' => [
                'block_hash' => $blockHash,
                'block_height' => $blockHeight,
                'timestamp' => time(),
                'miner_node' => $this->nodeId,
                'transactions_count' => method_exists($block, 'getTransactionCount') ? 
                    $block->getTransactionCount() : count($data['transactions'] ?? [])
            ],
            'cascade_level' => 0
        ];

        // Immediate propagation to network with cascade strategy
        $this->propagateEventWithCascade($event);
        
        // Update local sync state
        $this->updateSyncState('last_block_mined', $blockHeight);
        
        // Trigger dependent events
        $this->triggerEvent('sync.height_updated', [
            'height' => $blockHeight,
            'trigger' => 'block_mined'
        ]);
    }

    /**
     * Handle received block from network
     */
    public function handleBlockReceived(array $data): void
    {
        $blockHash = $data['block_hash'] ?? '';
        $blockHeight = $data['block_height'] ?? 0;
        $sourceNode = $data['source_node'] ?? 'unknown';

        $this->logger->info("Processing block received event: {$blockHash} from {$sourceNode}");

        // Check if we need to sync
        $localHeight = $this->getCurrentBlockHeight();
        
        if ($blockHeight > $localHeight + 1) {
            // Gap detected - trigger catch-up sync
            $this->triggerEvent('sync.gap_detected', [
                'local_height' => $localHeight,
                'received_height' => $blockHeight,
                'source_node' => $sourceNode,
                'gap_size' => $blockHeight - $localHeight
            ]);
        } elseif ($blockHeight == $localHeight + 1) {
            // Next sequential block - normal processing
            $this->triggerEvent('block.process', [
                'block_hash' => $blockHash,
                'block_height' => $blockHeight,
                'source_node' => $sourceNode
            ]);
        }
        
        // Update node reliability metrics
        $this->updateNodeMetrics($sourceNode, 'blocks_received', 1);
    }

    /**
     * Handle fork detection with enhanced resolution
     */
    public function handleForkDetected(array $data): void
    {
        $forkHeight = $data['fork_height'] ?? 0;
        $localHash = $data['local_hash'] ?? '';
        $remoteHash = $data['remote_hash'] ?? '';
        $sourceNode = $data['source_node'] ?? '';

        $this->logger->warning("Fork detected at height {$forkHeight}: local={$localHash}, remote={$remoteHash}");

        // Broadcast fork detection to network for consensus
        $forkEvent = [
            'type' => 'fork.detected',
            'priority' => self::PRIORITY_CRITICAL,
            'data' => [
                'fork_height' => $forkHeight,
                'local_hash' => $localHash,
                'remote_hash' => $remoteHash,
                'detector_node' => $this->nodeId,
                'timestamp' => time()
            ]
        ];

        $this->propagateEventToNetwork($forkEvent);
        
        // Start enhanced fork resolution process
        $this->initiateForkResolution($forkHeight, $sourceNode);
    }

    /**
     * Enhanced transaction broadcast handling
     */
    public function handleTransactionBroadcast(array $data): void
    {
        $txHash = $data['tx_hash'] ?? '';
        $sourceNode = $data['source_node'] ?? $this->nodeId;
        
        // Check for duplicates using existing broadcast_tracking
        if ($this->isDuplicateEvent($txHash)) {
            return;
        }

        $this->logger->debug("Processing transaction broadcast: {$txHash}");

        // Create transaction propagation event
        $event = [
            'type' => 'transaction.propagate',
            'priority' => self::PRIORITY_HIGH,
            'data' => array_merge($data, [
                'propagation_timestamp' => microtime(true),
                'source_node' => $sourceNode
            ])
        ];

        // Smart propagation - avoid sending back to source
        $this->propagateEventToNetwork($event, [$sourceNode]);
        
        // Update transaction metrics
        $this->updateNodeMetrics($sourceNode, 'transactions_received', 1);
    }
    
    /**
     * Enhanced block event propagation
     */
    private function propagateBlockEvent(array $event): bool
    {
        try {
            $this->propagateEventWithCascade($event);
            
            // Update local sync state for block events
            if (isset($event['data']['block_height'])) {
                $this->updateSyncState('last_block_processed', $event['data']['block_height']);
            }
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Block event propagation failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Enhanced transaction event propagation
     */
    private function propagateTransactionEvent(array $event): bool
    {
        try {
            // For transactions, use direct propagation (no cascade)
            $this->propagateEventToNetwork($event);
            
            // Update transaction metrics
            $this->recordPerformanceMetric('transactions_propagated', 1);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Transaction event propagation failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Enhanced fork event propagation
     */
    private function propagateForkEvent(array $event): bool
    {
        try {
            // Fork events require immediate propagation to all nodes
            $this->propagateEventToNetwork($event);
            
            // Record fork event for analysis
            $this->recordForkEvent($event['data']);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Fork event propagation failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Enhanced heartbeat event propagation
     */
    private function propagateHeartbeatEvent(array $event): bool
    {
        try {
            // Heartbeats use lightweight propagation
            $selectedNodes = $this->selectHeartbeatNodes();
            $this->propagateToSelectedNodes($event, $selectedNodes);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Heartbeat event propagation failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle sync gap detection with immediate response
     */
    private function handleSyncGap(array $event): bool
    {
        try {
            $gapSize = $event['data']['gap_size'] ?? 0;
            
            if ($gapSize <= 10) {
                // Small gap - trigger immediate sync
                $this->triggerImmediateSync($event['data']);
            } elseif ($gapSize <= 100) {
                // Medium gap - trigger batch sync
                $this->triggerBatchSync($event['data']);
            } else {
                // Large gap - schedule full sync
                $this->scheduleFullSync($event['data']);
            }
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Sync gap handling failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Propagate event with intelligent cascade strategy
     */
    private function propagateEventWithCascade(array $event): void
    {
        $cascadeLevel = $event['cascade_level'] ?? 0;
        $maxCascade = $this->config['max_cascade_levels'] ?? 3;
        
        if ($cascadeLevel >= $maxCascade) {
            return; // Prevent infinite cascading
        }

        // Get optimal node distribution for this cascade level
        $targetNodes = $this->selectCascadeNodes($cascadeLevel);
        
        foreach ($targetNodes as $nodeGroup) {
            // Add slight delay between cascade levels for network efficiency
            if ($cascadeLevel > 0) {
                usleep((int)($this->config['sync_cascade_delay'] * 1000000));
            }
            
            $cascadeEvent = $event;
            $cascadeEvent['cascade_level'] = $cascadeLevel + 1;
            
            $this->propagateToNodeGroup($cascadeEvent, $nodeGroup);
        }
    }

    /**
     * Select nodes for cascade propagation based on network topology
     */
    private function selectCascadeNodes(int $cascadeLevel): array
    {
        $nodes = $this->getActiveNodes();
        $nodeGroups = [];
        
        // Group nodes by reliability and distance
        $highReliability = [];
        $mediumReliability = [];
        $lowReliability = [];
        
        foreach ($nodes as $node) {
            $reliability = $this->getNodeReliability($node['url']);
            
            if ($reliability >= 0.9) {
                $highReliability[] = $node;
            } elseif ($reliability >= 0.7) {
                $mediumReliability[] = $node;
            } else {
                $lowReliability[] = $node;
            }
        }
        
        // Cascade strategy: high reliability first, then medium, then low
        switch ($cascadeLevel) {
            case 0:
                // Initial propagation - send to most reliable nodes
                $nodeGroups[] = array_slice($highReliability, 0, 3);
                break;
            case 1:
                // Second level - medium reliability nodes
                $nodeGroups[] = array_slice($mediumReliability, 0, 5);
                break;
            case 2:
                // Final level - remaining nodes
                $nodeGroups[] = array_merge(
                    array_slice($highReliability, 3),
                    array_slice($mediumReliability, 5),
                    $lowReliability
                );
                break;
        }
        
        return array_filter($nodeGroups);
    }

    /**
     * Enhanced event propagation with adaptive timeout and connection pooling
     */
    private function propagateEventToNetwork(array $event, array $excludeNodes = []): void
    {
        $nodes = $this->getActiveNodes();
        $excludeUrls = array_map('strtolower', $excludeNodes);
        
        // Filter out failed nodes and excluded nodes
        $availableNodes = array_filter($nodes, function($node) use ($excludeUrls) {
            $nodeUrl = strtolower($node['url'] ?? '');
            return !in_array($nodeUrl, $excludeUrls) && !$this->isNodeTemporarilyFailed($nodeUrl);
        });
        
        if (empty($availableNodes)) {
            $this->logger->warning('No available nodes for event propagation');
            return;
        }
        
        $payload = $this->prepareEventPayload($event);
        $startTime = microtime(true);
        
        // Limit concurrent connections
        $maxConnections = min(count($availableNodes), $this->config['max_concurrent_connections']);
        $nodeChunks = array_chunk($availableNodes, $maxConnections);
        
        $totalSuccess = 0;
        $totalFailures = 0;
        
        foreach ($nodeChunks as $nodeChunk) {
            $results = $this->processConcurrentRequests($nodeChunk, $payload, $event);
            $totalSuccess += $results['success'];
            $totalFailures += $results['failures'];
        }
        
        $duration = microtime(true) - $startTime;
        
        $this->recordPerformanceMetric('network_requests', count($availableNodes));
        $this->recordPerformanceMetric('network_failures', $totalFailures);
        $this->recordPerformanceMetric('total_response_time', $duration);
        
        $this->logger->info("Event propagated in {$duration}s: {$totalSuccess} success, {$totalFailures} failed");
    }
    
    /**
     * Process concurrent network requests with improved error handling
     */
    private function processConcurrentRequests(array $nodes, string $payload, array $event): array
    {
        $multiHandle = curl_multi_init();
        $curlHandles = [];
        
        // Set up curl handles
        foreach ($nodes as $node) {
            $nodeUrl = $node['url'] ?? '';
            $timeout = $this->getAdaptiveTimeout($nodeUrl);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => rtrim($nodeUrl, '/') . '/api/sync/events.php',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min($timeout, 2),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'BlockchainSync/1.0',
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Event-Priority: ' . ($event['priority'] ?? self::PRIORITY_NORMAL),
                    'X-Source-Node: ' . $this->nodeId,
                    'X-Event-Type: ' . ($event['type'] ?? 'unknown'),
                    'X-Event-ID: ' . ($event['id'] ?? '')
                ]
            ]);
            
            curl_multi_add_handle($multiHandle, $ch);
            $curlHandles[$nodeUrl] = $ch;
        }
        
        // Execute all requests
        $running = null;
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($running) {
                curl_multi_select($multiHandle, 0.1); // 100ms timeout for select
            }
        } while ($running > 0 && $status === CURLM_OK);
        
        // Process results
        $successCount = 0;
        $failureCount = 0;
        
        foreach ($curlHandles as $nodeUrl => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responseTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $error = curl_error($ch);
            
            if ($httpCode >= 200 && $httpCode < 300 && empty($error)) {
                $successCount++;
                $this->recordNodeResponseTime($nodeUrl, $responseTime);
                $this->updateNodeMetrics($nodeUrl, 'events_sent_success', 1);
                $this->removeNodeFromFailedList($nodeUrl);
            } else {
                $failureCount++;
                $this->addNodeToFailedList($nodeUrl);
                $this->updateNodeMetrics($nodeUrl, 'events_sent_failed', 1);
                $this->logger->debug("Request failed to {$nodeUrl}: HTTP {$httpCode}, Error: {$error}");
            }
            
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($multiHandle);
        
        return ['success' => $successCount, 'failures' => $failureCount];
    }

    /**
     * Prepare event payload with optional compression
     */
    private function prepareEventPayload(array $event): string
    {
        $payload = json_encode($event, JSON_UNESCAPED_SLASHES);
        
        if ($this->config['enable_compression'] && function_exists('gzencode')) {
            $compressed = gzencode($payload, 6);
            if ($compressed !== false && strlen($compressed) < strlen($payload)) {
                return base64_encode($compressed);
            }
        }
        
        return $payload;
    }

    /**
     * Enhanced heartbeat system for network monitoring
     */
    public function processHeartbeat(): void
    {
        $now = microtime(true);
        
        if (($now - $this->lastHeartbeat) < $this->config['heartbeat_interval']) {
            return;
        }
        
        $this->lastHeartbeat = $now;
        
        // Send heartbeat to network
        $heartbeatEvent = [
            'type' => 'heartbeat',
            'priority' => self::PRIORITY_LOW,
            'data' => [
                'node_id' => $this->nodeId,
                'timestamp' => $now,
                'block_height' => $this->getCurrentBlockHeight(),
                'mempool_size' => $this->getMempoolSize(),
                'uptime' => $this->getNodeUptime()
            ]
        ];
        
        $this->propagateEventToNetwork($heartbeatEvent);
        
        // Check for dead nodes
        $this->checkNodeHealth();
    }

    /**
     * Check node health and update status
     */
    private function checkNodeHealth(): void
    {
        $deadThreshold = time() - $this->config['dead_node_threshold'];
        
        try {
            // Update node status based on last heartbeat
            $stmt = $this->pdo->prepare("
                UPDATE nodes 
                SET status = 'inactive' 
                WHERE status = 'active' 
                AND last_seen < FROM_UNIXTIME(?)
            ");
            $stmt->execute([$deadThreshold]);
            
            $deadNodes = $stmt->rowCount();
            if ($deadNodes > 0) {
                $this->logger->info("Marked {$deadNodes} nodes as inactive due to missed heartbeats");
            }
            
        } catch (Exception $e) {
            $this->logger->error('Failed to update node health: ' . $e->getMessage());
        }
    }

    /**
     * Initiate enhanced fork resolution
     */
    private function initiateForkResolution(int $forkHeight, string $sourceNode): void
    {
        $this->logger->info("Initiating fork resolution at height {$forkHeight}");
        
        // Gather fork information from multiple nodes
        $forkData = $this->gatherForkConsensus($forkHeight);
        
        // Determine canonical chain based on consensus
        $canonicalChain = $this->determineCanonicalChain($forkData);
        
        if ($canonicalChain) {
            // Trigger chain reorganization if needed
            $this->triggerEvent('chain.reorganize', [
                'fork_height' => $forkHeight,
                'canonical_hash' => $canonicalChain['hash'],
                'canonical_node' => $canonicalChain['source']
            ]);
        }
    }

    /**
     * Gather fork consensus from network nodes
     */
    private function gatherForkConsensus(int $forkHeight): array
    {
        $nodes = $this->getActiveNodes();
        $consensus = [];
        
        foreach ($nodes as $node) {
            $nodeUrl = $node['url'] ?? '';
            
            try {
                // Query block at fork height from each node
                $blockData = $this->queryBlockFromNode($nodeUrl, $forkHeight);
                
                if ($blockData) {
                    $blockHash = $blockData['hash'] ?? '';
                    if ($blockHash) {
                        if (!isset($consensus[$blockHash])) {
                            $consensus[$blockHash] = [
                                'hash' => $blockHash,
                                'supporters' => [],
                                'count' => 0
                            ];
                        }
                        
                        $consensus[$blockHash]['supporters'][] = $nodeUrl;
                        $consensus[$blockHash]['count']++;
                    }
                }
                
            } catch (Exception $e) {
                $this->logger->warning("Failed to query block from {$nodeUrl}: " . $e->getMessage());
            }
        }
        
        return $consensus;
    }

    /**
     * Determine canonical chain based on consensus
     */
    private function determineCanonicalChain(array $forkData): ?array
    {
        if (empty($forkData)) {
            return null;
        }
        
        // Sort by supporter count (descending)
        uasort($forkData, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });
        
        $topChoice = reset($forkData);
        $totalNodes = count($this->getActiveNodes());
        $requiredConsensus = ceil($totalNodes * 0.51); // 51% consensus
        
        if ($topChoice['count'] >= $requiredConsensus) {
            return [
                'hash' => $topChoice['hash'],
                'source' => $topChoice['supporters'][0] ?? '',
                'consensus_ratio' => $topChoice['count'] / $totalNodes
            ];
        }
        
        return null;
    }

    /**
     * Get current block height
     */
    private function getCurrentBlockHeight(): int
    {
        try {
            $stmt = $this->pdo->query("SELECT MAX(height) FROM blocks");
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get mempool size
     */
    private function getMempoolSize(): int
    {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM mempool WHERE status = 'pending'");
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get active nodes from database
     */
    private function getActiveNodes(): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT node_id, ip_address, port, metadata, reputation_score 
                FROM nodes 
                WHERE status = 'active' 
                AND reputation_score >= 50
                ORDER BY reputation_score DESC
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $nodes = [];
            foreach ($rows as $row) {
                $metadata = json_decode($row['metadata'] ?? '{}', true);
                $protocol = $metadata['protocol'] ?? 'https';
                $domain = $metadata['domain'] ?? $row['ip_address'];
                $port = $row['port'] ?? ($protocol === 'https' ? 443 : 80);
                
                $url = sprintf('%s://%s%s', 
                    $protocol, 
                    $domain, 
                    ($port != 443 && $port != 80) ? ':' . $port : ''
                );
                
                $nodes[] = [
                    'id' => $row['node_id'],
                    'url' => $url,
                    'reputation' => $row['reputation_score']
                ];
            }
            
            return $nodes;
        } catch (Exception $e) {
            $this->logger->error('Failed to get active nodes: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Update node metrics using existing broadcast_stats table
     */
    private function updateNodeMetrics(string $nodeUrl, string $metric, $value): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO broadcast_stats (node_id, metric_type, metric_value) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([md5($nodeUrl), $metric, $value]);
        } catch (Exception $e) {
            // Ignore metrics errors to prevent sync disruption
        }
    }

    /**
     * Check if event is duplicate using broadcast_tracking
     */
    private function isDuplicateEvent(string $eventId): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM broadcast_tracking 
                WHERE transaction_hash = ? AND expires_at > NOW()
            ");
            $stmt->execute([hash('sha256', $eventId)]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Trigger event through dispatcher
     */
    private function triggerEvent(string $eventType, array $data): void
    {
        $this->eventDispatcher->dispatch($eventType, $data);
    }

    /**
     * Update sync state using config table
     */
    private function updateSyncState(string $key, $value): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO config (key_name, value, updated_at) 
                VALUES (?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()
            ");
            $stmt->execute(['sync.' . $key, (string)$value]);
        } catch (Exception $e) {
            $this->logger->error('Failed to update sync state: ' . $e->getMessage());
        }
    }

    /**
     * Get node reliability score
     */
    private function getNodeReliability(string $nodeUrl): float
    {
        try {
            $nodeId = md5($nodeUrl);
            $stmt = $this->pdo->prepare("
                SELECT 
                    SUM(CASE WHEN metric_type = 'events_sent_success' THEN metric_value ELSE 0 END) as success,
                    SUM(CASE WHEN metric_type = 'events_sent_failed' THEN metric_value ELSE 0 END) as failed
                FROM broadcast_stats 
                WHERE node_id = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute([$nodeId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $success = (float)($stats['success'] ?? 0);
            $failed = (float)($stats['failed'] ?? 0);
            $total = $success + $failed;
            
            return $total > 0 ? ($success / $total) : 0.8; // Default reliability
        } catch (Exception $e) {
            return 0.5; // Low default on error
        }
    }

    /**
     * Get current node ID
     */
    private function getCurrentNodeId(): string
    {
        return gethostname() . '_' . substr(md5(gethostname() . time()), 0, 8);
    }

    /**
     * Get node uptime
     */
    private function getNodeUptime(): int
    {
        return (int)shell_exec('cat /proc/uptime | cut -d" " -f1') ?: time();
    }

    /**
     * Query block from specific node
     */
    private function queryBlockFromNode(string $nodeUrl, int $height): ?array
    {
        $url = rtrim($nodeUrl, '/') . "/api/explorer/index.php?action=get_block&block_id={$height}";
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'method' => 'GET'
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }
        
        $data = json_decode($response, true);
        return $data['data'] ?? null;
    }

    /**
     * Propagate event to specific node group
     */
    private function propagateToNodeGroup(array $event, array $nodeGroup): void
    {
        if (empty($nodeGroup)) {
            return;
        }
        
        $payload = $this->prepareEventPayload($event);
        
        foreach ($nodeGroup as $node) {
            $nodeUrl = $node['url'] ?? '';
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => rtrim($nodeUrl, '/') . '/api/sync/events',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Event-Priority: ' . ($event['priority'] ?? self::PRIORITY_NORMAL),
                    'X-Source-Node: ' . $this->nodeId
                ]
            ]);
            
            curl_exec($ch);
            curl_close($ch);
        }
    }
    
    /**
     * Record performance metrics with automatic reset
     */
    private function recordPerformanceMetric(string $metric, $value): void
    {
        if (!$this->config['performance_monitoring']) {
            return;
        }
        
        // Reset metrics if interval passed
        $now = microtime(true);
        if (($now - $this->lastMetricsReset) >= $this->config['metrics_reset_interval']) {
            $this->resetPerformanceMetrics();
        }
        
        if (isset($this->performanceMetrics[$metric])) {
            if (is_numeric($this->performanceMetrics[$metric])) {
                $this->performanceMetrics[$metric] += $value;
            }
        }
        
        // Update average response time
        if ($metric === 'total_response_time' && $this->performanceMetrics['network_requests'] > 0) {
            $this->performanceMetrics['average_response_time'] = 
                $this->performanceMetrics['total_response_time'] / $this->performanceMetrics['network_requests'];
        }
    }
    
    /**
     * Reset performance metrics
     */
    private function resetPerformanceMetrics(): void
    {
        $this->performanceMetrics = [
            'events_processed' => 0,
            'events_failed' => 0,
            'network_requests' => 0,
            'network_failures' => 0,
            'average_response_time' => 0.0,
            'total_response_time' => 0.0,
            'peak_memory_usage' => 0,
            'start_time' => microtime(true)
        ];
        $this->lastMetricsReset = microtime(true);
    }
    
    /**
     * Update memory usage tracking
     */
    private function updateMemoryUsage(): void
    {
        $currentMemory = memory_get_usage(true);
        if ($currentMemory > $this->performanceMetrics['peak_memory_usage']) {
            $this->performanceMetrics['peak_memory_usage'] = $currentMemory;
        }
    }
    
    /**
     * Generate unique event ID for deduplication
     */
    private function generateEventId(string $eventType, array $data): string
    {
        $hashData = $eventType . serialize($data) . $this->nodeId;
        return hash('sha256', $hashData);
    }
    
    /**
     * Get adaptive timeout based on node performance
     */
    private function getAdaptiveTimeout(string $nodeUrl): int
    {
        if (!$this->config['adaptive_timeouts']) {
            return (int)$this->config['propagation_timeout'];
        }
        
        $baseTimeout = (int)$this->config['propagation_timeout'];
        $reliability = $this->getNodeReliability($nodeUrl);
        
        // Adjust timeout based on reliability (0.5x to 2.0x base timeout)
        $multiplier = 1.0 + (0.5 - $reliability);
        $adaptiveTimeout = (int)($baseTimeout * $multiplier);
        
        return max(1, min(10, $adaptiveTimeout)); // Clamp between 1-10 seconds
    }
    
    /**
     * Record node response time for adaptive timeouts
     */
    private function recordNodeResponseTime(string $nodeUrl, float $responseTime): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO broadcast_stats (node_id, metric_type, metric_value, created_at) 
                VALUES (?, 'response_time', ?, NOW())
            ");
            $stmt->execute([md5($nodeUrl), $responseTime]);
        } catch (Exception $e) {
            // Ignore metrics errors
        }
    }
    
    /**
     * Check if node is temporarily failed
     */
    private function isNodeTemporarilyFailed(string $nodeUrl): bool
    {
        if (!isset($this->failedNodes[$nodeUrl])) {
            return false;
        }
        
        $failureData = $this->failedNodes[$nodeUrl];
        $backoffTime = $failureData['count'] * $this->config['propagation_timeout'] * $this->backoffMultiplier;
        
        return (microtime(true) - $failureData['last_failure']) < $backoffTime;
    }
    
    /**
     * Add node to temporary failure list
     */
    private function addNodeToFailedList(string $nodeUrl): void
    {
        if (!isset($this->failedNodes[$nodeUrl])) {
            $this->failedNodes[$nodeUrl] = ['count' => 0, 'last_failure' => 0];
        }
        
        $this->failedNodes[$nodeUrl]['count']++;
        $this->failedNodes[$nodeUrl]['last_failure'] = microtime(true);
        
        // Remove old failure records (older than 1 hour)
        $this->cleanupFailedNodes();
    }
    
    /**
     * Remove node from failure list on success
     */
    private function removeNodeFromFailedList(string $nodeUrl): void
    {
        unset($this->failedNodes[$nodeUrl]);
    }
    
    /**
     * Clean up old failure records
     */
    private function cleanupFailedNodes(): void
    {
        $cutoffTime = microtime(true) - 3600; // 1 hour
        
        foreach ($this->failedNodes as $nodeUrl => $data) {
            if ($data['last_failure'] < $cutoffTime) {
                unset($this->failedNodes[$nodeUrl]);
            }
        }
    }
    
    /**
     * Select nodes for heartbeat propagation
     */
    private function selectHeartbeatNodes(): array
    {
        $allNodes = $this->getActiveNodes();
        
        // For heartbeats, select a subset of high-reputation nodes
        usort($allNodes, function($a, $b) {
            return ($b['reputation'] ?? 0) <=> ($a['reputation'] ?? 0);
        });
        
        return array_slice($allNodes, 0, min(5, count($allNodes)));
    }
    
    /**
     * Propagate to selected nodes
     */
    private function propagateToSelectedNodes(array $event, array $nodes): void
    {
        if (empty($nodes)) {
            return;
        }
        
        $payload = $this->prepareEventPayload($event);
        
        foreach ($nodes as $node) {
            $nodeUrl = $node['url'] ?? '';
            
            // Use lightweight single requests for heartbeats
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n" .
                               "X-Event-Priority: {$event['priority']}\r\n" .
                               "X-Source-Node: {$this->nodeId}\r\n",
                    'content' => $payload,
                    'timeout' => 2
                ]
            ]);
            
            @file_get_contents(rtrim($nodeUrl, '/') . '/api/sync/events.php', false, $context);
        }
    }
    
    /**
     * Trigger immediate sync for small gaps
     */
    private function triggerImmediateSync(array $gapData): void
    {
        $this->logger->info('Triggering immediate sync for gap: ' . json_encode($gapData));
        
        // Use existing sync mechanism but trigger immediately
        $sourceNode = $gapData['source_node'] ?? '';
        if ($sourceNode) {
            $this->triggerGapSync($this->pdo, $gapData['local_height'], $gapData['received_height'], $sourceNode);
        }
    }
    
    /**
     * Trigger batch sync for medium gaps
     */
    private function triggerBatchSync(array $gapData): void
    {
        $this->logger->info('Triggering batch sync for gap: ' . json_encode($gapData));
        
        // Schedule batch sync in background
        $this->scheduleSync($gapData, 'batch');
    }
    
    /**
     * Schedule sync operation
     */
    private function scheduleSync(array $gapData, string $syncType): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO sync_monitoring (
                    event_type, local_height, network_max_height, height_difference,
                    error_message, metadata, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                'sync_scheduled',
                $gapData['local_height'] ?? 0,
                $gapData['received_height'] ?? 0,
                $gapData['gap_size'] ?? 0,
                "Scheduled {$syncType} sync",
                json_encode(array_merge($gapData, ['sync_type' => $syncType]))
            ]);
            
        } catch (Exception $e) {
            $this->logger->error('Failed to schedule sync: ' . $e->getMessage());
        }
    }
    
    /**
     * Get performance metrics summary
     */
    public function getPerformanceMetrics(): array
    {
        if (!$this->config['performance_monitoring']) {
            return [];
        }
        
        $metrics = $this->performanceMetrics;
        $metrics['uptime'] = microtime(true) - $metrics['start_time'];
        $metrics['events_per_second'] = $metrics['uptime'] > 0 ? 
            $metrics['events_processed'] / $metrics['uptime'] : 0;
        
        return $metrics;
    }
    
    /**
     * Force process pending events
     */
    public function flushEventQueue(): bool
    {
        return $this->processEventQueue();
    }
    
    /**
     * Get queue status
     */
    public function getQueueStatus(): array
    {
        return [
            'pending_events' => count($this->eventQueue),
            'failed_nodes' => count($this->failedNodes),
            'processed_events' => $this->processedEvents
        ];
    }
    
    /**
     * Record fork event for monitoring
     */
    private function recordForkEvent(array $forkData): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO sync_monitoring (
                    event_type, local_height, network_max_height, error_message, metadata, created_at
                ) VALUES ('fork_detected', ?, ?, 'Fork detected in network', ?, NOW())
            ");
            
            $stmt->execute([
                $forkData['fork_height'] ?? 0,
                $forkData['fork_height'] ?? 0,
                json_encode($forkData)
            ]);
            
        } catch (Exception $e) {
            $this->logger->error('Failed to record fork event: ' . $e->getMessage());
        }
    }
    
    /**
     * Schedule full sync for large gaps
     */
    private function scheduleFullSync(array $gapData): void
    {
        $this->logger->info('Scheduling full sync for large gap: ' . json_encode($gapData));
        
        $this->scheduleSync($gapData, 'full');
    }
    
    /**
     * Trigger gap sync using existing network sync mechanism
     */
    private function triggerGapSync(PDO $pdo, int $localHeight, int $remoteHeight, string $sourceNode): void
    {
        $this->logger->info("Triggering gap sync from {$localHeight} to {$remoteHeight}");
        
        try {
            // Record sync request in monitoring
            $stmt = $pdo->prepare("
                INSERT INTO sync_monitoring (
                    event_type, local_height, network_max_height, height_difference,
                    error_message, metadata, created_at
                ) VALUES ('sync_triggered', ?, ?, ?, 'Gap sync triggered', ?, NOW())
            ");
            
            $stmt->execute([
                $localHeight,
                $remoteHeight,
                $remoteHeight - $localHeight,
                json_encode(['source_node' => $sourceNode, 'trigger' => 'gap_detected'])
            ]);
            
            // Try to trigger existing sync mechanism
            $syncScript = __DIR__ . '/../../network_sync.php';
            if (file_exists($syncScript)) {
                $command = "php {$syncScript} > /dev/null 2>&1 &";
                shell_exec($command);
                $this->logger->info('Gap sync process started in background');
            }
            
        } catch (Exception $e) {
            $this->logger->error('Failed to trigger gap sync: ' . $e->getMessage());
        }
    }
}
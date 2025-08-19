<?php
declare(strict_types=1);

namespace Blockchain\Core\Sync;

use Blockchain\Core\Database\DatabaseManager;
use Blockchain\Core\Events\EventDispatcher;
use Blockchain\Core\Logging\LoggerInterface;
use PDO;
use Exception;

/**
 * Batch Event Processor for High-Volume Synchronization
 * Приоритет 1: Пакетная обработка событий для высоких нагрузок
 */
class BatchEventProcessor
{
    private PDO $pdo;
    private EventDispatcher $eventDispatcher;
    private LoggerInterface $logger;
    private array $config;
    private array $eventQueue = [];
    private int $batchSize;
    private int $maxQueueSize;
    private float $flushInterval;
    private float $lastFlush;

    public function __construct(
        EventDispatcher $eventDispatcher,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->pdo = DatabaseManager::getConnection();
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
        $this->config = $config;
        
        // Configuration with defaults
        $this->batchSize = (int)($config['batch_size'] ?? 50);
        $this->maxQueueSize = (int)($config['max_queue_size'] ?? 1000);
        $this->flushInterval = (float)($config['flush_interval'] ?? 5.0); // seconds
        $this->lastFlush = microtime(true);
        
        $this->initializeEventQueue();
    }

    /**
     * Initialize event queue table
     */
    private function initializeEventQueue(): void
    {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS event_queue (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    event_type VARCHAR(50) NOT NULL,
                    event_data JSON NOT NULL,
                    event_id VARCHAR(64) NOT NULL,
                    source_node VARCHAR(64) NOT NULL,
                    priority TINYINT NOT NULL DEFAULT 5,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    processed_at TIMESTAMP NULL,
                    retry_count TINYINT DEFAULT 0,
                    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
                    INDEX idx_event_queue_status (status),
                    INDEX idx_event_queue_priority (priority),
                    INDEX idx_event_queue_created (created_at),
                    INDEX idx_event_queue_type (event_type),
                    UNIQUE KEY unique_event_id (event_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            $this->logger->error('Failed to initialize event queue table: ' . $e->getMessage());
        }
    }

    /**
     * Add event to processing queue
     */
    public function queueEvent(string $eventType, array $eventData, string $sourceNode, int $priority = 5): bool
    {
        $result = $this->addEvent($eventType, $eventData, $sourceNode, $priority);
        return $result['batched'] ?? false;
    }
    
    /**
     * Add event to processing queue (alias for queueEvent)
     */
    public function addEvent(string $eventType, array $eventData, string $sourceNode, int $priority = 5): array
    {
        try {
            // Check queue size limit
            if (count($this->eventQueue) >= $this->maxQueueSize) {
                $this->logger->warning('Event queue full, forcing flush');
                $this->flushQueue();
            }

            // Generate unique event ID
            $eventId = hash('sha256', $eventType . '|' . json_encode($eventData) . '|' . $sourceNode . '|' . time());

            // Check for duplicates using broadcast_tracking
            if ($this->isDuplicateEvent($eventId)) {
                $this->logger->debug("Duplicate event ignored: $eventId");
                return ['batched' => false, 'reason' => 'duplicate'];
            }

            // Add to in-memory queue
            $this->eventQueue[] = [
                'event_type' => $eventType,
                'event_data' => $eventData,
                'event_id' => $eventId,
                'source_node' => $sourceNode,
                'priority' => $priority,
                'created_at' => date('Y-m-d H:i:s')
            ];

            // Check if we should flush
            if (count($this->eventQueue) >= $this->batchSize || 
                (microtime(true) - $this->lastFlush) >= $this->flushInterval) {
                $this->flushQueue();
            }

            return ['batched' => true, 'batch_id' => $eventId];
        } catch (Exception $e) {
            $this->logger->error('Failed to queue event: ' . $e->getMessage());
            return ['batched' => false, 'error' => $e->getMessage()];
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
            $stmt->execute([$eventId]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            $this->logger->error('Error checking duplicate event: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Flush queue to database and process events
     */
    public function flushQueue(): int
    {
        if (empty($this->eventQueue)) {
            return 0;
        }

        try {
            $this->pdo->beginTransaction();

            // Insert events to database queue
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO event_queue 
                (event_type, event_data, event_id, source_node, priority, created_at) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $insertedCount = 0;
            foreach ($this->eventQueue as $event) {
                $stmt->execute([
                    $event['event_type'],
                    json_encode($event['event_data']),
                    $event['event_id'],
                    $event['source_node'],
                    $event['priority'],
                    $event['created_at']
                ]);
                if ($stmt->rowCount() > 0) {
                    $insertedCount++;
                }
            }

            $this->pdo->commit();

            // Process events in batches
            $processedCount = $this->processBatch();

            // Clear in-memory queue
            $this->eventQueue = [];
            $this->lastFlush = microtime(true);

            $this->logger->info("Flushed queue: $insertedCount inserted, $processedCount processed");
            return $processedCount;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->logger->error('Failed to flush queue: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get batch processor statistics
     */
    public function getStats(): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_events,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_events,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_events,
                    AVG(CASE WHEN processed_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, created_at, processed_at) ELSE NULL END) as avg_processing_time
                FROM event_queue 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'queue_size' => count($this->eventQueue),
                'total_events' => (int)($stats['total_events'] ?? 0),
                'completed_events' => (int)($stats['completed_events'] ?? 0),
                'failed_events' => (int)($stats['failed_events'] ?? 0),
                'avg_processing_time' => (float)($stats['avg_processing_time'] ?? 0),
                'batch_size' => $this->batchSize,
                'max_queue_size' => $this->maxQueueSize
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to get batch processor stats: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get health status
     */
    public function getHealthStatus(): array
    {
        $stats = $this->getStats();
        $successRate = $stats['total_events'] > 0 ? ($stats['completed_events'] / $stats['total_events']) * 100 : 100;
        
        return [
            'status' => $successRate > 90 ? 'healthy' : ($successRate > 70 ? 'warning' : 'error'),
            'efficiency' => $successRate,
            'queue_health' => count($this->eventQueue) < $this->maxQueueSize * 0.8 ? 'healthy' : 'warning'
        ];
    }

    /**
     * Process batch of events from database
     */
    public function processBatch(): int
    {
        try {
            // Get pending events ordered by priority and creation time
            $stmt = $this->pdo->prepare("
                SELECT * FROM event_queue 
                WHERE status = 'pending' AND retry_count < 3
                ORDER BY priority ASC, created_at ASC 
                LIMIT ?
            ");
            $stmt->execute([$this->batchSize]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($events)) {
                return 0;
            }

            $processedCount = 0;
            foreach ($events as $event) {
                if ($this->processEvent($event)) {
                    $processedCount++;
                }
            }

            return $processedCount;

        } catch (Exception $e) {
            $this->logger->error('Failed to process batch: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Process single event
     */
    private function processEvent(array $event): bool
    {
        try {
            // Mark as processing
            $this->updateEventStatus($event['id'], 'processing');

            $eventData = json_decode($event['event_data'], true);
            $success = false;

            // Process based on event type
            switch ($event['event_type']) {
                case 'block.added':
                    $success = $this->processBlockEvent($eventData, $event['source_node']);
                    break;
                case 'transaction.new':
                    $success = $this->processTransactionEvent($eventData, $event['source_node']);
                    break;
                case 'mempool.update':
                    $success = $this->processMempoolEvent($eventData, $event['source_node']);
                    break;
                case 'wallet.created':
                    $success = $this->processWalletEvent($eventData, $event['source_node']);
                    break;
                case 'sync.request':
                    $success = $this->processSyncRequest($eventData, $event['source_node']);
                    break;
                default:
                    $this->logger->warning("Unknown event type: {$event['event_type']}");
                    $success = false;
            }

            // Update status
            if ($success) {
                $this->updateEventStatus($event['id'], 'completed');
                
                // Record in broadcast_tracking to prevent duplicates
                $this->recordBroadcastTracking($event['event_id'], $event['source_node']);
            } else {
                $this->handleEventFailure($event);
            }

            return $success;

        } catch (Exception $e) {
            $this->logger->error("Failed to process event {$event['id']}: " . $e->getMessage());
            $this->handleEventFailure($event);
            return false;
        }
    }

    /**
     * Process block event
     */
    private function processBlockEvent(array $eventData, string $sourceNode): bool
    {
        try {
            // Dispatch to existing block sync logic
            $this->eventDispatcher->dispatch('sync.block.received', [
                'block_hash' => $eventData['block_hash'] ?? '',
                'block_height' => $eventData['block_height'] ?? 0,
                'source_node' => $sourceNode
            ]);
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to process block event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process transaction event
     */
    private function processTransactionEvent(array $eventData, string $sourceNode): bool
    {
        try {
            // Dispatch to existing transaction sync logic
            $this->eventDispatcher->dispatch('sync.transaction.received', [
                'transaction' => $eventData,
                'source_node' => $sourceNode
            ]);
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to process transaction event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process mempool event
     */
    private function processMempoolEvent(array $eventData, string $sourceNode): bool
    {
        try {
            // Dispatch to mempool sync
            $this->eventDispatcher->dispatch('sync.mempool.update', [
                'mempool_data' => $eventData,
                'source_node' => $sourceNode
            ]);
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to process mempool event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process wallet event
     */
    private function processWalletEvent(array $eventData, string $sourceNode): bool
    {
        try {
            // Dispatch to wallet sync
            $this->eventDispatcher->dispatch('sync.wallet.created', [
                'wallet_data' => $eventData,
                'source_node' => $sourceNode
            ]);
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to process wallet event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process sync request
     */
    private function processSyncRequest(array $eventData, string $sourceNode): bool
    {
        try {
            // Trigger targeted sync
            $this->eventDispatcher->dispatch('sync.request.received', [
                'sync_type' => $eventData['sync_type'] ?? 'full',
                'target_height' => $eventData['target_height'] ?? null,
                'source_node' => $sourceNode
            ]);
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to process sync request: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update event status
     */
    private function updateEventStatus(int $eventId, string $status): void
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE event_queue 
                SET status = ?, processed_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$status, $eventId]);
        } catch (Exception $e) {
            $this->logger->error("Failed to update event status: " . $e->getMessage());
        }
    }

    /**
     * Handle event failure
     */
    private function handleEventFailure(array $event): void
    {
        try {
            $newRetryCount = $event['retry_count'] + 1;
            $maxRetries = 3;

            if ($newRetryCount >= $maxRetries) {
                // Mark as failed
                $stmt = $this->pdo->prepare("
                    UPDATE event_queue 
                    SET status = 'failed', retry_count = ?, processed_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$newRetryCount, $event['id']]);
            } else {
                // Increment retry count and reset to pending
                $stmt = $this->pdo->prepare("
                    UPDATE event_queue 
                    SET status = 'pending', retry_count = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$newRetryCount, $event['id']]);
            }
        } catch (Exception $e) {
            $this->logger->error("Failed to handle event failure: " . $e->getMessage());
        }
    }

    /**
     * Record in broadcast_tracking for deduplication
     */
    private function recordBroadcastTracking(string $eventId, string $sourceNode): void
    {
        try {
            $currentNodeId = $this->getCurrentNodeId();
            $stmt = $this->pdo->prepare("
                INSERT INTO broadcast_tracking 
                (transaction_hash, source_node_id, current_node_id, hop_count, broadcast_path, created_at, expires_at)
                VALUES (?, ?, ?, 0, ?, NOW(), DATE_ADD(NOW(), INTERVAL 2 HOUR))
                ON DUPLICATE KEY UPDATE
                    created_at = NOW(),
                    expires_at = DATE_ADD(NOW(), INTERVAL 2 HOUR)
            ");
            $stmt->execute([$eventId, $sourceNode, $currentNodeId, $sourceNode]);
        } catch (Exception $e) {
            $this->logger->error('Failed to record broadcast tracking: ' . $e->getMessage());
        }
    }

    /**
     * Get current node ID
     */
    private function getCurrentNodeId(): string
    {
        return gethostname() . '_' . substr(md5(gethostname()), 0, 8);
    }

    /**
     * Clean up old events
     */
    public function cleanupOldEvents(): int
    {
        try {
            // Remove completed events older than 24 hours
            $stmt = $this->pdo->prepare("
                DELETE FROM event_queue 
                WHERE status = 'completed' AND processed_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            $deletedCount = $stmt->rowCount();

            // Remove failed events older than 7 days
            $stmt = $this->pdo->prepare("
                DELETE FROM event_queue 
                WHERE status = 'failed' AND processed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute();
            $deletedCount += $stmt->rowCount();

            if ($deletedCount > 0) {
                $this->logger->info("Cleaned up $deletedCount old events");
            }

            return $deletedCount;
        } catch (Exception $e) {
            $this->logger->error('Failed to cleanup old events: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get queue statistics
     */
    public function getQueueStats(): array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    status,
                    COUNT(*) as count,
                    AVG(retry_count) as avg_retries
                FROM event_queue 
                GROUP BY status
            ");
            $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [
                'in_memory_queue' => count($this->eventQueue),
                'database_queue' => []
            ];

            foreach ($stats as $stat) {
                $result['database_queue'][$stat['status']] = [
                    'count' => (int)$stat['count'],
                    'avg_retries' => round((float)$stat['avg_retries'], 2)
                ];
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Failed to get queue stats: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Force flush queue on destruction
     */
    public function __destruct()
    {
        if (!empty($this->eventQueue)) {
            $this->flushQueue();
        }
    }
}

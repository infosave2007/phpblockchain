<?php
declare(strict_types=1);

namespace Blockchain\Core\Sync;

use Blockchain\Core\Database\DatabaseManager;
use Blockchain\Core\Logging\LoggerInterface;
use PDO;
use Exception;

/**
 * Sync Rate Limiter with Queue Management
 * Priority 1: Synchronization queue with speed limiting
 */
class SyncRateLimiter
{
    private PDO $pdo;
    private LoggerInterface $logger;
    private array $config;
    private array $rateLimits;
    private array $lastRequestTimes = [];

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->pdo = DatabaseManager::getConnection();
        $this->logger = $logger;
        $this->config = $config;

        // Default rate limits (requests per minute)
        $this->rateLimits = [
            'block_sync' => (int)($config['block_sync_rpm'] ?? 60),      // 1 per second
            'transaction_sync' => (int)($config['tx_sync_rpm'] ?? 300),   // 5 per second
            'mempool_sync' => (int)($config['mempool_sync_rpm'] ?? 30),   // 0.5 per second
            'wallet_sync' => (int)($config['wallet_sync_rpm'] ?? 120),    // 2 per second
            'full_sync' => (int)($config['full_sync_rpm'] ?? 6),          // 0.1 per second
        ];
    }


    /**
     * Check if request is allowed under rate limit
     */
    public function isAllowed(string $syncType, string $nodeId = 'default'): bool
    {
        return $this->allowRequest($nodeId, $syncType);
    }
    
    /**
     * Get rate limiter statistics
     */
    public function getStats(): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_requests,
                    SUM(CASE WHEN blocked_until IS NOT NULL THEN 1 ELSE 0 END) as blocked_requests,
                    AVG(request_count) as avg_requests_per_window
                FROM sync_rate_limits 
                WHERE window_start > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'total_requests' => (int)($stats['total_requests'] ?? 0),
                'blocked_requests' => (int)($stats['blocked_requests'] ?? 0),
                'avg_requests_per_window' => (float)($stats['avg_requests_per_window'] ?? 0),
                'usage_percent' => $this->calculateUsagePercent(),
                'rate_limits' => $this->rateLimits
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to get rate limiter stats: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get health status
     */
    public function getHealthStatus(): array
    {
        $stats = $this->getStats();
        $blockRate = $stats['total_requests'] > 0 ? ($stats['blocked_requests'] / $stats['total_requests']) * 100 : 0;
        
        return [
            'status' => $blockRate < 10 ? 'healthy' : ($blockRate < 30 ? 'warning' : 'error'),
            'usage_percent' => $stats['usage_percent'] ?? 0,
            'block_rate' => $blockRate
        ];
    }

    /**
     * Calculate usage percentage
     */
    private function calculateUsagePercent(): float
    {
        // Simple calculation based on current rate limits
        $totalLimit = array_sum($this->rateLimits);
        $currentUsage = 0;
        
        foreach ($this->rateLimits as $type => $limit) {
            $currentUsage += $limit * 0.5; // Assume 50% usage
        }
        
        return $totalLimit > 0 ? ($currentUsage / $totalLimit) * 100 : 0;
    }

    /**
     * Check if request is allowed under rate limit (alias for isAllowed)
     */
    public function allowRequest(string $nodeId, string $syncType): bool
    {
        try {
            $limitKey = $syncType . '_' . $nodeId;
            $limit = $this->rateLimits[$syncType] ?? 60;
            $windowSize = 60; // 1 minute window
            
            $now = time();
            
            // Get current rate limit state
            $stmt = $this->pdo->prepare("
                SELECT request_count, window_start, blocked_until 
                FROM sync_rate_limits 
                WHERE id = ?
            ");
            $stmt->execute([$limitKey]);
            $state = $stmt->fetch(PDO::FETCH_ASSOC);

            // Check if currently blocked
            if ($state && $state['blocked_until'] && strtotime($state['blocked_until']) > $now) {
                $this->logger->debug("Request blocked due to rate limit: $limitKey");
                return false;
            }

            // Initialize or reset window
            if (!$state || ($now - strtotime($state['window_start'])) >= $windowSize) {
                $this->resetRateLimit($limitKey, $now);
                return true;
            }

            // Check if within limit
            if ($state['request_count'] >= $limit) {
                // Block for remaining window time
                $blockUntil = strtotime($state['window_start']) + $windowSize;
                $this->blockRateLimit($limitKey, $blockUntil);
                
                $this->logger->warning("Rate limit exceeded for $limitKey: {$state['request_count']}/$limit");
                return false;
            }

            // Increment counter
            $this->incrementRateLimit($limitKey);
            return true;

        } catch (Exception $e) {
            $this->logger->error('Rate limit check failed: ' . $e->getMessage());
            return true; // Allow on error to prevent system lockup
        }
    }

    /**
     * Add sync request to priority queue
     */
    public function queueSyncRequest(string $syncType, array $data, string $nodeId, int $priority = 5, int $delaySeconds = 0): bool
    {
        try {
            $scheduledAt = date('Y-m-d H:i:s', time() + $delaySeconds);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO sync_queue_priority 
                (sync_type, node_id, priority, data, scheduled_at) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $syncType,
                $nodeId,
                $priority,
                json_encode($data),
                $scheduledAt
            ]);

            if ($result) {
                $this->logger->info("Queued sync request: $syncType for $nodeId (priority: $priority, delay: {$delaySeconds}s)");
            }

            return $result;

        } catch (Exception $e) {
            $this->logger->error('Failed to queue sync request: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Process next sync request from queue
     */
    public function processNextRequest(): ?array
    {
        try {
            $this->pdo->beginTransaction();

            // Get next request (highest priority, oldest first)
            $stmt = $this->pdo->prepare("
                SELECT * FROM sync_queue_priority 
                WHERE status = 'pending' 
                AND scheduled_at <= NOW() 
                AND retry_count < 3
                ORDER BY priority ASC, created_at ASC 
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute();
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                $this->pdo->rollBack();
                return null;
            }

            // Check rate limit
            if (!$this->isAllowed($request['sync_type'], $request['node_id'])) {
                // Reschedule for later
                $newScheduledAt = date('Y-m-d H:i:s', time() + 60); // 1 minute delay
                $this->pdo->prepare("
                    UPDATE sync_queue_priority 
                    SET scheduled_at = ? 
                    WHERE id = ?
                ")->execute([$newScheduledAt, $request['id']]);
                
                $this->pdo->commit();
                return null;
            }

            // Mark as processing
            $stmt = $this->pdo->prepare("
                UPDATE sync_queue_priority 
                SET status = 'processing', processed_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$request['id']]);

            $this->pdo->commit();

            // Prepare request data
            $requestData = [
                'id' => $request['id'],
                'sync_type' => $request['sync_type'],
                'node_id' => $request['node_id'],
                'priority' => $request['priority'],
                'data' => json_decode($request['data'], true),
                'retry_count' => $request['retry_count']
            ];

            return $requestData;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->logger->error('Failed to process next request: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Mark sync request as completed
     */
    public function markCompleted(int $requestId): void
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE sync_queue_priority 
                SET status = 'completed', processed_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$requestId]);
        } catch (Exception $e) {
            $this->logger->error('Failed to mark request completed: ' . $e->getMessage());
        }
    }

    /**
     * Mark sync request as failed
     */
    public function markFailed(int $requestId, bool $retry = true): void
    {
        try {
            if ($retry) {
                // Increment retry count and reschedule
                $delay = pow(2, 3) * 60; // Exponential backoff: 8 minutes
                $newScheduledAt = date('Y-m-d H:i:s', time() + $delay);
                
                $stmt = $this->pdo->prepare("
                    UPDATE sync_queue_priority 
                    SET status = 'pending', 
                        retry_count = retry_count + 1,
                        scheduled_at = ?
                    WHERE id = ?
                ");
                $stmt->execute([$newScheduledAt, $requestId]);
            } else {
                // Mark as permanently failed
                $stmt = $this->pdo->prepare("
                    UPDATE sync_queue_priority 
                    SET status = 'failed', processed_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$requestId]);
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to mark request failed: ' . $e->getMessage());
        }
    }

    /**
     * Reset rate limit window
     */
    private function resetRateLimit(string $limitKey, int $now): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO sync_rate_limits (id, request_count, window_start, last_request) 
                VALUES (?, 1, ?, ?)
                ON DUPLICATE KEY UPDATE
                    request_count = 1,
                    window_start = VALUES(window_start),
                    last_request = VALUES(last_request),
                    blocked_until = NULL
            ");
            $stmt->execute([$limitKey, date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now)]);
        } catch (Exception $e) {
            $this->logger->error('Failed to reset rate limit: ' . $e->getMessage());
        }
    }

    /**
     * Increment rate limit counter
     */
    private function incrementRateLimit(string $limitKey): void
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE sync_rate_limits 
                SET request_count = request_count + 1, last_request = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$limitKey]);
        } catch (Exception $e) {
            $this->logger->error('Failed to increment rate limit: ' . $e->getMessage());
        }
    }

    /**
     * Block rate limit until specified time
     */
    private function blockRateLimit(string $limitKey, int $blockUntil): void
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE sync_rate_limits 
                SET blocked_until = ? 
                WHERE id = ?
            ");
            $stmt->execute([date('Y-m-d H:i:s', $blockUntil), $limitKey]);
        } catch (Exception $e) {
            $this->logger->error('Failed to block rate limit: ' . $e->getMessage());
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
                    sync_type,
                    status,
                    COUNT(*) as count,
                    AVG(priority) as avg_priority,
                    MIN(created_at) as oldest_request
                FROM sync_queue_priority 
                GROUP BY sync_type, status
            ");
            $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($stats as $stat) {
                $result[$stat['sync_type']][$stat['status']] = [
                    'count' => (int)$stat['count'],
                    'avg_priority' => round((float)$stat['avg_priority'], 1),
                    'oldest_request' => $stat['oldest_request']
                ];
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Failed to get queue stats: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get rate limit status
     */
    public function getRateLimitStatus(): array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    id,
                    request_count,
                    window_start,
                    blocked_until
                FROM sync_rate_limits 
                WHERE window_start > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
            ");
            $limits = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($limits as $limit) {
                $result[$limit['id']] = [
                    'request_count' => (int)$limit['request_count'],
                    'window_start' => $limit['window_start'],
                    'blocked_until' => $limit['blocked_until'],
                    'is_blocked' => $limit['blocked_until'] && strtotime($limit['blocked_until']) > time()
                ];
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Failed to get rate limit status: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean up old records
     */
    public function cleanup(): int
    {
        try {
            $deletedCount = 0;

            // Clean up old rate limit records (older than 2 hours)
            $stmt = $this->pdo->prepare("
                DELETE FROM sync_rate_limits 
                WHERE window_start < DATE_SUB(NOW(), INTERVAL 2 HOUR)
            ");
            $stmt->execute();
            $deletedCount += $stmt->rowCount();

            // Clean up completed queue items (older than 24 hours)
            $stmt = $this->pdo->prepare("
                DELETE FROM sync_queue_priority 
                WHERE status = 'completed' AND processed_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            $deletedCount += $stmt->rowCount();

            // Clean up failed queue items (older than 7 days)
            $stmt = $this->pdo->prepare("
                DELETE FROM sync_queue_priority 
                WHERE status = 'failed' AND processed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute();
            $deletedCount += $stmt->rowCount();

            if ($deletedCount > 0) {
                $this->logger->info("Cleaned up $deletedCount rate limiter records");
            }

            return $deletedCount;
        } catch (Exception $e) {
            $this->logger->error('Failed to cleanup rate limiter: ' . $e->getMessage());
            return 0;
        }
    }
}

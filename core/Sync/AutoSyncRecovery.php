<?php
declare(strict_types=1);

namespace Blockchain\Core\Sync;

use Blockchain\Core\Database\DatabaseManager;
use Blockchain\Core\Events\EventDispatcher;
use Blockchain\Core\Logging\LoggerInterface;
use PDO;
use Exception;

/**
 * Automatic Sync Recovery System
 * Приоритет 1: Автоматическое восстановление синхронизации
 */
class AutoSyncRecovery
{
    private PDO $pdo;
    private EventDispatcher $eventDispatcher;
    private LoggerInterface $logger;
    private SyncRateLimiter $rateLimiter;
    private array $config;

    public function __construct(
        EventDispatcher $eventDispatcher,
        LoggerInterface $logger,
        SyncRateLimiter $rateLimiter,
        array $config = []
    ) {
        $this->pdo = DatabaseManager::getConnection();
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
        $this->rateLimiter = $rateLimiter;
        $this->config = $config;

        $this->initializeSyncMonitoring();
    }

    /**
     * Initialize sync monitoring tables
     */
    private function initializeSyncMonitoring(): void
    {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sync_health_monitor (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    node_id VARCHAR(64) NOT NULL,
                    metric_type VARCHAR(50) NOT NULL,
                    metric_value DECIMAL(15,4) NOT NULL,
                    threshold_warning DECIMAL(15,4) NOT NULL,
                    threshold_critical DECIMAL(15,4) NOT NULL,
                    status ENUM('healthy', 'warning', 'critical') NOT NULL,
                    last_check TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    recovery_triggered BOOLEAN DEFAULT FALSE,
                    recovery_count INT DEFAULT 0,
                    INDEX idx_sync_health_node (node_id),
                    INDEX idx_sync_health_metric (metric_type),
                    INDEX idx_sync_health_status (status),
                    INDEX idx_sync_health_check (last_check),
                    UNIQUE KEY unique_node_metric (node_id, metric_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sync_recovery_log (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    node_id VARCHAR(64) NOT NULL,
                    recovery_type VARCHAR(50) NOT NULL,
                    trigger_reason TEXT NOT NULL,
                    recovery_actions JSON NOT NULL,
                    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    completed_at TIMESTAMP NULL,
                    success BOOLEAN DEFAULT FALSE,
                    error_message TEXT NULL,
                    metrics_before JSON NULL,
                    metrics_after JSON NULL,
                    INDEX idx_sync_recovery_node (node_id),
                    INDEX idx_sync_recovery_type (recovery_type),
                    INDEX idx_sync_recovery_started (started_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

        } catch (Exception $e) {
            $this->logger->error('Failed to initialize sync monitoring tables: ' . $e->getMessage());
        }
    }

    /**
     * Check if recovery should be triggered
     */
    private function shouldTriggerRecovery(string $sourceNode, string $eventType): bool
    {
        // Simple logic: trigger recovery if we have multiple recent errors
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM sync_recovery_log 
                WHERE node_id = ? AND started_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute([$sourceNode]);
            $recentErrors = $stmt->fetchColumn();
            
            return $recentErrors >= 3; // Trigger after 3 errors in 1 hour
        } catch (Exception $e) {
            $this->logger->error("Failed to check recovery trigger: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle sync error and trigger recovery if needed
     */
    public function handleError(string $eventType, string $sourceNode, string $errorMessage): void
    {
        try {
            $this->logger->warning("Sync error detected", [
                'event_type' => $eventType,
                'source_node' => $sourceNode,
                'error' => $errorMessage
            ]);

            // Log error for monitoring
            $stmt = $this->pdo->prepare("
                INSERT INTO sync_recovery_log 
                (node_id, recovery_type, trigger_reason, recovery_actions, started_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            
            $recoveryActions = [
                'action' => 'error_handling',
                'event_type' => $eventType,
                'timestamp' => time()
            ];
            
            $stmt->execute([
                $sourceNode,
                'error_recovery',
                $errorMessage,
                json_encode($recoveryActions)
            ]);

            // Trigger recovery if needed
            if ($this->shouldTriggerRecovery($sourceNode, $eventType)) {
                $this->triggerRecovery(['error_recovery' => ['error' => $errorMessage, 'node' => $sourceNode]]);
            }

        } catch (Exception $e) {
            $this->logger->error("Failed to handle sync error: " . $e->getMessage());
        }
    }

    /**
     * Get auto recovery statistics
     */
    public function getStats(): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_recoveries,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_recoveries,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_recoveries,
                    AVG(CASE WHEN completed_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, started_at, completed_at) ELSE NULL END) as avg_recovery_time
                FROM sync_recovery_log 
                WHERE started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'total_recoveries' => (int)($stats['total_recoveries'] ?? 0),
                'successful_recoveries' => (int)($stats['successful_recoveries'] ?? 0),
                'failed_recoveries' => (int)($stats['failed_recoveries'] ?? 0),
                'avg_recovery_time' => (float)($stats['avg_recovery_time'] ?? 0),
                'success_rate' => $stats['total_recoveries'] > 0 ? ($stats['successful_recoveries'] / $stats['total_recoveries']) * 100 : 100
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to get auto recovery stats: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get health status
     */
    public function getHealthStatus(): array
    {
        $stats = $this->getStats();
        $successRate = $stats['success_rate'] ?? 100;
        
        return [
            'status' => $successRate > 80 ? 'healthy' : ($successRate > 50 ? 'warning' : 'error'),
            'recovery_efficiency' => $successRate,
            'avg_recovery_time' => $stats['avg_recovery_time'] ?? 0
        ];
    }

    /**
     * Monitor sync health and trigger recovery if needed
     */
    public function monitorAndRecover(): array
    {
        $results = [];
        
        try {
            // Check various sync health metrics
            $results['height_sync'] = $this->checkHeightSync();
            $results['transaction_sync'] = $this->checkTransactionSync();
            $results['mempool_sync'] = $this->checkMempoolSync();
            $results['node_connectivity'] = $this->checkNodeConnectivity();
            $results['event_processing'] = $this->checkEventProcessing();

            // Trigger recovery for critical issues
            $criticalIssues = array_filter($results, fn($r) => $r['status'] === 'critical');
            if (!empty($criticalIssues)) {
                $results['recovery'] = $this->triggerRecovery($criticalIssues);
            }

            return $results;

        } catch (Exception $e) {
            $this->logger->error('Sync monitoring failed: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Check blockchain height synchronization
     */
    private function checkHeightSync(): array
    {
        try {
            $currentNodeId = $this->getCurrentNodeId();
            
            // Get network heights from active nodes
            $networkHeights = $this->getNetworkHeights();
            if (empty($networkHeights)) {
                return [
                    'status' => 'warning',
                    'message' => 'No network nodes available for height comparison',
                    'metric' => 0,
                    'threshold_warning' => 10,
                    'threshold_critical' => 50
                ];
            }

            $maxHeight = max($networkHeights);
            $localHeight = $this->getLocalHeight();
            $heightDelta = $maxHeight - $localHeight;

            // Thresholds
            $warningThreshold = (int)($this->config['height_warning_threshold'] ?? 10);
            $criticalThreshold = (int)($this->config['height_critical_threshold'] ?? 50);

            $status = 'healthy';
            if ($heightDelta >= $criticalThreshold) {
                $status = 'critical';
            } elseif ($heightDelta >= $warningThreshold) {
                $status = 'warning';
            }

            // Update monitoring
            $this->updateHealthMetric($currentNodeId, 'height_delta', $heightDelta, $warningThreshold, $criticalThreshold, $status);

            return [
                'status' => $status,
                'message' => "Height delta: $heightDelta blocks (local: $localHeight, max: $maxHeight)",
                'metric' => $heightDelta,
                'local_height' => $localHeight,
                'network_max_height' => $maxHeight,
                'threshold_warning' => $warningThreshold,
                'threshold_critical' => $criticalThreshold
            ];

        } catch (Exception $e) {
            $this->logger->error('Height sync check failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Check transaction synchronization
     */
    private function checkTransactionSync(): array
    {
        try {
            $currentNodeId = $this->getCurrentNodeId();
            
            // Get transaction counts from network
            $networkTxCounts = $this->getNetworkTransactionCounts();
            if (empty($networkTxCounts)) {
                return [
                    'status' => 'warning',
                    'message' => 'No network nodes available for transaction comparison'
                ];
            }

            $maxTxCount = max($networkTxCounts);
            $localTxCount = $this->getLocalTransactionCount();
            $txDelta = $maxTxCount - $localTxCount;

            // Thresholds (percentage of max)
            $warningThreshold = max(10, (int)($maxTxCount * 0.05)); // 5% or min 10
            $criticalThreshold = max(50, (int)($maxTxCount * 0.15)); // 15% or min 50

            $status = 'healthy';
            if ($txDelta >= $criticalThreshold) {
                $status = 'critical';
            } elseif ($txDelta >= $warningThreshold) {
                $status = 'warning';
            }

            $this->updateHealthMetric($currentNodeId, 'transaction_delta', $txDelta, $warningThreshold, $criticalThreshold, $status);

            return [
                'status' => $status,
                'message' => "Transaction delta: $txDelta (local: $localTxCount, max: $maxTxCount)",
                'metric' => $txDelta,
                'local_count' => $localTxCount,
                'network_max_count' => $maxTxCount,
                'threshold_warning' => $warningThreshold,
                'threshold_critical' => $criticalThreshold
            ];

        } catch (Exception $e) {
            $this->logger->error('Transaction sync check failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Check mempool synchronization
     */
    private function checkMempoolSync(): array
    {
        try {
            $currentNodeId = $this->getCurrentNodeId();
            
            // Check mempool health indicators
            $mempoolSize = $this->getLocalMempoolSize();
            $oldTransactions = $this->getOldMempoolTransactions();
            
            // Thresholds
            $maxMempoolSize = (int)($this->config['max_mempool_size'] ?? 1000);
            $maxOldTxAge = (int)($this->config['max_old_tx_age'] ?? 3600); // 1 hour

            $status = 'healthy';
            $issues = [];

            if ($mempoolSize > $maxMempoolSize) {
                $status = 'warning';
                $issues[] = "Large mempool: $mempoolSize transactions";
            }

            if ($oldTransactions > 10) {
                $status = ($status === 'healthy') ? 'warning' : 'critical';
                $issues[] = "Old transactions: $oldTransactions";
            }

            $this->updateHealthMetric($currentNodeId, 'mempool_size', $mempoolSize, $maxMempoolSize * 0.8, $maxMempoolSize, $status);

            return [
                'status' => $status,
                'message' => empty($issues) ? 'Mempool healthy' : implode(', ', $issues),
                'mempool_size' => $mempoolSize,
                'old_transactions' => $oldTransactions,
                'max_size' => $maxMempoolSize
            ];

        } catch (Exception $e) {
            $this->logger->error('Mempool sync check failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Check node connectivity
     */
    private function checkNodeConnectivity(): array
    {
        try {
            $currentNodeId = $this->getCurrentNodeId();
            
            $totalNodes = $this->getTotalNetworkNodes();
            $responsiveNodes = $this->getResponsiveNetworkNodes();
            
            if ($totalNodes === 0) {
                return [
                    'status' => 'warning',
                    'message' => 'No network nodes configured'
                ];
            }

            $connectivityRatio = $responsiveNodes / $totalNodes;
            
            // Thresholds
            $warningThreshold = 0.5;  // 50%
            $criticalThreshold = 0.25; // 25%

            $status = 'healthy';
            if ($connectivityRatio < $criticalThreshold) {
                $status = 'critical';
            } elseif ($connectivityRatio < $warningThreshold) {
                $status = 'warning';
            }

            $this->updateHealthMetric($currentNodeId, 'connectivity_ratio', $connectivityRatio, $warningThreshold, $criticalThreshold, $status);

            return [
                'status' => $status,
                'message' => "Network connectivity: $responsiveNodes/$totalNodes nodes responsive",
                'responsive_nodes' => $responsiveNodes,
                'total_nodes' => $totalNodes,
                'connectivity_ratio' => $connectivityRatio
            ];

        } catch (Exception $e) {
            $this->logger->error('Node connectivity check failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Check event processing health
     */
    private function checkEventProcessing(): array
    {
        try {
            $currentNodeId = $this->getCurrentNodeId();
            
            // Check event queue backlog
            $pendingEvents = $this->getPendingEventCount();
            $failedEvents = $this->getFailedEventCount();
            
            // Thresholds
            $warningThreshold = (int)($this->config['max_pending_events'] ?? 100);
            $criticalThreshold = (int)($this->config['max_pending_events'] ?? 100) * 5;

            $status = 'healthy';
            if ($pendingEvents > $criticalThreshold || $failedEvents > 50) {
                $status = 'critical';
            } elseif ($pendingEvents > $warningThreshold || $failedEvents > 10) {
                $status = 'warning';
            }

            $this->updateHealthMetric($currentNodeId, 'pending_events', $pendingEvents, $warningThreshold, $criticalThreshold, $status);

            return [
                'status' => $status,
                'message' => "Event processing: $pendingEvents pending, $failedEvents failed",
                'pending_events' => $pendingEvents,
                'failed_events' => $failedEvents,
                'threshold_warning' => $warningThreshold,
                'threshold_critical' => $criticalThreshold
            ];

        } catch (Exception $e) {
            $this->logger->error('Event processing check failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Trigger recovery for critical issues
     */
    private function triggerRecovery(array $criticalIssues): array
    {
        $currentNodeId = $this->getCurrentNodeId();
        $recoveryId = null;
        
        try {
            // Start recovery log
            $recoveryActions = [];
            $triggerReason = 'Critical sync issues: ' . implode(', ', array_keys($criticalIssues));
            
            $stmt = $this->pdo->prepare("
                INSERT INTO sync_recovery_log 
                (node_id, recovery_type, trigger_reason, recovery_actions, metrics_before) 
                VALUES (?, 'auto_recovery', ?, '[]', ?)
            ");
            $stmt->execute([
                $currentNodeId,
                $triggerReason,
                json_encode($criticalIssues)
            ]);
            $recoveryId = $this->pdo->lastInsertId();

            // Execute recovery actions based on issues
            foreach ($criticalIssues as $issueType => $issueData) {
                switch ($issueType) {
                    case 'height_sync':
                        $recoveryActions[] = $this->recoverHeightSync($issueData);
                        break;
                    case 'transaction_sync':
                        $recoveryActions[] = $this->recoverTransactionSync($issueData);
                        break;
                    case 'mempool_sync':
                        $recoveryActions[] = $this->recoverMempoolSync($issueData);
                        break;
                    case 'node_connectivity':
                        $recoveryActions[] = $this->recoverNodeConnectivity($issueData);
                        break;
                    case 'event_processing':
                        $recoveryActions[] = $this->recoverEventProcessing($issueData);
                        break;
                }
            }

            // Update recovery log
            $stmt = $this->pdo->prepare("
                UPDATE sync_recovery_log 
                SET recovery_actions = ?, completed_at = NOW(), success = TRUE 
                WHERE id = ?
            ");
            $stmt->execute([json_encode($recoveryActions), $recoveryId]);

            $this->logger->info("Auto-recovery completed for node $currentNodeId", [
                'recovery_id' => $recoveryId,
                'actions' => count($recoveryActions)
            ]);

            return [
                'status' => 'completed',
                'recovery_id' => $recoveryId,
                'actions' => $recoveryActions
            ];

        } catch (Exception $e) {
            $this->logger->error('Recovery failed: ' . $e->getMessage());
            
            if ($recoveryId) {
                $stmt = $this->pdo->prepare("
                    UPDATE sync_recovery_log 
                    SET completed_at = NOW(), success = FALSE, error_message = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$e->getMessage(), $recoveryId]);
            }

            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Recover height synchronization
     */
    private function recoverHeightSync(array $issueData): array
    {
        try {
            // Queue high-priority full sync
            $this->rateLimiter->queueSyncRequest('full_sync', [
                'trigger' => 'height_recovery',
                'target_height' => $issueData['network_max_height'] ?? null
            ], $this->getCurrentNodeId(), 1); // Highest priority

            return [
                'action' => 'height_recovery',
                'method' => 'full_sync_queued',
                'target_height' => $issueData['network_max_height'] ?? null,
                'status' => 'initiated'
            ];
        } catch (Exception $e) {
            return [
                'action' => 'height_recovery',
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Recover transaction synchronization
     */
    private function recoverTransactionSync(array $issueData): array
    {
        try {
            // Queue transaction sync
            $this->rateLimiter->queueSyncRequest('transaction_sync', [
                'trigger' => 'transaction_recovery',
                'target_count' => $issueData['network_max_count'] ?? null
            ], $this->getCurrentNodeId(), 2);

            return [
                'action' => 'transaction_recovery',
                'method' => 'transaction_sync_queued',
                'target_count' => $issueData['network_max_count'] ?? null,
                'status' => 'initiated'
            ];
        } catch (Exception $e) {
            return [
                'action' => 'transaction_recovery',
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Recover mempool synchronization
     */
    private function recoverMempoolSync(array $issueData): array
    {
        try {
            // Clean old mempool transactions
            $cleanedCount = $this->cleanOldMempoolTransactions();
            
            // Queue mempool sync
            $this->rateLimiter->queueSyncRequest('mempool_sync', [
                'trigger' => 'mempool_recovery'
            ], $this->getCurrentNodeId(), 3);

            return [
                'action' => 'mempool_recovery',
                'method' => 'cleanup_and_sync',
                'cleaned_transactions' => $cleanedCount,
                'status' => 'initiated'
            ];
        } catch (Exception $e) {
            return [
                'action' => 'mempool_recovery',
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Recover node connectivity
     */
    private function recoverNodeConnectivity(array $issueData): array
    {
        try {
            // Refresh network topology
            $this->refreshNetworkTopology();
            
            return [
                'action' => 'connectivity_recovery',
                'method' => 'topology_refresh',
                'responsive_nodes' => $issueData['responsive_nodes'] ?? 0,
                'status' => 'completed'
            ];
        } catch (Exception $e) {
            return [
                'action' => 'connectivity_recovery',
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Recover event processing
     */
    private function recoverEventProcessing(array $issueData): array
    {
        try {
            // Reset failed events to pending (with limit)
            $stmt = $this->pdo->prepare("
                UPDATE event_queue 
                SET status = 'pending', retry_count = 0 
                WHERE status = 'failed' AND retry_count < 2
                LIMIT 50
            ");
            $stmt->execute();
            $resetCount = $stmt->rowCount();

            return [
                'action' => 'event_processing_recovery',
                'method' => 'reset_failed_events',
                'reset_count' => $resetCount,
                'status' => 'completed'
            ];
        } catch (Exception $e) {
            return [
                'action' => 'event_processing_recovery',
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    // Helper methods for metrics collection
    private function getCurrentNodeId(): string
    {
        return gethostname() . '_' . substr(md5(gethostname()), 0, 8);
    }

    private function getNetworkHeights(): array
    {
        // Implementation would call network nodes to get their heights
        // For now, return mock data
        return [989, 990, 989, 991];
    }

    private function getLocalHeight(): int
    {
        try {
            $stmt = $this->pdo->query("SELECT MAX(height) FROM blocks");
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getNetworkTransactionCounts(): array
    {
        // Implementation would call network nodes
        return [1000, 1002, 999, 1001];
    }

    private function getLocalTransactionCount(): int
    {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM transactions");
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getLocalMempoolSize(): int
    {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM mempool");
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getOldMempoolTransactions(): int
    {
        try {
            $stmt = $this->pdo->query("
                SELECT COUNT(*) FROM mempool 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getTotalNetworkNodes(): int
    {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM network_topology");
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getResponsiveNetworkNodes(): int
    {
        try {
            $stmt = $this->pdo->query("
                SELECT COUNT(*) FROM network_topology 
                WHERE last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ");
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getPendingEventCount(): int
    {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM event_queue WHERE status = 'pending'");
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getFailedEventCount(): int
    {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM event_queue WHERE status = 'failed'");
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function cleanOldMempoolTransactions(): int
    {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM mempool 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)
            ");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function refreshNetworkTopology(): void
    {
        // Implementation would refresh network topology
        $this->eventDispatcher->dispatch('network.topology.refresh', []);
    }

    private function updateHealthMetric(string $nodeId, string $metricType, float $value, float $warningThreshold, float $criticalThreshold, string $status): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO sync_health_monitor 
                (node_id, metric_type, metric_value, threshold_warning, threshold_critical, status, last_check) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    metric_value = VALUES(metric_value),
                    threshold_warning = VALUES(threshold_warning),
                    threshold_critical = VALUES(threshold_critical),
                    status = VALUES(status),
                    last_check = NOW()
            ");
            $stmt->execute([$nodeId, $metricType, $value, $warningThreshold, $criticalThreshold, $status]);
        } catch (Exception $e) {
            $this->logger->error('Failed to update health metric: ' . $e->getMessage());
        }
    }
}

<?php
declare(strict_types=1);

namespace Blockchain\Core\LoadBalancer;

use Blockchain\Core\Database\DatabaseManager;
use Blockchain\Core\Logging\LoggerInterface;
use PDO;
use Exception;

/**
 * Node Health Monitor for Load Balancing
 * Приоритет 2: Выбор узлов на основе их здоровья
 */
class NodeHealthMonitor
{
    private PDO $pdo;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->pdo = DatabaseManager::getConnection();
        $this->logger = $logger;
        $this->config = $config;

        $this->initializeHealthTables();
    }

    /**
     * Initialize health monitoring tables
     */
    private function initializeHealthTables(): void
    {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS node_health_metrics (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    node_id VARCHAR(64) NOT NULL,
                    node_url VARCHAR(255) NOT NULL,
                    response_time DECIMAL(8,4) NOT NULL DEFAULT 0,
                    success_rate DECIMAL(5,2) NOT NULL DEFAULT 100.00,
                    cpu_usage DECIMAL(5,2) DEFAULT NULL,
                    memory_usage DECIMAL(5,2) DEFAULT NULL,
                    disk_usage DECIMAL(5,2) DEFAULT NULL,
                    active_connections INT DEFAULT NULL,
                    queue_size INT DEFAULT NULL,
                    last_error TEXT NULL,
                    last_check TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    health_score DECIMAL(5,2) NOT NULL DEFAULT 100.00,
                    status ENUM('healthy', 'degraded', 'unhealthy', 'offline') DEFAULT 'healthy',
                    INDEX idx_node_health_id (node_id),
                    INDEX idx_node_health_score (health_score),
                    INDEX idx_node_health_status (status),
                    INDEX idx_node_health_check (last_check),
                    UNIQUE KEY unique_node_url (node_url)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS node_request_history (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    node_id VARCHAR(64) NOT NULL,
                    request_type VARCHAR(50) NOT NULL,
                    response_time DECIMAL(8,4) NOT NULL,
                    success BOOLEAN NOT NULL,
                    error_message TEXT NULL,
                    request_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_node_request_id (node_id),
                    INDEX idx_node_request_type (request_type),
                    INDEX idx_node_request_timestamp (request_timestamp),
                    INDEX idx_node_request_success (success)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

        } catch (Exception $e) {
            $this->logger->error('Failed to initialize health tables: ' . $e->getMessage());
        }
    }

    /**
     * Check health of all nodes
     */
    public function checkAllNodesHealth(): array
    {
        $nodes = $this->getActiveNodes();
        $results = [];

        foreach ($nodes as $node) {
            $results[$node['node_id']] = $this->checkNodeHealth($node);
        }

        return $results;
    }

    /**
     * Check health of specific node
     */
    public function checkNodeHealth(array $node): array
    {
        $startTime = microtime(true);
        $nodeId = $node['node_id'];
        $nodeUrl = $node['url'];

        try {
            // Basic connectivity test
            $healthEndpoint = rtrim($nodeUrl, '/') . '/wallet/wallet_api.php';
            $healthData = json_encode(['action' => 'get_config']);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $healthEndpoint,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $healthData,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responseTime = microtime(true) - $startTime;
            $error = curl_error($ch);
            curl_close($ch);

            $success = ($httpCode === 200 && $response !== false && empty($error));
            $healthData = null;

            if ($success) {
                $healthData = json_decode($response, true);
            }

            // Record request history
            $this->recordRequestHistory($nodeId, 'health_check', $responseTime, $success, $error);

            // Calculate health metrics
            $metrics = $this->calculateHealthMetrics($nodeId, $responseTime, $success, $healthData);

            // Update node health
            $this->updateNodeHealth($nodeId, $nodeUrl, $metrics);

            return array_merge($metrics, [
                'node_id' => $nodeId,
                'node_url' => $nodeUrl,
                'last_check' => date('Y-m-d H:i:s')
            ]);

        } catch (Exception $e) {
            $this->logger->error("Health check failed for node $nodeId: " . $e->getMessage());
            
            $this->recordRequestHistory($nodeId, 'health_check', 0, false, $e->getMessage());
            $this->updateNodeHealth($nodeId, $nodeUrl, [
                'response_time' => 999.99,
                'success_rate' => 0,
                'health_score' => 0,
                'status' => 'offline',
                'last_error' => $e->getMessage()
            ]);

            return [
                'node_id' => $nodeId,
                'node_url' => $nodeUrl,
                'status' => 'offline',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Calculate comprehensive health metrics
     */
    private function calculateHealthMetrics(string $nodeId, float $responseTime, bool $success, ?array $healthData): array
    {
        // Get historical success rate (last 100 requests)
        $successRate = $this->getRecentSuccessRate($nodeId, 100);
        
        // Get average response time (last 50 successful requests)
        $avgResponseTime = $this->getAverageResponseTime($nodeId, 50);

        // Calculate health score (0-100)
        $healthScore = $this->calculateHealthScore([
            'response_time' => $responseTime,
            'success_rate' => $successRate,
            'avg_response_time' => $avgResponseTime,
            'current_success' => $success
        ]);

        // Determine status
        $status = $this->determineNodeStatus($healthScore, $successRate, $responseTime);

        return [
            'response_time' => round($responseTime, 4),
            'success_rate' => round($successRate, 2),
            'avg_response_time' => round($avgResponseTime, 4),
            'health_score' => round($healthScore, 2),
            'status' => $status,
            'last_error' => $success ? null : 'Request failed'
        ];
    }

    /**
     * Calculate health score based on multiple factors
     */
    private function calculateHealthScore(array $metrics): float
    {
        $score = 100.0;

        // Response time penalty (0-40 points)
        $responseTime = $metrics['response_time'];
        if ($responseTime > 5.0) {
            $score -= 40; // Very slow
        } elseif ($responseTime > 2.0) {
            $score -= 25; // Slow
        } elseif ($responseTime > 1.0) {
            $score -= 15; // Moderate
        } elseif ($responseTime > 0.5) {
            $score -= 5; // Slightly slow
        }

        // Success rate penalty (0-50 points)
        $successRate = $metrics['success_rate'];
        if ($successRate < 50) {
            $score -= 50; // Very unreliable
        } elseif ($successRate < 70) {
            $score -= 30; // Unreliable
        } elseif ($successRate < 85) {
            $score -= 20; // Somewhat unreliable
        } elseif ($successRate < 95) {
            $score -= 10; // Slightly unreliable
        }

        // Current request failure penalty (0-10 points)
        if (!$metrics['current_success']) {
            $score -= 10;
        }

        // Average response time penalty (0-10 points)
        $avgResponseTime = $metrics['avg_response_time'];
        if ($avgResponseTime > 2.0) {
            $score -= 10;
        } elseif ($avgResponseTime > 1.0) {
            $score -= 5;
        }

        return max(0, $score);
    }

    /**
     * Determine node status based on metrics
     */
    private function determineNodeStatus(float $healthScore, float $successRate, float $responseTime): string
    {
        if ($healthScore >= 80 && $successRate >= 95 && $responseTime < 2.0) {
            return 'healthy';
        } elseif ($healthScore >= 60 && $successRate >= 80 && $responseTime < 5.0) {
            return 'degraded';
        } elseif ($healthScore >= 20 && $successRate >= 50) {
            return 'unhealthy';
        } else {
            return 'offline';
        }
    }

    /**
     * Get recent success rate for node
     */
    private function getRecentSuccessRate(string $nodeId, int $requestCount): float
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful
                FROM node_request_history 
                WHERE node_id = ? 
                ORDER BY request_timestamp DESC 
                LIMIT ?
            ");
            $stmt->execute([$nodeId, $requestCount]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result || $result['total'] == 0) {
                return 100.0; // No history, assume healthy
            }

            return ($result['successful'] / $result['total']) * 100;
        } catch (Exception $e) {
            $this->logger->error('Failed to get success rate: ' . $e->getMessage());
            return 100.0;
        }
    }

    /**
     * Get average response time for successful requests
     */
    private function getAverageResponseTime(string $nodeId, int $requestCount): float
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT AVG(response_time) as avg_time
                FROM node_request_history 
                WHERE node_id = ? AND success = 1
                ORDER BY request_timestamp DESC 
                LIMIT ?
            ");
            $stmt->execute([$nodeId, $requestCount]);
            $result = $stmt->fetchColumn();

            return $result ? (float)$result : 1.0;
        } catch (Exception $e) {
            $this->logger->error('Failed to get average response time: ' . $e->getMessage());
            return 1.0;
        }
    }

    /**
     * Record request history
     */
    private function recordRequestHistory(string $nodeId, string $requestType, float $responseTime, bool $success, ?string $error): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO node_request_history 
                (node_id, request_type, response_time, success, error_message) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nodeId, $requestType, $responseTime, $success, $error]);

            // Clean up old records (keep last 1000 per node)
            $stmt = $this->pdo->prepare("
                DELETE FROM node_request_history 
                WHERE node_id = ? 
                AND id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM node_request_history 
                        WHERE node_id = ? 
                        ORDER BY request_timestamp DESC 
                        LIMIT 1000
                    ) as recent
                )
            ");
            $stmt->execute([$nodeId, $nodeId]);

        } catch (Exception $e) {
            $this->logger->error('Failed to record request history: ' . $e->getMessage());
        }
    }

    /**
     * Update node health metrics
     */
    private function updateNodeHealth(string $nodeId, string $nodeUrl, array $metrics): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO node_health_metrics 
                (node_id, node_url, response_time, success_rate, health_score, status, last_error, last_check) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    response_time = VALUES(response_time),
                    success_rate = VALUES(success_rate),
                    health_score = VALUES(health_score),
                    status = VALUES(status),
                    last_error = VALUES(last_error),
                    last_check = NOW()
            ");
            
            $stmt->execute([
                $nodeId,
                $nodeUrl,
                $metrics['response_time'] ?? 999.99,
                $metrics['success_rate'] ?? 0,
                $metrics['health_score'] ?? 0,
                $metrics['status'] ?? 'offline',
                $metrics['last_error'] ?? null
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update node health: ' . $e->getMessage());
        }
    }

    /**
     * Get healthy nodes for load balancing
     */
    public function getHealthyNodes(int $limit = 10): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    node_id,
                    node_url,
                    health_score,
                    response_time,
                    success_rate,
                    status
                FROM node_health_metrics 
                WHERE status IN ('healthy', 'degraded') 
                AND last_check > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                ORDER BY health_score DESC, response_time ASC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $this->logger->error('Failed to get healthy nodes: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get best node for specific operation
     */
    public function getBestNode(string $operationType = 'default'): ?array
    {
        $healthyNodes = $this->getHealthyNodes(5);
        
        if (empty($healthyNodes)) {
            return null;
        }

        // Weight nodes based on operation type
        foreach ($healthyNodes as &$node) {
            $node['weight'] = $this->calculateNodeWeight($node, $operationType);
        }

        // Sort by weight (highest first)
        usort($healthyNodes, fn($a, $b) => $b['weight'] <=> $a['weight']);

        return $healthyNodes[0] ?? null;
    }

    /**
     * Calculate node weight for specific operation
     */
    private function calculateNodeWeight(array $node, string $operationType): float
    {
        $weight = $node['health_score'];

        // Adjust weight based on operation type
        switch ($operationType) {
            case 'transaction':
                // Prefer faster response times for transactions
                $weight += (5.0 - min(5.0, $node['response_time'])) * 10;
                break;
            case 'sync':
                // Prefer most reliable nodes for sync
                $weight += ($node['success_rate'] - 90) * 2;
                break;
            case 'query':
                // Balance between speed and reliability for queries
                $weight += (5.0 - min(5.0, $node['response_time'])) * 5;
                $weight += ($node['success_rate'] - 90);
                break;
        }

        return $weight;
    }

    /**
     * Get active nodes from network topology
     */
    private function getActiveNodes(): array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    node_id,
                    CONCAT(protocol, '://', domain, 
                           CASE WHEN port != 80 AND port != 443 
                                THEN CONCAT(':', port) 
                                ELSE '' 
                           END) as url,
                    reputation_score,
                    last_seen
                FROM network_topology 
                WHERE is_active = 1 
                AND last_seen > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                ORDER BY reputation_score DESC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $this->logger->error('Failed to get active nodes: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get health statistics
     */
    public function getHealthStats(): array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    status,
                    COUNT(*) as count,
                    AVG(health_score) as avg_health_score,
                    AVG(response_time) as avg_response_time,
                    AVG(success_rate) as avg_success_rate
                FROM node_health_metrics 
                WHERE last_check > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                GROUP BY status
            ");
            $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($stats as $stat) {
                $result[$stat['status']] = [
                    'count' => (int)$stat['count'],
                    'avg_health_score' => round((float)$stat['avg_health_score'], 2),
                    'avg_response_time' => round((float)$stat['avg_response_time'], 4),
                    'avg_success_rate' => round((float)$stat['avg_success_rate'], 2)
                ];
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Failed to get health stats: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean up old health data
     */
    public function cleanup(): int
    {
        try {
            $deletedCount = 0;

            // Remove old request history (older than 7 days)
            $stmt = $this->pdo->prepare("
                DELETE FROM node_request_history 
                WHERE request_timestamp < DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute();
            $deletedCount += $stmt->rowCount();

            // Remove old health metrics (older than 1 day, keep latest for each node)
            $stmt = $this->pdo->prepare("
                DELETE nhm1 FROM node_health_metrics nhm1
                INNER JOIN node_health_metrics nhm2 
                WHERE nhm1.node_id = nhm2.node_id 
                AND nhm1.last_check < nhm2.last_check 
                AND nhm1.last_check < DATE_SUB(NOW(), INTERVAL 1 DAY)
            ");
            $stmt->execute();
            $deletedCount += $stmt->rowCount();

            if ($deletedCount > 0) {
                $this->logger->info("Cleaned up $deletedCount health monitoring records");
            }

            return $deletedCount;
        } catch (Exception $e) {
            $this->logger->error('Failed to cleanup health data: ' . $e->getMessage());
            return 0;
        }
    }
}

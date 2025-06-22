<?php
declare(strict_types=1);

namespace Blockchain\Core\Monitoring;

use Psr\Log\LoggerInterface;
use Exception;

/**
 * Blockchain Monitoring System
 */
class MonitoringSystem
{
    private LoggerInterface $logger;
    private array $config;
    private array $metrics;
    private array $alerts;
    private string $dataDir;

    public function __construct(
        LoggerInterface $logger,
        array $config = [],
        string $dataDir = null
    ) {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->dataDir = $dataDir ?? __DIR__ . '/../../storage/monitoring';
        $this->metrics = [];
        $this->alerts = [];
        
        $this->initializeMonitoring();
    }

    /**
     * Initialize monitoring system
     */
    private function initializeMonitoring(): void
    {
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }

        // Load existing metrics
        $this->loadMetrics();
        
        // Register shutdown function to save metrics
        register_shutdown_function([$this, 'saveMetrics']);
    }

    /**
     * Record blockchain metrics
     */
    public function recordBlockchainMetrics(array $blockchainData): void
    {
        $timestamp = time();
        
        $metrics = [
            'timestamp' => $timestamp,
            'block_height' => $blockchainData['block_height'] ?? 0,
            'total_transactions' => $blockchainData['total_transactions'] ?? 0,
            'pending_transactions' => $blockchainData['pending_transactions'] ?? 0,
            'active_validators' => $blockchainData['active_validators'] ?? 0,
            'total_stake' => $blockchainData['total_stake'] ?? 0,
            'network_hash_rate' => $blockchainData['network_hash_rate'] ?? 0,
            'block_time_avg' => $blockchainData['block_time_avg'] ?? 0,
            'transaction_throughput' => $blockchainData['transaction_throughput'] ?? 0,
        ];

        $this->addMetric('blockchain', $metrics);
        $this->checkBlockchainAlerts($metrics);
    }

    /**
     * Record node performance metrics
     */
    public function recordNodeMetrics(): void
    {
        $timestamp = time();
        
        $metrics = [
            'timestamp' => $timestamp,
            'cpu_usage' => $this->getCPUUsage(),
            'memory_usage' => $this->getMemoryUsage(),
            'disk_usage' => $this->getDiskUsage(),
            'network_connections' => $this->getNetworkConnections(),
            'peer_count' => $this->getPeerCount(),
            'sync_status' => $this->getSyncStatus(),
            'uptime' => $this->getUptime(),
        ];

        $this->addMetric('node', $metrics);
        $this->checkNodeAlerts($metrics);
    }

    /**
     * Record API metrics
     */
    public function recordAPIMetrics(string $endpoint, float $responseTime, int $statusCode): void
    {
        $timestamp = time();
        
        $metrics = [
            'timestamp' => $timestamp,
            'endpoint' => $endpoint,
            'response_time' => $responseTime,
            'status_code' => $statusCode,
            'success' => $statusCode < 400,
        ];

        $this->addMetric('api', $metrics);
        $this->updateAPIStats($endpoint, $responseTime, $statusCode);
    }

    /**
     * Record security events
     */
    public function recordSecurityEvent(string $type, array $details): void
    {
        $timestamp = time();
        
        $event = [
            'timestamp' => $timestamp,
            'type' => $type,
            'details' => $details,
            'severity' => $this->getSecuritySeverity($type),
        ];

        $this->addMetric('security', $event);
        
        // Trigger immediate alert for high severity events
        if ($event['severity'] === 'high' || $event['severity'] === 'critical') {
            $this->triggerAlert('security', "Security event: {$type}", $event);
        }
    }

    /**
     * Get monitoring dashboard data
     */
    public function getDashboardData(int $timeRange = 3600): array
    {
        $endTime = time();
        $startTime = $endTime - $timeRange;

        return [
            'blockchain_metrics' => $this->getMetricsInRange('blockchain', $startTime, $endTime),
            'node_metrics' => $this->getMetricsInRange('node', $startTime, $endTime),
            'api_metrics' => $this->getAPIStatsSummary($startTime, $endTime),
            'security_events' => $this->getMetricsInRange('security', $startTime, $endTime),
            'alerts' => $this->getActiveAlerts(),
            'system_health' => $this->getSystemHealth(),
        ];
    }

    /**
     * Get system health status
     */
    public function getSystemHealth(): array
    {
        $health = [
            'overall_status' => 'healthy',
            'components' => []
        ];

        // Check blockchain health
        $blockchainHealth = $this->checkBlockchainHealth();
        $health['components']['blockchain'] = $blockchainHealth;

        // Check node health
        $nodeHealth = $this->checkNodeHealth();
        $health['components']['node'] = $nodeHealth;

        // Check API health
        $apiHealth = $this->checkAPIHealth();
        $health['components']['api'] = $apiHealth;

        // Determine overall health
        $statuses = array_column($health['components'], 'status');
        if (in_array('critical', $statuses)) {
            $health['overall_status'] = 'critical';
        } elseif (in_array('warning', $statuses)) {
            $health['overall_status'] = 'warning';
        }

        return $health;
    }

    /**
     * Check blockchain health
     */
    private function checkBlockchainHealth(): array
    {
        $latestMetrics = $this->getLatestMetrics('blockchain');
        
        if (!$latestMetrics) {
            return ['status' => 'unknown', 'message' => 'No metrics available'];
        }

        $issues = [];
        
        // Check if blocks are being produced
        if (time() - $latestMetrics['timestamp'] > 300) { // 5 minutes
            $issues[] = 'No recent blockchain metrics';
        }

        // Check block time
        if ($latestMetrics['block_time_avg'] > $this->config['alerts']['block_time_threshold']) {
            $issues[] = 'Block time too high';
        }

        // Check pending transactions
        if ($latestMetrics['pending_transactions'] > $this->config['alerts']['pending_tx_threshold']) {
            $issues[] = 'High pending transactions';
        }

        if (empty($issues)) {
            return ['status' => 'healthy', 'message' => 'All blockchain metrics normal'];
        } else {
            $severity = count($issues) > 2 ? 'critical' : 'warning';
            return ['status' => $severity, 'message' => implode(', ', $issues)];
        }
    }

    /**
     * Check node health
     */
    private function checkNodeHealth(): array
    {
        $latestMetrics = $this->getLatestMetrics('node');
        
        if (!$latestMetrics) {
            return ['status' => 'unknown', 'message' => 'No node metrics available'];
        }

        $issues = [];
        
        // Check CPU usage
        if ($latestMetrics['cpu_usage'] > $this->config['alerts']['cpu_threshold']) {
            $issues[] = 'High CPU usage';
        }

        // Check memory usage
        if ($latestMetrics['memory_usage'] > $this->config['alerts']['memory_threshold']) {
            $issues[] = 'High memory usage';
        }

        // Check disk usage
        if ($latestMetrics['disk_usage'] > $this->config['alerts']['disk_threshold']) {
            $issues[] = 'High disk usage';
        }

        // Check peer count
        if ($latestMetrics['peer_count'] < $this->config['alerts']['min_peers']) {
            $issues[] = 'Low peer count';
        }

        if (empty($issues)) {
            return ['status' => 'healthy', 'message' => 'All node metrics normal'];
        } else {
            $severity = count($issues) > 2 ? 'critical' : 'warning';
            return ['status' => $severity, 'message' => implode(', ', $issues)];
        }
    }

    /**
     * Check API health
     */
    private function checkAPIHealth(): array
    {
        $stats = $this->getAPIStatsSummary(time() - 3600, time());
        
        if (empty($stats)) {
            return ['status' => 'unknown', 'message' => 'No API metrics available'];
        }

        $issues = [];
        
        // Check error rate
        if ($stats['error_rate'] > $this->config['alerts']['api_error_rate_threshold']) {
            $issues[] = 'High API error rate';
        }

        // Check response time
        if ($stats['avg_response_time'] > $this->config['alerts']['api_response_time_threshold']) {
            $issues[] = 'High API response time';
        }

        if (empty($issues)) {
            return ['status' => 'healthy', 'message' => 'API performance normal'];
        } else {
            return ['status' => 'warning', 'message' => implode(', ', $issues)];
        }
    }

    /**
     * Add metric to storage
     */
    private function addMetric(string $type, array $data): void
    {
        if (!isset($this->metrics[$type])) {
            $this->metrics[$type] = [];
        }

        $this->metrics[$type][] = $data;

        // Keep only recent metrics in memory
        if (count($this->metrics[$type]) > $this->config['max_metrics_in_memory']) {
            array_shift($this->metrics[$type]);
        }
    }

    /**
     * Get metrics in time range
     */
    private function getMetricsInRange(string $type, int $startTime, int $endTime): array
    {
        $metrics = $this->metrics[$type] ?? [];
        
        return array_filter($metrics, function($metric) use ($startTime, $endTime) {
            return $metric['timestamp'] >= $startTime && $metric['timestamp'] <= $endTime;
        });
    }

    /**
     * Get latest metrics for type
     */
    private function getLatestMetrics(string $type): ?array
    {
        $metrics = $this->metrics[$type] ?? [];
        return empty($metrics) ? null : end($metrics);
    }

    /**
     * Trigger alert
     */
    private function triggerAlert(string $type, string $message, array $data = []): void
    {
        $alert = [
            'id' => uniqid(),
            'type' => $type,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
            'acknowledged' => false,
        ];

        $this->alerts[] = $alert;

        // Log alert
        $this->logger->warning("Alert triggered", $alert);

        // Send notifications if configured
        $this->sendNotification($alert);
    }

    /**
     * Send notification for alert
     */
    private function sendNotification(array $alert): void
    {
        // Implement notification logic (email, webhook, etc.)
        if ($this->config['notifications']['enabled']) {
            // Send email notification
            if ($this->config['notifications']['email']['enabled']) {
                $this->sendEmailNotification($alert);
            }

            // Send webhook notification
            if ($this->config['notifications']['webhook']['enabled']) {
                $this->sendWebhookNotification($alert);
            }
        }
    }

    /**
     * System metric collection methods
     */
    private function getCPUUsage(): float
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return $load[0] ?? 0.0;
        }
        return 0.0;
    }

    private function getMemoryUsage(): float
    {
        return memory_get_usage(true) / 1024 / 1024; // MB
    }

    private function getDiskUsage(): float
    {
        $bytes = disk_total_space($this->dataDir) - disk_free_space($this->dataDir);
        return ($bytes / disk_total_space($this->dataDir)) * 100; // Percentage
    }

    private function getNetworkConnections(): int
    {
        // Implementation depends on OS
        return 0; // Placeholder
    }

    private function getPeerCount(): int
    {
        // This would integrate with your P2P network manager
        return 0; // Placeholder
    }

    private function getSyncStatus(): bool
    {
        // Check if node is synchronized
        return true; // Placeholder
    }

    private function getUptime(): int
    {
        // Get process uptime
        return time() - filemtime(__FILE__); // Simplified
    }

    /**
     * Load metrics from storage
     */
    private function loadMetrics(): void
    {
        $metricsFile = $this->dataDir . '/metrics.json';
        
        if (file_exists($metricsFile)) {
            $data = json_decode(file_get_contents($metricsFile), true);
            $this->metrics = $data['metrics'] ?? [];
            $this->alerts = $data['alerts'] ?? [];
        }
    }

    /**
     * Save metrics to storage
     */
    public function saveMetrics(): void
    {
        $data = [
            'metrics' => $this->metrics,
            'alerts' => $this->alerts,
            'last_updated' => time()
        ];

        $metricsFile = $this->dataDir . '/metrics.json';
        file_put_contents($metricsFile, json_encode($data));
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'max_metrics_in_memory' => 1000,
            'alerts' => [
                'cpu_threshold' => 80.0,
                'memory_threshold' => 80.0,
                'disk_threshold' => 90.0,
                'min_peers' => 3,
                'block_time_threshold' => 30,
                'pending_tx_threshold' => 1000,
                'api_error_rate_threshold' => 5.0,
                'api_response_time_threshold' => 1000.0,
            ],
            'notifications' => [
                'enabled' => false,
                'email' => ['enabled' => false],
                'webhook' => ['enabled' => false],
            ],
        ];
    }

    /**
     * Additional helper methods would go here...
     */
    private function checkBlockchainAlerts(array $metrics): void {}
    private function checkNodeAlerts(array $metrics): void {}
    private function updateAPIStats(string $endpoint, float $responseTime, int $statusCode): void {}
    private function getAPIStatsSummary(int $startTime, int $endTime): array { return []; }
    private function getActiveAlerts(): array { return $this->alerts; }
    private function getSecuritySeverity(string $type): string { return 'medium'; }
    private function sendEmailNotification(array $alert): void {}
    private function sendWebhookNotification(array $alert): void {}
}

<?php
/**
 * Health Check Middleware - automatic check on every request
 * Integrates into every API call for instant diagnostics
 */

namespace Core\Network;

use Exception;

class HealthCheckMiddleware
{
    private NodeHealthMonitor $healthMonitor;
    private array $config;
    private bool $initialized = false;
    
    public function __construct(NodeHealthMonitor $healthMonitor, array $config = [])
    {
        $this->healthMonitor = $healthMonitor;
        $this->config = array_merge([
            'skip_health_check' => false,
            'quick_check_only' => true,
            'max_check_time' => 100, // ms
            'emergency_mode' => false
        ], $config);
    }
    
    /**
     * Middleware for health check before request processing
     */
    public function handle(callable $next, ...$args)
    {
        // If health check is disabled - just execute the request
        if ($this->config['skip_health_check']) {
            return $next(...$args);
        }
        
        $startTime = microtime(true);
        
        try {
            // Quick health check
            $healthStatus = $this->healthMonitor->quickHealthCheck();
            
            $checkTime = (microtime(true) - $startTime) * 1000;
            
            // If check took too long - something is wrong
            if ($checkTime > $this->config['max_check_time']) {
                error_log("Health check took too long: {$checkTime}ms");
            }
            
            // If node is unhealthy - return error instead of executing request
            if (!$healthStatus['healthy']) {
                return $this->handleUnhealthyNode($healthStatus);
            }
            
            // Node is healthy - execute main request
            $result = $next(...$args);
            
            // Add health information to response
            if (is_array($result)) {
                $result['node_health'] = [
                    'status' => $healthStatus['status'] ?? 'healthy',
                    'check_time' => round($checkTime, 2),
                    'node_id' => $healthStatus['node_id']
                ];
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Health check middleware error: " . $e->getMessage());
            
            // In case of health check error - still try to execute request
            return $next(...$args);
        }
    }
    
    /**
     * Handle situation when node is unhealthy
     */
    private function handleUnhealthyNode(array $healthStatus): array
    {
        $response = [
            'success' => false,
            'error' => 'Node is currently unhealthy',
            'node_status' => $healthStatus['status'] ?? 'unknown',
            'node_id' => $healthStatus['node_id'],
            'errors' => $healthStatus['errors'] ?? [],
            'recovery_info' => $this->getRecoveryInfo($healthStatus),
            'timestamp' => time()
        ];
        
        // Set HTTP status code
        if (!headers_sent()) {
            switch ($healthStatus['status'] ?? 'unknown') {
                case NodeHealthMonitor::STATUS_RECOVERING:
                    http_response_code(503); // Service Unavailable
                    header('Retry-After: 60'); // Try again in a minute
                    break;
                    
                case NodeHealthMonitor::STATUS_DEGRADED:
                    http_response_code(206); // Partial Content
                    break;
                    
                case NodeHealthMonitor::STATUS_ERROR:
                case NodeHealthMonitor::STATUS_OFFLINE:
                default:
                    http_response_code(503); // Service Unavailable
                    break;
            }
            
            header('Content-Type: application/json');
            header('X-Node-Status: ' . $healthStatus['status']);
            header('X-Node-ID: ' . $healthStatus['node_id']);
        }
        
        return $response;
    }
    
    /**
     * Получить информацию о процессе восстановления
     */
    private function getRecoveryInfo(array $healthStatus): array
    {
        $info = [
            'estimated_time' => null,
            'recommended_action' => 'wait',
            'alternative_nodes' => []
        ];
        
        switch ($healthStatus['status']) {
            case NodeHealthMonitor::STATUS_RECOVERING:
                $info['estimated_time'] = 300; // 5 минут
                $info['recommended_action'] = 'wait_or_retry_other_node';
                break;
                
            case NodeHealthMonitor::STATUS_DEGRADED:
                $info['estimated_time'] = 60; // 1 минута
                $info['recommended_action'] = 'retry_in_1_minute';
                break;
                
            case NodeHealthMonitor::STATUS_ERROR:
                $info['estimated_time'] = 600; // 10 минут
                $info['recommended_action'] = 'use_other_node';
                break;
        }
        
        // Получаем список альтернативных нод
        $info['alternative_nodes'] = $this->getHealthyAlternativeNodes();
        
        return $info;
    }
    
    /**
     * Получить список здоровых альтернативных нод
     */
    private function getHealthyAlternativeNodes(): array
    {
        try {
            $networkStats = $this->healthMonitor->getNetworkStats();
            $healthyNodes = [];
            
            foreach ($networkStats as $stat) {
                if ($stat['status'] === NodeHealthMonitor::STATUS_HEALTHY) {
                    $healthyNodes[] = [
                        'count' => $stat['count'],
                        'avg_response_time' => $stat['avg_last_seen']
                    ];
                }
            }
            
            return $healthyNodes;
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Статическая функция для быстрой интеграции
     */
    public static function checkAndExecute(
        NodeHealthMonitor $healthMonitor,
        callable $callback,
        array $config = []
    ) {
        $middleware = new self($healthMonitor, $config);
        return $middleware->handle($callback);
    }
}

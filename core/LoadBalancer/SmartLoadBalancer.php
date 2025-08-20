<?php
declare(strict_types=1);

namespace Blockchain\Core\LoadBalancer;

use Blockchain\Core\Database\DatabaseManager;
use Blockchain\Core\Logging\LoggerInterface;
use PDO;
use Exception;

/**
 * Smart Load Balancer with Health Monitoring and Circuit Breaking
 * Priority 2: Distribution of operations across all nodes
 */
class SmartLoadBalancer
{
    private NodeHealthMonitor $healthMonitor;
    private CircuitBreaker $circuitBreaker;
    private LoggerInterface $logger;
    private array $config;
    private array $nodeWeights = [];

    public function __construct(
        NodeHealthMonitor $healthMonitor,
        CircuitBreaker $circuitBreaker,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->healthMonitor = $healthMonitor;
        $this->circuitBreaker = $circuitBreaker;
        $this->logger = $logger;
        $this->config = array_merge([
            'max_retries' => 3,
            'retry_delay' => 1000, // milliseconds
            'load_balancing_strategy' => 'weighted_round_robin', // weighted_round_robin, least_connections, health_based
            'health_check_interval' => 60, // seconds
            'circuit_breaker_enabled' => true,
        ], $config);
    }

    /**
     * Select best node for operation with load balancing
     */
    public function selectNode(string $operationType = 'default', array $excludeNodes = []): ?array
    {
        try {
            // Get healthy nodes
            $healthyNodes = $this->getAvailableNodes($operationType, $excludeNodes);
            
            if (empty($healthyNodes)) {
                $this->logger->warning("No healthy nodes available for operation: $operationType");
                return null;
            }

            // Apply load balancing strategy
            $selectedNode = $this->applyLoadBalancingStrategy($healthyNodes, $operationType);
            
            if ($selectedNode) {
                $this->logger->debug("Selected node for $operationType: {$selectedNode['node_id']}");
            }

            return $selectedNode;

        } catch (Exception $e) {
            $this->logger->error("Failed to select node: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Execute operation with automatic failover and retry
     */
    public function executeWithFailover(callable $operation, string $operationType = 'default', array $operationData = []): array
    {
        $maxRetries = $this->config['max_retries'];
        $retryDelay = $this->config['retry_delay'];
        $excludeNodes = [];
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $node = $this->selectNode($operationType, $excludeNodes);
            
            if (!$node) {
                return [
                    'success' => false,
                    'error' => 'No available nodes for operation',
                    'attempts' => $attempt,
                    'operation_type' => $operationType
                ];
            }

            // Check circuit breaker
            if ($this->config['circuit_breaker_enabled'] && 
                !$this->circuitBreaker->allowRequest($node['node_id'], $operationType)) {
                
                $this->logger->info("Circuit breaker blocked request to {$node['node_id']} for $operationType");
                $excludeNodes[] = $node['node_id'];
                continue;
            }

            try {
                $startTime = microtime(true);
                
                // Execute operation
                $result = $operation($node, $operationData);
                
                $responseTime = microtime(true) - $startTime;
                
                // Record success
                if ($this->config['circuit_breaker_enabled']) {
                    $this->circuitBreaker->recordSuccess($node['node_id'], $operationType, $responseTime);
                }

                return [
                    'success' => true,
                    'result' => $result,
                    'node_used' => $node,
                    'response_time' => $responseTime,
                    'attempts' => $attempt,
                    'operation_type' => $operationType
                ];

            } catch (Exception $e) {
                $lastError = $e;
                $responseTime = microtime(true) - $startTime;
                
                // Record failure
                if ($this->config['circuit_breaker_enabled']) {
                    $this->circuitBreaker->recordFailure($node['node_id'], $operationType, $e->getMessage());
                }

                $this->logger->warning("Operation failed on {$node['node_id']} (attempt $attempt): " . $e->getMessage());
                
                // Add to exclude list for next attempt
                $excludeNodes[] = $node['node_id'];
                
                // Wait before retry (except on last attempt)
                if ($attempt < $maxRetries) {
                    usleep($retryDelay * 1000); // Convert to microseconds
                    $retryDelay *= 2; // Exponential backoff
                }
            }
        }

        return [
            'success' => false,
            'error' => $lastError ? $lastError->getMessage() : 'All nodes failed',
            'attempts' => $maxRetries,
            'operation_type' => $operationType,
            'excluded_nodes' => $excludeNodes
        ];
    }

    /**
     * Get available nodes for operation
     */
    private function getAvailableNodes(string $operationType, array $excludeNodes = []): array
    {
        // Get healthy nodes from health monitor
        $healthyNodes = $this->healthMonitor->getHealthyNodes(20);
        
        // Filter out excluded nodes
        if (!empty($excludeNodes)) {
            $healthyNodes = array_filter($healthyNodes, function($node) use ($excludeNodes) {
                return !in_array($node['node_id'], $excludeNodes);
            });
        }

        // Filter by circuit breaker state if enabled
        if ($this->config['circuit_breaker_enabled']) {
            $healthyNodes = array_filter($healthyNodes, function($node) use ($operationType) {
                return $this->circuitBreaker->allowRequest($node['node_id'], $operationType);
            });
        }

        return array_values($healthyNodes); // Re-index array
    }

    /**
     * Apply load balancing strategy
     */
    private function applyLoadBalancingStrategy(array $nodes, string $operationType): ?array
    {
        if (empty($nodes)) {
            return null;
        }

        switch ($this->config['load_balancing_strategy']) {
            case 'weighted_round_robin':
                return $this->weightedRoundRobin($nodes, $operationType);
            
            case 'least_connections':
                return $this->leastConnections($nodes, $operationType);
            
            case 'health_based':
                return $this->healthBasedSelection($nodes, $operationType);
            
            case 'random':
                return $nodes[array_rand($nodes)];
            
            default:
                return $nodes[0]; // First available node
        }
    }

    /**
     * Weighted round robin selection
     */
    private function weightedRoundRobin(array $nodes, string $operationType): array
    {
        // Calculate weights based on health scores and response times
        $weightedNodes = [];
        
        foreach ($nodes as $node) {
            $weight = $this->calculateNodeWeight($node, $operationType);
            
            // Add multiple entries based on weight
            $entries = max(1, (int)($weight / 10)); // Weight 100 = 10 entries, Weight 50 = 5 entries
            for ($i = 0; $i < $entries; $i++) {
                $weightedNodes[] = $node;
            }
        }

        // Select random entry from weighted array
        return $weightedNodes[array_rand($weightedNodes)];
    }

    /**
     * Least connections selection (simulated by queue size)
     */
    private function leastConnections(array $nodes, string $operationType): array
    {
        // Sort by queue size (if available) and response time
        usort($nodes, function($a, $b) {
            $queueA = $a['queue_size'] ?? 0;
            $queueB = $b['queue_size'] ?? 0;
            
            if ($queueA === $queueB) {
                return $a['response_time'] <=> $b['response_time'];
            }
            
            return $queueA <=> $queueB;
        });

        return $nodes[0];
    }

    /**
     * Health-based selection (best health score)
     */
    private function healthBasedSelection(array $nodes, string $operationType): array
    {
        // Sort by health score (highest first)
        usort($nodes, function($a, $b) {
            return $b['health_score'] <=> $a['health_score'];
        });

        return $nodes[0];
    }

    /**
     * Calculate node weight for load balancing
     */
    private function calculateNodeWeight(array $node, string $operationType): float
    {
        $baseWeight = $node['health_score'] ?? 50;
        
        // Adjust weight based on response time
        $responseTime = $node['response_time'] ?? 1.0;
        $responseWeight = max(10, 100 - ($responseTime * 20)); // Penalize slow responses
        
        // Adjust weight based on success rate
        $successRate = $node['success_rate'] ?? 100;
        $successWeight = $successRate;
        
        // Operation-specific adjustments
        $operationMultiplier = 1.0;
        switch ($operationType) {
            case 'transaction':
                // Prioritize fast response times for transactions
                $operationMultiplier = $responseWeight / 100;
                break;
            case 'sync':
                // Prioritize reliability for sync operations
                $operationMultiplier = $successWeight / 100;
                break;
            case 'query':
                // Balance speed and reliability for queries
                $operationMultiplier = ($responseWeight + $successWeight) / 200;
                break;
        }

        $finalWeight = ($baseWeight + $responseWeight + $successWeight) / 3 * $operationMultiplier;
        
        return max(1, $finalWeight);
    }

    /**
     * Get load balancing statistics
     */
    public function getLoadBalancerStats(): array
    {
        try {
            $healthStats = $this->healthMonitor->getHealthStats();
            $circuitStats = $this->circuitBreaker->getCircuitStats();
            
            return [
                'strategy' => $this->config['load_balancing_strategy'],
                'health_monitor' => $healthStats,
                'circuit_breaker' => $circuitStats,
                'config' => [
                    'max_retries' => $this->config['max_retries'],
                    'circuit_breaker_enabled' => $this->config['circuit_breaker_enabled'],
                    'health_check_interval' => $this->config['health_check_interval']
                ]
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to get load balancer stats: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Perform health checks on all nodes
     */
    public function performHealthChecks(): array
    {
        return $this->healthMonitor->checkAllNodesHealth();
    }

    /**
     * Get nodes by status
     */
    public function getNodesByStatus(string $status): array
    {
        try {
            $pdo = DatabaseManager::getConnection();
            $stmt = $pdo->prepare("
                SELECT 
                    node_id,
                    node_url,
                    health_score,
                    response_time,
                    success_rate,
                    status,
                    last_check
                FROM node_health_metrics 
                WHERE status = ? 
                ORDER BY health_score DESC
            ");
            $stmt->execute([$status]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $this->logger->error('Failed to get nodes by status: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Reset circuit breaker for node
     */
    public function resetCircuitBreaker(string $nodeId, string $operationType = 'default'): bool
    {
        return $this->circuitBreaker->resetCircuit($nodeId, $operationType);
    }

    /**
     * Add node to load balancer
     */
    public function addNode(string $nodeId, string $nodeUrl): bool
    {
        try {
            $pdo = DatabaseManager::getConnection();
            
            // Add to network topology if not exists
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO network_topology 
                (node_id, domain, protocol, port, is_active, reputation_score, last_seen) 
                VALUES (?, ?, ?, ?, 1, 100, NOW())
            ");
            
            $parsedUrl = parse_url($nodeUrl);
            $domain = $parsedUrl['host'] ?? '';
            $protocol = $parsedUrl['scheme'] ?? 'https';
            $port = $parsedUrl['port'] ?? ($protocol === 'https' ? 443 : 80);
            
            $stmt->execute([$nodeId, $domain, $protocol, $port]);
            
            // Initialize health metrics
            $this->healthMonitor->checkNodeHealth([
                'node_id' => $nodeId,
                'url' => $nodeUrl
            ]);
            
            $this->logger->info("Added node to load balancer: $nodeId ($nodeUrl)");
            return true;
            
        } catch (Exception $e) {
            $this->logger->error("Failed to add node: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove node from load balancer
     */
    public function removeNode(string $nodeId): bool
    {
        try {
            $pdo = DatabaseManager::getConnection();
            
            // Deactivate in network topology
            $stmt = $pdo->prepare("
                UPDATE network_topology 
                SET is_active = 0 
                WHERE node_id = ?
            ");
            $stmt->execute([$nodeId]);
            
            // Update health status
            $stmt = $pdo->prepare("
                UPDATE node_health_metrics 
                SET status = 'offline' 
                WHERE node_id = ?
            ");
            $stmt->execute([$nodeId]);
            
            $this->logger->info("Removed node from load balancer: $nodeId");
            return true;
            
        } catch (Exception $e) {
            $this->logger->error("Failed to remove node: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update load balancer configuration
     */
    public function updateConfig(array $newConfig): void
    {
        $this->config = array_merge($this->config, $newConfig);
        $this->logger->info("Updated load balancer configuration", $newConfig);
    }

    /**
     * Cleanup old data
     */
    public function cleanup(): int
    {
        $deletedCount = 0;
        $deletedCount += $this->healthMonitor->cleanup();
        $deletedCount += $this->circuitBreaker->cleanup();
        
        return $deletedCount;
    }
}

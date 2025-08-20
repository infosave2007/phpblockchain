<?php
declare(strict_types=1);

namespace Blockchain\Core\LoadBalancer;

use Blockchain\Core\Database\DatabaseManager;
use Blockchain\Core\Logging\LoggerInterface;
use PDO;
use Exception;

/**
 * Circuit Breaker Pattern Implementation
 * Priority 2: Circuit breaker pattern for overloaded nodes
 */
class CircuitBreaker
{
    private PDO $pdo;
    private LoggerInterface $logger;
    private array $config;

    // Circuit states
    const STATE_CLOSED = 'closed';       // Normal operation
    const STATE_OPEN = 'open';           // Failing, reject requests
    const STATE_HALF_OPEN = 'half_open'; // Testing if service recovered

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->pdo = DatabaseManager::getConnection();
        $this->logger = $logger;
        $this->config = array_merge([
            'failure_threshold' => 5,        // Number of failures to open circuit
            'success_threshold' => 3,        // Number of successes to close circuit in half-open
            'timeout' => 60,                 // Seconds before trying half-open
            'request_volume_threshold' => 10, // Minimum requests before considering failure rate
            'error_percentage_threshold' => 50, // Error percentage to open circuit
        ], $config);
    }


    /**
     * Check if request should be allowed through circuit breaker
     */
    public function allowRequest(string $nodeId, string $operationType = 'default'): bool
    {
        $circuitId = $this->getCircuitId($nodeId, $operationType);
        
        try {
            $circuit = $this->getCircuitState($circuitId);
            
            if (!$circuit) {
                // Initialize new circuit as closed
                $this->initializeCircuit($circuitId, $nodeId, $operationType);
                return true;
            }

            switch ($circuit['state']) {
                case self::STATE_CLOSED:
                    return true;

                case self::STATE_OPEN:
                    // Check if timeout period has passed
                    if ($this->shouldAttemptReset($circuit)) {
                        $this->transitionToHalfOpen($circuitId);
                        $this->logEvent($circuitId, 'half_opened', $nodeId, $operationType);
                        return true;
                    }
                    
                    $this->logEvent($circuitId, 'request_rejected', $nodeId, $operationType);
                    return false;

                case self::STATE_HALF_OPEN:
                    // Allow limited requests to test if service recovered
                    $this->logEvent($circuitId, 'request_allowed', $nodeId, $operationType);
                    return true;

                default:
                    return false;
            }

        } catch (Exception $e) {
            $this->logger->error("Circuit breaker check failed for $circuitId: " . $e->getMessage());
            return true; // Fail open to prevent system lockup
        }
    }

    /**
     * Record successful request
     */
    public function recordSuccess(string $nodeId, string $operationType = 'default', float $responseTime = 0): void
    {
        $circuitId = $this->getCircuitId($nodeId, $operationType);
        
        try {
            $circuit = $this->getCircuitState($circuitId);
            
            if (!$circuit) {
                return; // Circuit doesn't exist yet
            }

            $this->pdo->beginTransaction();

            switch ($circuit['state']) {
                case self::STATE_CLOSED:
                    // Reset failure count on success
                    $this->resetFailureCount($circuitId);
                    break;

                case self::STATE_HALF_OPEN:
                    $newSuccessCount = $circuit['success_count'] + 1;
                    
                    // Check if we have enough successes to close circuit
                    if ($newSuccessCount >= $this->config['success_threshold']) {
                        $this->transitionToClosed($circuitId);
                        $this->logEvent($circuitId, 'closed', $nodeId, $operationType);
                        $this->logger->info("Circuit breaker closed for $nodeId ($operationType)");
                    } else {
                        $this->incrementSuccessCount($circuitId);
                    }
                    break;
            }

            $this->updateRequestStats($circuitId, true);
            $this->pdo->commit();

        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->logger->error("Failed to record success for $circuitId: " . $e->getMessage());
        }
    }

    /**
     * Record failed request
     */
    public function recordFailure(string $nodeId, string $operationType = 'default', string $errorMessage = ''): void
    {
        $circuitId = $this->getCircuitId($nodeId, $operationType);
        
        try {
            $circuit = $this->getCircuitState($circuitId);
            
            if (!$circuit) {
                $this->initializeCircuit($circuitId, $nodeId, $operationType);
                $circuit = $this->getCircuitState($circuitId);
            }

            $this->pdo->beginTransaction();

            switch ($circuit['state']) {
                case self::STATE_CLOSED:
                    $newFailureCount = $circuit['failure_count'] + 1;
                    
                    // Check if we should open the circuit
                    if ($this->shouldOpenCircuit($circuit, $newFailureCount)) {
                        $this->transitionToOpen($circuitId, $errorMessage);
                        $this->logEvent($circuitId, 'opened', $nodeId, $operationType, $newFailureCount, 0, $errorMessage);
                        $this->logger->warning("Circuit breaker opened for $nodeId ($operationType) - failures: $newFailureCount");
                    } else {
                        $this->incrementFailureCount($circuitId, $errorMessage);
                    }
                    break;

                case self::STATE_HALF_OPEN:
                    // Failure in half-open state -> back to open
                    $this->transitionToOpen($circuitId, $errorMessage);
                    $this->logEvent($circuitId, 'opened', $nodeId, $operationType, 0, 0, $errorMessage);
                    $this->logger->warning("Circuit breaker re-opened for $nodeId ($operationType)");
                    break;
            }

            $this->updateRequestStats($circuitId, false);
            $this->pdo->commit();

        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->logger->error("Failed to record failure for $circuitId: " . $e->getMessage());
        }
    }

    /**
     * Get circuit state
     */
    private function getCircuitState(string $circuitId): ?array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM circuit_breaker_state WHERE id = ?
            ");
            $stmt->execute([$circuitId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            $this->logger->error("Failed to get circuit state: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Initialize new circuit
     */
    private function initializeCircuit(string $circuitId, string $nodeId, string $operationType): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO circuit_breaker_state 
                (id, node_id, operation_type, state, failure_count, success_count) 
                VALUES (?, ?, ?, 'closed', 0, 0)
            ");
            $stmt->execute([$circuitId, $nodeId, $operationType]);
        } catch (Exception $e) {
            $this->logger->error("Failed to initialize circuit: " . $e->getMessage());
        }
    }

    /**
     * Check if circuit should be opened
     */
    private function shouldOpenCircuit(array $circuit, int $newFailureCount): bool
    {
        // Check failure count threshold
        if ($newFailureCount >= $this->config['failure_threshold']) {
            return true;
        }

        // Check error percentage threshold (if we have enough requests)
        $totalRequests = $circuit['total_requests'] + 1; // +1 for current request
        if ($totalRequests >= $this->config['request_volume_threshold']) {
            $failedRequests = $circuit['failed_requests'] + 1; // +1 for current failure
            $errorPercentage = ($failedRequests / $totalRequests) * 100;
            
            if ($errorPercentage >= $this->config['error_percentage_threshold']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if circuit should attempt reset (transition to half-open)
     */
    private function shouldAttemptReset(array $circuit): bool
    {
        if (!$circuit['next_attempt_time']) {
            return true;
        }
        
        return strtotime($circuit['next_attempt_time']) <= time();
    }

    /**
     * Transition circuit to open state
     */
    private function transitionToOpen(string $circuitId, string $errorMessage = ''): void
    {
        $nextAttemptTime = date('Y-m-d H:i:s', time() + $this->config['timeout']);
        
        try {
            $stmt = $this->pdo->prepare("
                UPDATE circuit_breaker_state 
                SET state = 'open', 
                    last_failure_time = NOW(),
                    state_changed_at = NOW(),
                    next_attempt_time = ?,
                    failure_count = failure_count + 1,
                    success_count = 0
                WHERE id = ?
            ");
            $stmt->execute([$nextAttemptTime, $circuitId]);
        } catch (Exception $e) {
            $this->logger->error("Failed to transition to open: " . $e->getMessage());
        }
    }

    /**
     * Transition circuit to half-open state
     */
    private function transitionToHalfOpen(string $circuitId): void
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE circuit_breaker_state 
                SET state = 'half_open', 
                    state_changed_at = NOW(),
                    success_count = 0
                WHERE id = ?
            ");
            $stmt->execute([$circuitId]);
        } catch (Exception $e) {
            $this->logger->error("Failed to transition to half-open: " . $e->getMessage());
        }
    }

    /**
     * Transition circuit to closed state
     */
    private function transitionToClosed(string $circuitId): void
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE circuit_breaker_state 
                SET state = 'closed', 
                    failure_count = 0,
                    success_count = 0,
                    last_success_time = NOW(),
                    state_changed_at = NOW(),
                    next_attempt_time = NULL,
                    total_requests = 0,
                    failed_requests = 0
                WHERE id = ?
            ");
            $stmt->execute([$circuitId]);
        } catch (Exception $e) {
            $this->logger->error("Failed to transition to closed: " . $e->getMessage());
        }
    }

    /**
     * Increment failure count
     */
    private function incrementFailureCount(string $circuitId, string $errorMessage = ''): void
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE circuit_breaker_state 
                SET failure_count = failure_count + 1,
                    last_failure_time = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$circuitId]);
        } catch (Exception $e) {
            $this->logger->error("Failed to increment failure count: " . $e->getMessage());
        }
    }

    /**
     * Increment success count
     */
    private function incrementSuccessCount(string $circuitId): void
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE circuit_breaker_state 
                SET success_count = success_count + 1,
                    last_success_time = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$circuitId]);
        } catch (Exception $e) {
            $this->logger->error("Failed to increment success count: " . $e->getMessage());
        }
    }

    /**
     * Reset failure count
     */
    private function resetFailureCount(string $circuitId): void
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE circuit_breaker_state 
                SET failure_count = 0,
                    last_success_time = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$circuitId]);
        } catch (Exception $e) {
            $this->logger->error("Failed to reset failure count: " . $e->getMessage());
        }
    }

    /**
     * Update request statistics
     */
    private function updateRequestStats(string $circuitId, bool $success): void
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE circuit_breaker_state 
                SET total_requests = total_requests + 1,
                    failed_requests = failed_requests + ?
                WHERE id = ?
            ");
            $stmt->execute([$success ? 0 : 1, $circuitId]);
        } catch (Exception $e) {
            $this->logger->error("Failed to update request stats: " . $e->getMessage());
        }
    }

    /**
     * Log circuit breaker event
     */
    private function logEvent(string $circuitId, string $eventType, string $nodeId, string $operationType, int $failureCount = 0, int $successCount = 0, string $errorMessage = ''): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO circuit_breaker_events 
                (circuit_id, event_type, node_id, operation_type, failure_count, success_count, error_message) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$circuitId, $eventType, $nodeId, $operationType, $failureCount, $successCount, $errorMessage]);
        } catch (Exception $e) {
            $this->logger->error("Failed to log circuit breaker event: " . $e->getMessage());
        }
    }

    /**
     * Get circuit ID
     */
    private function getCircuitId(string $nodeId, string $operationType): string
    {
        return $nodeId . '_' . $operationType;
    }

    /**
     * Get circuit breaker statistics
     */
    public function getCircuitStats(): array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    state,
                    COUNT(*) as count,
                    AVG(failure_count) as avg_failures,
                    AVG(success_count) as avg_successes
                FROM circuit_breaker_state 
                GROUP BY state
            ");
            $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($stats as $stat) {
                $result[$stat['state']] = [
                    'count' => (int)$stat['count'],
                    'avg_failures' => round((float)$stat['avg_failures'], 2),
                    'avg_successes' => round((float)$stat['avg_successes'], 2)
                ];
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Failed to get circuit stats: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get circuits by state
     */
    public function getCircuitsByState(string $state): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    node_id,
                    operation_type,
                    state,
                    failure_count,
                    success_count,
                    last_failure_time,
                    last_success_time,
                    state_changed_at,
                    next_attempt_time
                FROM circuit_breaker_state 
                WHERE state = ?
                ORDER BY state_changed_at DESC
            ");
            $stmt->execute([$state]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $this->logger->error('Failed to get circuits by state: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Force reset circuit (admin function)
     */
    public function resetCircuit(string $nodeId, string $operationType = 'default'): bool
    {
        $circuitId = $this->getCircuitId($nodeId, $operationType);
        
        try {
            $this->transitionToClosed($circuitId);
            $this->logEvent($circuitId, 'closed', $nodeId, $operationType);
            $this->logger->info("Circuit breaker manually reset for $nodeId ($operationType)");
            return true;
        } catch (Exception $e) {
            $this->logger->error("Failed to reset circuit: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clean up old events
     */
    public function cleanup(): int
    {
        try {
            $deletedCount = 0;

            // Remove old events (older than 30 days)
            $stmt = $this->pdo->prepare("
                DELETE FROM circuit_breaker_events 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute();
            $deletedCount += $stmt->rowCount();

            // Reset old closed circuits (older than 7 days with no activity)
            $stmt = $this->pdo->prepare("
                UPDATE circuit_breaker_state 
                SET total_requests = 0, failed_requests = 0 
                WHERE state = 'closed' 
                AND state_changed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute();
            $deletedCount += $stmt->rowCount();

            if ($deletedCount > 0) {
                $this->logger->info("Cleaned up $deletedCount circuit breaker records");
            }

            return $deletedCount;
        } catch (Exception $e) {
            $this->logger->error('Failed to cleanup circuit breaker: ' . $e->getMessage());
            return 0;
        }
    }
}

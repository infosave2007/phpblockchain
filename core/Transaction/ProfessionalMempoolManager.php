<?php
declare(strict_types=1);

namespace Blockchain\Core\Transaction;

use PDO;
use Exception;

/**
 * Professional Mempool Manager for Consensus-Critical Operations
 * Handles pending transactions for blockchain consensus synchronization
 */
class ProfessionalMempoolManager
{
    private PDO $database;
    private string $nodeId;
    private int $maxMempoolSize;
    private int $defaultExpireTime;
    
    public function __construct(PDO $database, string $nodeId, array $config = [])
    {
        $this->database = $database;
        $this->nodeId = $nodeId;
        $this->maxMempoolSize = $config['max_mempool_size'] ?? 10000;
        $this->defaultExpireTime = $config['default_expire_time'] ?? 3600; // 1 hour
    }
    
    /**
     * Add transaction to mempool with consensus validation
     */
    public function addTransaction(array $transaction): bool
    {
        try {
            // Validate transaction format
            if (!$this->validateTransaction($transaction)) {
                throw new Exception("Invalid transaction format");
            }
            
            // Check if transaction already exists
            if ($this->transactionExists($transaction['hash'])) {
                return false; // Already in mempool
            }
            
            // Check mempool size limit
            if ($this->getMempoolSize() >= $this->maxMempoolSize) {
                $this->cleanupLowPriorityTransactions();
            }
            
            // Calculate priority score for consensus ordering
            $priorityScore = $this->calculatePriority($transaction);
            
            // Calculate expiry time
            $expiresAt = date('Y-m-d H:i:s', time() + $this->defaultExpireTime);
            
            // Insert transaction
            $sql = "INSERT INTO mempool (
                tx_hash, from_address, to_address, amount, fee,
                gas_price, gas_limit, nonce, data, signature,
                priority_score, expires_at, node_id, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
            
            $stmt = $this->database->prepare($sql);
            $result = $stmt->execute([
                $transaction['hash'],
                $transaction['from'],
                $transaction['to'],
                $transaction['amount'],
                $transaction['fee'],
                $transaction['gas_price'] ?? 0,
                $transaction['gas_limit'] ?? 21000,
                $transaction['nonce'],
                $transaction['data'] ?? '',
                $transaction['signature'],
                $priorityScore,
                $expiresAt,
                $this->nodeId
            ]);
            
            if ($result) {
                $this->logTransaction('added', $transaction['hash']);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Failed to add transaction to mempool: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get transactions for block proposal (consensus-ordered)
     */
    public function getTransactionsForBlock(int $maxCount = 1000): array
    {
        $sql = "SELECT * FROM mempool 
                WHERE status = 'pending' 
                  AND expires_at > NOW()
                ORDER BY priority_score DESC, fee DESC, created_at ASC
                LIMIT ?";
        
        $stmt = $this->database->prepare($sql);
        $stmt->execute([$maxCount]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get pending transactions by address
     */
    public function getTransactionsByAddress(string $address): array
    {
        $sql = "SELECT * FROM mempool 
                WHERE (from_address = ? OR to_address = ?)
                  AND status = 'pending'
                ORDER BY nonce ASC, created_at DESC";
        
        $stmt = $this->database->prepare($sql);
        $stmt->execute([$address, $address]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Remove transactions when included in block
     */
    public function removeTransactions(array $txHashes): bool
    {
        if (empty($txHashes)) {
            return true;
        }
        
        $placeholders = str_repeat('?,', count($txHashes) - 1) . '?';
        $sql = "DELETE FROM mempool WHERE tx_hash IN ($placeholders)";
        
        $stmt = $this->database->prepare($sql);
        $result = $stmt->execute($txHashes);
        
        foreach ($txHashes as $hash) {
            $this->logTransaction('removed', $hash);
        }
        
        return $result;
    }
    
    /**
     * Check for double-spend attempts (consensus-critical)
     */
    public function checkDoubleSpend(): array
    {
        $sql = "SELECT from_address, nonce, COUNT(*) as count, 
                       GROUP_CONCAT(tx_hash) as tx_hashes
                FROM mempool 
                WHERE status = 'pending'
                GROUP BY from_address, nonce
                HAVING COUNT(*) > 1";
        
        $stmt = $this->database->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get mempool statistics for network monitoring
     */
    public function getStatistics(): array
    {
        $stats = [];
        
        // Total transactions by status
        $sql = "SELECT status, COUNT(*) as count FROM mempool GROUP BY status";
        $stmt = $this->database->prepare($sql);
        $stmt->execute();
        $stats['by_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Financial metrics
        $sql = "SELECT 
                    COUNT(*) as total_pending,
                    SUM(amount) as total_value,
                    SUM(fee) as total_fees,
                    AVG(fee) as avg_fee,
                    AVG(priority_score) as avg_priority,
                    MIN(created_at) as oldest_tx,
                    MAX(created_at) as newest_tx
                FROM mempool 
                WHERE status = 'pending'";
        $stmt = $this->database->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stats['pending'] = $result;
        $stats['node_id'] = $this->nodeId;
        $stats['timestamp'] = date('Y-m-d H:i:s');
        
        return $stats;
    }
    
    /**
     * Export mempool data for consensus synchronization
     */
    public function exportForConsensus(): array
    {
        $sql = "SELECT tx_hash, from_address, to_address, amount, fee, 
                       gas_price, gas_limit, nonce, data, signature,
                       priority_score, created_at, expires_at
                FROM mempool 
                WHERE status = 'pending' 
                ORDER BY priority_score DESC, created_at ASC";
        
        $stmt = $this->database->prepare($sql);
        $stmt->execute();
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'metadata' => [
                'timestamp' => time(),
                'node_id' => $this->nodeId,
                'transaction_count' => count($transactions),
                'export_type' => 'consensus_sync'
            ],
            'transactions' => $transactions,
            'statistics' => $this->getStatistics()
        ];
    }
    
    /**
     * Import mempool data for consensus synchronization
     */
    public function importForConsensus(array $data): bool
    {
        $this->database->beginTransaction();
        
        try {
            // Clear existing pending transactions
            $this->database->exec("DELETE FROM mempool WHERE status = 'pending'");
            
            // Import transactions with validation
            $imported = 0;
            foreach ($data['transactions'] as $tx) {
                if ($this->importSingleTransaction($tx)) {
                    $imported++;
                }
            }
            
            $this->database->commit();
            
            error_log("Mempool consensus sync: imported {$imported}/{$data['metadata']['transaction_count']} transactions from node {$data['metadata']['node_id']}");
            return true;
            
        } catch (Exception $e) {
            $this->database->rollback();
            error_log("Failed to import mempool for consensus: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Cleanup expired and invalid transactions
     */
    public function consensusCleanup(): array
    {
        $stats = ['expired' => 0, 'old' => 0, 'failed' => 0, 'invalid_nonce' => 0];
        
        // Remove expired transactions
        $sql = "DELETE FROM mempool WHERE expires_at < NOW()";
        $stmt = $this->database->prepare($sql);
        $stmt->execute();
        $stats['expired'] = $stmt->rowCount();
        
        // Remove very old transactions (24+ hours)
        $sql = "DELETE FROM mempool WHERE created_at < NOW() - INTERVAL 24 HOUR";
        $stmt = $this->database->prepare($sql);
        $stmt->execute();
        $stats['old'] = $stmt->rowCount();
        
        // Remove failed transactions with too many retries
        $sql = "DELETE FROM mempool WHERE retry_count > 5";
        $stmt = $this->database->prepare($sql);
        $stmt->execute();
        $stats['failed'] = $stmt->rowCount();
        
        return $stats;
    }
    
    /**
     * Validate mempool for consensus integrity
     */
    public function validateConsensusIntegrity(): array
    {
        $issues = [];
        
        // Check for double-spend attempts
        $doubleSpends = $this->checkDoubleSpend();
        if (!empty($doubleSpends)) {
            $issues[] = count($doubleSpends) . " double-spend attempts detected";
        }
        
        // Check for invalid nonces
        $sql = "SELECT from_address, MIN(nonce) as min_nonce, MAX(nonce) as max_nonce, COUNT(*) as count
                FROM mempool 
                WHERE status = 'pending'
                GROUP BY from_address
                HAVING max_nonce - min_nonce + 1 != count";
        
        $stmt = $this->database->prepare($sql);
        $stmt->execute();
        $nonceGaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($nonceGaps)) {
            $issues[] = count($nonceGaps) . " addresses with nonce gaps";
        }
        
        // Check for transactions without proper fees
        $sql = "SELECT COUNT(*) FROM mempool WHERE fee <= 0 AND status = 'pending'";
        $stmt = $this->database->prepare($sql);
        $stmt->execute();
        $zeroFee = $stmt->fetchColumn();
        
        if ($zeroFee > 0) {
            $issues[] = "{$zeroFee} transactions with zero or negative fees";
        }
        
        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'double_spends' => $doubleSpends,
            'nonce_gaps' => $nonceGaps,
            'zero_fee_count' => $zeroFee
        ];
    }
    
    // Private helper methods
    
    private function validateTransaction(array $transaction): bool
    {
        $required = ['hash', 'from', 'to', 'amount', 'fee', 'nonce', 'signature'];
        
        foreach ($required as $field) {
            if (!isset($transaction[$field])) {
                return false;
            }
        }
        
        // Validate hash format
        if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $transaction['hash'])) {
            return false;
        }
        
        // Validate address formats
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $transaction['from']) ||
            !preg_match('/^0x[a-fA-F0-9]{40}$/', $transaction['to'])) {
            return false;
        }
        
        // Validate amounts
        if (floatval($transaction['amount']) < 0 || floatval($transaction['fee']) < 0) {
            return false;
        }
        
        return true;
    }
    
    private function transactionExists(string $txHash): bool
    {
        $h = strtolower(trim($txHash));
        $h0 = str_starts_with($h,'0x') ? $h : ('0x'.$h);
        $h1 = str_starts_with($h,'0x') ? substr($h,2) : $h;
        $sql = "SELECT COUNT(*) FROM mempool WHERE tx_hash = ? OR tx_hash = ?";
        $stmt = $this->database->prepare($sql);
        $stmt->execute([$h0, $h1]);
        
        return $stmt->fetchColumn() > 0;
    }
    
    private function getMempoolSize(): int
    {
        $sql = "SELECT COUNT(*) FROM mempool WHERE status = 'pending'";
        $stmt = $this->database->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchColumn();
    }
    
    private function calculatePriority(array $transaction): int
    {
        $baseScore = 1000;
        
        // Fee-based scoring (higher fees = higher priority)
        $feeScore = min(floatval($transaction['fee']) * 100, 500);
        
        // Gas price consideration (for smart contracts)
        $gasScore = min(floatval($transaction['gas_price'] ?? 0) * 10, 300);
        
        // Amount consideration (slight priority for higher amounts)
        $amountScore = min(floatval($transaction['amount']) / 1000, 100);
        
        return (int)($baseScore + $feeScore + $gasScore + $amountScore);
    }
    
    private function cleanupLowPriorityTransactions(): void
    {
        // Remove lowest priority transactions to make room
        $sql = "DELETE FROM mempool 
                WHERE status = 'pending'
                ORDER BY priority_score ASC, created_at ASC
                LIMIT 100";
        
        $stmt = $this->database->prepare($sql);
        $stmt->execute();
    }
    
    private function importSingleTransaction(array $tx): bool
    {
        $sql = "INSERT INTO mempool (
            tx_hash, from_address, to_address, amount, fee,
            gas_price, gas_limit, nonce, data, signature,
            priority_score, created_at, expires_at, status, node_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)";
        
        $stmt = $this->database->prepare($sql);
        $h = strtolower(trim((string)$tx['tx_hash']));
        $h0 = str_starts_with($h,'0x') ? $h : ('0x'.$h);
        return $stmt->execute([
            $h0,
            $tx['from_address'],
            $tx['to_address'],
            $tx['amount'],
            $tx['fee'],
            $tx['gas_price'] ?? 0,
            $tx['gas_limit'] ?? 21000,
            $tx['nonce'],
            $tx['data'] ?? '',
            $tx['signature'],
            $tx['priority_score'],
            $tx['created_at'],
            $tx['expires_at'],
            $this->nodeId
        ]);
    }
    
    private function logTransaction(string $action, string $txHash): void
    {
        error_log("Mempool {$action}: {$txHash} (node: {$this->nodeId})");
    }
}

<?php
declare(strict_types=1);

namespace Blockchain\Core\Transaction;

use Blockchain\Core\Transaction\Transaction;
use Blockchain\Core\Transaction\FeePolicy;
use PDO;
use Exception;

/**
 * Mempool Manager
 * 
 * Manages pending transactions before they are included in blocks
 */
class MempoolManager
{
    private PDO $database;
    private array $config;
    private int $maxSize;
    private float $minFee;
    
    public function __construct(PDO $database, array $config = [])
    {
        $this->database = $database;
        $this->config = $config;
        $this->maxSize = $config['max_size'] ?? 10000;
        try {
            $rate = FeePolicy::getRate($database);
        } catch (\Throwable $e) {
            $rate = 0.001;
        }
        if ($rate <= 0) {
            $this->minFee = 0.0;
        } else {
            $this->minFee = $config['min_fee'] ?? $rate;
        }
    }
    
    /**
     * Add transaction to mempool
     */
    public function addTransaction(Transaction $transaction): bool
    {
        try {
            // Validate transaction
            if (!$transaction->isValid()) {
                throw new Exception("Invalid transaction");
            }
            
            // Check if transaction already exists
            if ($this->hasTransaction($transaction->getHash())) {
                throw new Exception("Transaction already in mempool");
            }
            
            // Check minimum fee
            if ($transaction->getFee() < $this->minFee) {
                throw new Exception("Transaction fee too low");
            }
            
            // Check mempool size limit
            if ($this->getSize() >= $this->maxSize) {
                // Remove lowest priority transaction
                $this->evictLowestPriority();
            }
            
            // Insert into database
            $stmt = $this->database->prepare("
                INSERT INTO mempool (
                    tx_hash,
                    from_address,
                    to_address,
                    amount,
                    fee,
                    gas_price,
                    gas_limit,
                    nonce,
                    data,
                    signature,
                    priority_score
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            return $stmt->execute([
                $transaction->getHash(),
                $transaction->getFromAddress(),
                $transaction->getToAddress(),
                $transaction->getAmount(),
                $transaction->getFee(),
                $transaction->getGasPrice(),
                $transaction->getGasLimit(),
                $transaction->getNonce(),
                $transaction->getData(),
                $transaction->getSignature(),
                $transaction->getPriorityScore()
            ]);
            
        } catch (Exception $e) {
            error_log("Failed to add transaction to mempool: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Remove transaction from mempool
     */
    public function removeTransaction(string $txHash): bool
    {
        $stmt = $this->database->prepare("DELETE FROM mempool WHERE tx_hash = ?");
        return $stmt->execute([$txHash]);
    }
    
    /**
     * Get transaction from mempool
     */
    public function getTransaction(string $txHash): ?Transaction
    {
        $stmt = $this->database->prepare("
            SELECT * FROM mempool WHERE tx_hash = ?
        ");
        
        $stmt->execute([$txHash]);
        $data = $stmt->fetch();
        
        if (!$data) {
            return null;
        }
        
        return $this->arrayToTransaction($data);
    }
    
    /**
     * Check if transaction exists in mempool
     */
    public function hasTransaction(string $txHash): bool
    {
        $stmt = $this->database->prepare("
            SELECT COUNT(*) FROM mempool WHERE tx_hash = ?
        ");
        
        $stmt->execute([$txHash]);
        return (int)$stmt->fetchColumn() > 0;
    }
    
    /**
     * Get transactions for block creation
     */
    public function getTransactionsForBlock(int $maxCount = 1000, int $maxGas = 8000000): array
    {
        $stmt = $this->database->prepare("
            SELECT * FROM mempool 
            ORDER BY priority_score DESC, created_at ASC 
            LIMIT ?
        ");
        
        $stmt->execute([$maxCount]);
        $transactions = [];
        $totalGas = 0;
        
        while ($row = $stmt->fetch()) {
            $transaction = $this->arrayToTransaction($row);
            
            // Check gas limit
            if ($totalGas + $transaction->getGasLimit() > $maxGas) {
                break;
            }
            
            $transactions[] = $transaction;
            $totalGas += $transaction->getGasLimit();
        }
        
        return $transactions;
    }
    
    /**
     * Get pending transactions by address
     */
    public function getPendingTransactions(string $address): array
    {
        $stmt = $this->database->prepare("
            SELECT * FROM mempool 
            WHERE from_address = ? OR to_address = ?
            ORDER BY nonce ASC
        ");
        
        $stmt->execute([$address, $address]);
        $transactions = [];
        
        while ($row = $stmt->fetch()) {
            $transactions[] = $this->arrayToTransaction($row);
        }
        
        return $transactions;
    }
    
    /**
     * Get mempool statistics
     */
    public function getStats(): array
    {
        $stmt = $this->database->query("
            SELECT 
                COUNT(*) as total_transactions,
                AVG(fee) as average_fee,
                MAX(fee) as max_fee,
                MIN(fee) as min_fee,
                SUM(amount) as total_amount,
                AVG(priority_score) as average_priority
            FROM mempool
        ");
        
        $stats = $stmt->fetch();
        
        return [
            'total_transactions' => (int)$stats['total_transactions'],
            'average_fee' => (float)$stats['average_fee'],
            'max_fee' => (float)$stats['max_fee'],
            'min_fee' => (float)$stats['min_fee'],
            'total_amount' => (float)$stats['total_amount'],
            'average_priority' => (float)$stats['average_priority'],
            'size_bytes' => $this->getSizeInBytes()
        ];
    }
    
    /**
     * Get mempool size (number of transactions)
     */
    public function getSize(): int
    {
        $stmt = $this->database->query("SELECT COUNT(*) FROM mempool");
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Get mempool size in bytes
     */
    public function getSizeInBytes(): int
    {
        $stmt = $this->database->query("
            SELECT SUM(LENGTH(JSON_OBJECT(
                'hash', tx_hash,
                'from', from_address,
                'to', to_address,
                'amount', amount,
                'fee', fee,
                'data', data,
                'signature', signature
            ))) as total_size
            FROM mempool
        ");
        
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Clear old transactions
     */
    public function clearOldTransactions(int $maxAge = 3600): int
    {
        $stmt = $this->database->prepare("
            DELETE FROM mempool 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        
        $stmt->execute([$maxAge]);
        return $stmt->rowCount();
    }
    
    /**
     * Evict lowest priority transaction
     */
    private function evictLowestPriority(): void
    {
        $stmt = $this->database->prepare("
            DELETE FROM mempool 
            ORDER BY priority_score ASC, created_at ASC 
            LIMIT 1
        ");
        
        $stmt->execute();
    }
    
    /**
     * Validate transaction nonce
     */
    public function validateNonce(Transaction $transaction): bool
    {
        $fromAddress = $transaction->getFromAddress();
        $nonce = $transaction->getNonce();
        
        // Get current nonce from blockchain
        $stmt = $this->database->prepare("
            SELECT nonce FROM wallets WHERE address = ?
        ");
        
        $stmt->execute([$fromAddress]);
        $result = $stmt->fetch();
        $currentNonce = $result ? (int)$result['nonce'] : 0;
        
        // Check for nonce gaps in mempool
        $stmt = $this->database->prepare("
            SELECT nonce FROM mempool 
            WHERE from_address = ? AND nonce < ?
            ORDER BY nonce ASC
        ");
        
        $stmt->execute([$fromAddress, $nonce]);
        $pendingNonces = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Expected nonce should be current + 1 + pending count
        $expectedNonce = $currentNonce + count($pendingNonces) + 1;
        
        return $nonce === $expectedNonce;
    }
    
    /**
     * Replace transaction (RBF - Replace By Fee)
     */
    public function replaceTransaction(string $oldTxHash, Transaction $newTransaction): bool
    {
        try {
            // Get old transaction
            $oldTx = $this->getTransaction($oldTxHash);
            
            if (!$oldTx) {
                throw new Exception("Original transaction not found");
            }
            
            // Validate replacement
            if ($oldTx->getFromAddress() !== $newTransaction->getFromAddress()) {
                throw new Exception("Different sender address");
            }
            
            if ($oldTx->getNonce() !== $newTransaction->getNonce()) {
                throw new Exception("Different nonce");
            }
            
            if ($newTransaction->getFee() <= $oldTx->getFee()) {
                throw new Exception("New fee must be higher");
            }
            
            // Remove old transaction and add new one
            $this->database->beginTransaction();
            
            try {
                $this->removeTransaction($oldTxHash);
                $this->addTransaction($newTransaction);
                
                $this->database->commit();
                return true;
                
            } catch (Exception $e) {
                $this->database->rollback();
                throw $e;
            }
            
        } catch (Exception $e) {
            error_log("Failed to replace transaction: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Convert database row to Transaction object
     */
    private function arrayToTransaction(array $data): Transaction
    {
        $transaction = new Transaction(
            $data['from_address'],
            $data['to_address'],
            (float)$data['amount'],
            (float)$data['fee'],
            (int)$data['nonce'],
            $data['data'],
            (int)$data['gas_limit'],
            (float)$data['gas_price']
        );
        
        $transaction->setSignature($data['signature']);
        
        return $transaction;
    }
    
    /**
     * Clean up mempool
     */
    public function cleanup(): void
    {
        // Remove old transactions
        $this->clearOldTransactions();
        
        // Remove invalid transactions
        $this->removeInvalidTransactions();
    }
    
    /**
     * Remove invalid transactions
     */
    private function removeInvalidTransactions(): int
    {
        $stmt = $this->database->query("SELECT * FROM mempool");
        $removed = 0;
        
        while ($row = $stmt->fetch()) {
            $transaction = $this->arrayToTransaction($row);
            
            if (!$transaction->isValid()) {
                $this->removeTransaction($transaction->getHash());
                $removed++;
            }
        }
        
        return $removed;
    }
}

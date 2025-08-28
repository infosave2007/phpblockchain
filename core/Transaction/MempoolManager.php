<?php
declare(strict_types=1);

namespace Blockchain\Core\Transaction;

use Blockchain\Core\Transaction\Transaction;
use Blockchain\Core\Events\EventDispatcher;
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
    private ?EventDispatcher $eventDispatcher;
    
    /**
     * Normalize transaction hash to lowercase with 0x prefix if it's hex.
     * Does NOT modify non-hex strings. Used for inserts and comparisons.
     */
    private function normalizeHash(string $hash): string
    {
        $h = strtolower(trim($hash));
        if (str_starts_with($h, '0x')) {
            $h = substr($h, 2);
        }
        if (preg_match('/^[0-9a-f]{32,}$/', $h)) {
            return '0x' . $h;
        }
        return strtolower(trim($hash));
    }

    /**
     * Return both variants of a hash for DB lookups: [0x-prefixed, non-prefixed].
     * If not a hex hash, returns the original twice to keep SQL placeholders aligned.
     */
    private function hashVariants(string $hash): array
    {
        $norm = $this->normalizeHash($hash);
        $noPrefix = str_starts_with($norm, '0x') ? substr($norm, 2) : $norm;
        if (!preg_match('/^[0-9a-f]{32,}$/', $noPrefix)) {
            // Not a hex-like hash, return identical placeholders
            $orig = strtolower(trim($hash));
            return [$orig, $orig];
        }
        return [$norm, $noPrefix];
    }
    
    public function __construct(PDO $database, array $config = [], ?EventDispatcher $eventDispatcher = null)
    {
        $this->database = $database;
        $this->config = $config;
        $this->maxSize = $config['max_size'] ?? 10000;
        $this->minFee = $config['min_fee'] ?? 0.001;
        $this->eventDispatcher = $eventDispatcher;
    }
    
    /**
     * Add transaction to mempool (improved for network compatibility)
     */
    public function addTransaction(Transaction $transaction): bool
    {
        try {
            // Validate transaction
            if (!$transaction->isValid()) {
                throw new Exception("Invalid transaction");
            }

            // Check for zero/empty amount transactions to prevent spam
            if ($transaction->getAmount() <= 0) {
                throw new Exception("Empty transaction (zero amount not allowed)");
            }

            // Check if transaction already exists by hash
            if ($this->hasTransaction($transaction->getHash())) {
                // Not an error - transaction already exists
                return true;
            }
            
            // Check for duplicate by content (from, to, amount, nonce) - but be more lenient
            // Only reject if it's truly identical (same hash would be caught above)
            
            // Check minimum fee - be more lenient for network transactions
            if ($transaction->getFee() < ($this->minFee / 10)) { // Allow 10x lower fees for network sync
                throw new Exception("Transaction fee too low");
            }
            
            // Check mempool size limit
            if ($this->getSize() >= $this->maxSize) {
                // Remove lowest priority transaction
                $this->evictLowestPriority();
            }
            
            // Insert into database with all required fields
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
                    priority_score,
                    created_at,
                    expires_at,
                    status,
                    retry_count,
                    node_id,
                    broadcast_count
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR), 'pending', 0, NULL, 0)
            ");
            
            // Normalize hash on insert for consistency going forward
            $normHash = $this->normalizeHash($transaction->getHash());
            $success = $stmt->execute([
                $normHash,
                $transaction->getFromAddress(),
                $transaction->getToAddress(),
                $transaction->getAmount(),
                $transaction->getFee(),
                $transaction->getGasPrice() ?? 0,
                $transaction->getGasLimit() ?? 21000,
                $transaction->getNonce(),
                $transaction->getData() ?? '',
                $transaction->getSignature() ?? '',
                $this->calculatePriorityScore($transaction)
            ]);
            
            if (!$success) {
                $errorInfo = $stmt->errorInfo();
                throw new Exception("Database insert failed: " . ($errorInfo[2] ?? 'Unknown error'));
            }
            
            // Trigger mempool update event for automatic synchronization
            if ($this->eventDispatcher) {
                $this->eventDispatcher->dispatch('mempool.transaction.added', [
                    'transaction_hash' => $normHash,
                    'from_address' => $transaction->getFromAddress(),
                    'to_address' => $transaction->getToAddress(),
                    'amount' => $transaction->getAmount(),
                    'fee' => $transaction->getFee(),
                    'mempool_size' => $this->getSize(),
                    'timestamp' => time()
                ]);
            }
            
            return true;
            
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
        [$h0, $h1] = $this->hashVariants($txHash);
        $stmt = $this->database->prepare("DELETE FROM mempool WHERE tx_hash = ? OR tx_hash = ?");
        $result = $stmt->execute([$h0, $h1]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Trigger mempool update event for automatic synchronization
            if ($this->eventDispatcher) {
                $this->eventDispatcher->dispatch('mempool.transaction.removed', [
                    'transaction_hash' => $txHash,
                    'mempool_size' => $this->getSize(),
                    'timestamp' => time()
                ]);
            }
        }
        
        return $result;
    }
    
    /**
     * Get transaction from mempool
     */
    public function getTransaction(string $txHash): ?Transaction
    {
        [$h0, $h1] = $this->hashVariants($txHash);
        $stmt = $this->database->prepare("
            SELECT * FROM mempool WHERE tx_hash = ? OR tx_hash = ? LIMIT 1
        ");
        
        $stmt->execute([$h0, $h1]);
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
        [$h0, $h1] = $this->hashVariants($txHash);
        $stmt = $this->database->prepare("
            SELECT COUNT(*) FROM mempool WHERE tx_hash = ? OR tx_hash = ?
        ");
        
        $stmt->execute([$h0, $h1]);
        return (int)$stmt->fetchColumn() > 0;
    }
    
    /**
     * Check if similar transaction exists in mempool
     */
    public function hasSimilarTransaction(Transaction $transaction): bool
    {
        $stmt = $this->database->prepare("
            SELECT COUNT(*) FROM mempool 
            WHERE from_address = ? 
            AND to_address = ? 
            AND amount = ? 
            AND nonce = ?
        ");
        
        $stmt->execute([
            $transaction->getFromAddress(),
            $transaction->getToAddress(),
            $transaction->getAmount(),
            $transaction->getNonce()
        ]);
        
        return (int)$stmt->fetchColumn() > 0;
    }
    
    /**
     * Get transactions for block creation
     */
    public function getTransactionsForBlock(int $maxCount = 1000, int $maxGas = 8000000): array
    {
        $stmt = $this->database->prepare("
            SELECT * FROM mempool
            WHERE amount > 0.0
            ORDER BY priority_score DESC, created_at ASC
            LIMIT :max_count
        ");

        $stmt->bindParam(':max_count', $maxCount, PDO::PARAM_INT);
        $stmt->execute();
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
     * Calculate priority score for transaction
     */
    private function calculatePriorityScore(Transaction $transaction): int
    {
        // Base score from gas price
        $gasScore = (int)($transaction->getGasPrice() * 1000);
        
        // Fee score
        $feeScore = (int)($transaction->getFee() * 10000);
        
        // Age bonus (newer transactions get slight priority)
        $ageBonus = 1000;
        
        return $gasScore + $feeScore + $ageBonus;
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
        
        // Preserve original persisted hash
        if (isset($data['tx_hash'])) {
            $transaction->forceHash($data['tx_hash']);
        }
        
        // Set signature if present
        if (!empty($data['signature'])) {
            $transaction->setSignature($data['signature']);
        }
        
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

        // Remove empty transactions
        $this->removeEmptyTransactions();
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

    /**
     * Remove empty transactions (zero or negative amount)
     */
    public function removeEmptyTransactions(): int
    {
        $stmt = $this->database->prepare("
            DELETE FROM mempool WHERE amount <= 0
        ");

        $stmt->execute();
        return $stmt->rowCount();
    }
}

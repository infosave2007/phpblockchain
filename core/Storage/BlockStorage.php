<?php
declare(strict_types=1);

namespace Blockchain\Core\Storage;

use Blockchain\Core\Contracts\BlockInterface;

/**
 * Professional Block Storage
 */
class BlockStorage
{
    private array $blocks = [];
    private string $storageFile;
    private ?\PDO $database = null;
    
    public function __construct(string $storageFile = 'blockchain.json', ?\PDO $database = null)
    {
        $this->storageFile = $storageFile;
        $this->database = $database;
        $this->loadBlocks();
    }
    
    public function saveBlock(BlockInterface $block): bool
    {
        $this->blocks[$block->getIndex()] = $block;
        
        // Save to database if available
        if ($this->database) {
            $this->saveToDatabaseStorage($block);
        }
        
        return $this->saveToFile();
    }
    
    /**
     * Save block to database storage
     */
    public function saveToDatabaseStorage(BlockInterface $block): bool
    {
        if (!$this->database) {
            return false;
        }
        
        try {
            // Start transaction
            $this->database->beginTransaction();
            
            // Save block first
            $stmt = $this->database->prepare("
                INSERT INTO blocks (hash, parent_hash, height, timestamp, validator, signature, merkle_root, transactions_count, metadata) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                parent_hash = VALUES(parent_hash),
                height = VALUES(height),
                timestamp = VALUES(timestamp),
                validator = VALUES(validator),
                signature = VALUES(signature),
                merkle_root = VALUES(merkle_root),
                transactions_count = VALUES(transactions_count),
                metadata = VALUES(metadata)
            ");
            
            $metadata = json_encode([
                'genesis' => $block->getIndex() === 0,
                'difficulty' => $block->getDifficulty(),
                'nonce' => $block->getNonce()
            ]);
            
            $blockResult = $stmt->execute([
                $block->getHash(),
                $block->getPreviousHash(),
                $block->getIndex(),
                $block->getTimestamp(),
                '0x0000000000000000000000000000000000000000', // validator placeholder
                'block_signature',
                $block->getMerkleRoot(),
                count($block->getTransactions()),
                $metadata
            ]);
            
            if (!$blockResult) {
                throw new \Exception('Failed to save block');
            }
            
            // Process each transaction in the block
            foreach ($block->getTransactions() as $transaction) {
                $this->processTransaction($transaction, $block);
            }
            
            // Commit transaction
            $this->database->commit();
            return true;
            
        } catch (\PDOException $e) {
            $this->database->rollBack();
            return false;
        } catch (\Exception $e) {
            $this->database->rollBack();
            throw $e;
        }
    }
    
    /**
     * Process individual transaction and create related records
     */
    private function processTransaction(array $transaction, BlockInterface $block): void
    {
        // Save transaction to transactions table
        $stmt = $this->database->prepare("
            INSERT INTO transactions (hash, block_hash, block_height, from_address, to_address, amount, fee, gas_limit, gas_used, gas_price, nonce, data, signature, status, timestamp)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?)
            ON DUPLICATE KEY UPDATE status = 'confirmed'
        ");
        
        $stmt->execute([
            $transaction['hash'] ?? hash('sha256', json_encode($transaction)),
            $block->getHash(),
            $block->getIndex(),
            $transaction['from'] ?? 'unknown',
            $transaction['to'] ?? 'unknown',
            $transaction['amount'] ?? 0,
            $transaction['fee'] ?? 0,
            21000, // default gas limit
            0,     // gas used
            0.00001, // default gas price
            0,     // nonce
            json_encode($transaction['metadata'] ?? []),
            'system_signature',
            $transaction['timestamp'] ?? $block->getTimestamp()
        ]);
        
        // Process special transaction types
        $txType = $transaction['type'] ?? 'transfer';
        
        switch ($txType) {
            case 'stake':
                $this->processStakeTransaction($transaction, $block);
                break;
                
            case 'register_validator':
                $this->processValidatorRegistration($transaction, $block);
                break;
                
            case 'register_node':
                $this->processNodeRegistration($transaction, $block);
                break;
                
            case 'transfer':
            case 'genesis':
                // Update wallet balances
                $this->updateWalletBalance($transaction['to'], $transaction['amount']);
                break;
        }
    }
    
    /**
     * Process staking transaction
     */
    private function processStakeTransaction(array $transaction, BlockInterface $block): void
    {
        $metadata = $transaction['metadata'] ?? [];
        
        $stmt = $this->database->prepare("
            INSERT INTO staking (validator, staker, amount, status, start_block, created_at)
            VALUES (?, ?, ?, 'active', ?, NOW())
            ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)
        ");
        
        $stmt->execute([
            $metadata['validator'] ?? $transaction['from'],
            $transaction['from'],
            $transaction['amount'],
            $block->getIndex()
        ]);
    }
    
    /**
     * Process validator registration
     */
    private function processValidatorRegistration(array $transaction, BlockInterface $block): void
    {
        $metadata = $transaction['metadata'] ?? [];
        
        $stmt = $this->database->prepare("
            INSERT INTO validators (address, public_key, status, commission_rate, created_at)
            VALUES (?, ?, 'active', ?, NOW())
            ON DUPLICATE KEY UPDATE status = 'active', commission_rate = VALUES(commission_rate)
        ");
        
        $stmt->execute([
            $metadata['validator_address'] ?? $transaction['from'],
            'placeholder_public_key', // Will be updated with real public key
            $metadata['commission_rate'] ?? 0.1
        ]);
    }
    
    /**
     * Process node registration
     */
    private function processNodeRegistration(array $transaction, BlockInterface $block): void
    {
        $metadata = $transaction['metadata'] ?? [];
        
        // Generate node_id from transaction data
        $nodeId = hash('sha256', $transaction['from'] . ($metadata['node_domain'] ?? 'localhost') . time());
        
        $stmt = $this->database->prepare("
            INSERT INTO nodes (node_id, ip_address, port, public_key, version, status, metadata, created_at)
            VALUES (?, ?, ?, ?, ?, 'active', ?, NOW())
            ON DUPLICATE KEY UPDATE 
                status = 'active', 
                version = VALUES(version),
                metadata = VALUES(metadata),
                updated_at = NOW()
        ");
        
        // Extract IP from domain or use localhost
        $domain = $metadata['node_domain'] ?? 'localhost';
        $ip = ($domain === 'localhost') ? '127.0.0.1' : gethostbyname($domain);
        
        $nodeMetadata = json_encode([
            'node_type' => $metadata['node_type'] ?? 'primary',
            'domain' => $domain,
            'protocol' => $metadata['protocol'] ?? 'https',
            'wallet_address' => $transaction['from'],
            'genesis_node' => true
        ]);
        
        $stmt->execute([
            $nodeId,
            $ip,
            443, // HTTPS port
            $metadata['public_key'] ?? 'placeholder_public_key',
            $metadata['version'] ?? '1.0.0',
            $nodeMetadata
        ]);
    }
    
    /**
     * Update wallet balance
     */
    private function updateWalletBalance(string $address, float $amount): void
    {
        if ($address === 'unknown' || $address === 'genesis_address' || $address === 'staking_contract') {
            return; // Skip system addresses
        }
        
        $stmt = $this->database->prepare("
            INSERT INTO wallets (address, public_key, balance, created_at)
            VALUES (?, 'placeholder_public_key', ?, NOW())
            ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance), updated_at = NOW()
        ");
        
        $stmt->execute([$address, $amount]);
    }
    
    public function getBlock(int $index): ?BlockInterface
    {
        return $this->blocks[$index] ?? null;
    }
    
    /**
     * Get block by index (alias for getBlock)
     */
    public function getBlockByIndex(int $index): ?BlockInterface
    {
        return $this->getBlock($index);
    }
    
    /**
     * Get block by hash
     */
    public function getBlockByHash(string $hash): ?BlockInterface
    {
        foreach ($this->blocks as $block) {
            if ($block instanceof BlockInterface && $block->getHash() === $hash) {
                return $block;
            }
        }
        return null;
    }
    
    public function getLatestBlock(): ?BlockInterface
    {
        if (empty($this->blocks)) {
            return null;
        }
        
        $maxIndex = max(array_keys($this->blocks));
        return $this->blocks[$maxIndex];
    }
    
    public function getBlockCount(): int
    {
        return count($this->blocks);
    }
    
    private function loadBlocks(): void
    {
        if (file_exists($this->storageFile)) {
            $data = json_decode(file_get_contents($this->storageFile), true);
            if (is_array($data)) {
                // For now, start with empty blocks array
                // In a full implementation, we'd deserialize blocks from JSON
                $this->blocks = [];
            }
        }
    }
    
    private function saveToFile(): bool
    {
        // For now, save simplified block data
        $blockData = [];
        foreach ($this->blocks as $index => $block) {
            if ($block instanceof BlockInterface) {
                $blockData[$index] = [
                    'index' => $block->getIndex(),
                    'hash' => $block->getHash(),
                    'previous_hash' => $block->getPreviousHash(),
                    'timestamp' => $block->getTimestamp(),
                    'transactions_count' => count($block->getTransactions())
                ];
            }
        }
        return file_put_contents($this->storageFile, json_encode($blockData, JSON_PRETTY_PRINT)) !== false;
    }
}

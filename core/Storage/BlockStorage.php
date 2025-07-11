<?php
declare(strict_types=1);

namespace Blockchain\Core\Storage;

use Blockchain\Core\Contracts\BlockInterface;
use Blockchain\Core\Consensus\ValidatorManager;

// Подключаем WalletLogger для логирования
require_once dirname(__DIR__, 2) . '/wallet/WalletLogger.php';

/**
 * Professional Block Storage with ValidatorManager integration
 */
class BlockStorage
{
    private array $blocks = [];
    private string $storageFile;
    private ?\PDO $database = null;
    private ?ValidatorManager $validatorManager = null;
    
    public function __construct(string $storageFile = 'blockchain.json', ?\PDO $database = null, ?ValidatorManager $validatorManager = null)
    {
        $this->storageFile = $storageFile;
        $this->database = $database;
        $this->validatorManager = $validatorManager;
        $this->loadBlocks();
    }
    
    /**
     * Set ValidatorManager for centralized validator operations
     */
    public function setValidatorManager(ValidatorManager $validatorManager): void
    {
        $this->validatorManager = $validatorManager;
    }
    
    /**
     * Write log using WalletLogger
     */
    private function writeLog(string $message, string $level = 'INFO'): void
    {
        \WalletLogger::log($message, $level);
    }
    
    public function saveBlock(BlockInterface $block): bool
    {
        // Cast to concrete Block class to access getIndex method
        if ($block instanceof \Blockchain\Core\Blockchain\Block) {
            $this->blocks[$block->getIndex()] = $block;
        }
        
        // Save to database if available
        if ($this->database) {
            $this->saveToDatabaseStorage($block);
        }
        
        return $this->saveToFile();
    }
    
    /**
     * Save block to database storage using ValidatorManager
     */
    public function saveToDatabaseStorage(BlockInterface $block): bool
    {
        if (!$this->database) {
            $this->writeLog("BlockStorage::saveToDatabaseStorage - No database connection", 'ERROR');
            return false;
        }
        
        // Cast to concrete Block class for access to all methods
        if (!($block instanceof \Blockchain\Core\Blockchain\Block)) {
            $this->writeLog("BlockStorage::saveToDatabaseStorage - Block is not instance of Block class", 'ERROR');
            return false;
        }
        
        try {
            $this->writeLog("BlockStorage::saveToDatabaseStorage - Starting to save block " . $block->getHash(), 'DEBUG');
            // Start transaction
            $this->database->beginTransaction();
            
            // Get validator and signature using ValidatorManager
            $validatorAddress = '0x0000000000000000000000000000000000000000'; // fallback
            $blockSignature = 'fallback_signature';
            
            if ($this->validatorManager) {
                try {
                    $blockData = [
                        'hash' => $block->getHash(),
                        'index' => $block->getIndex(),
                        'timestamp' => $block->getTimestamp(),
                        'previous_hash' => $block->getPreviousHash(),
                        'merkle_root' => $block->getMerkleRoot(),
                        'transactions_count' => count($block->getTransactions())
                    ];
                    
                    $signatureData = $this->validatorManager->signBlock($blockData);
                    $validatorAddress = $signatureData['validator_address'];
                    $blockSignature = $signatureData['signature'];
                    
                } catch (\Exception $e) {
                    // Log error but continue with fallback values
                    $this->writeLog("ValidatorManager signing failed in BlockStorage: " . $e->getMessage(), 'WARNING');
                }
            }
            
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
                'nonce' => $block->getNonce(),
                'validator_manager_used' => $this->validatorManager !== null
            ]);
            
            $blockResult = $stmt->execute([
                $block->getHash(),
                $block->getPreviousHash(),
                $block->getIndex(),
                $block->getTimestamp(),
                $validatorAddress,
                $blockSignature,
                $block->getMerkleRoot(),
                count($block->getTransactions()),
                $metadata
            ]);
            
            $this->writeLog("BlockStorage::saveToDatabaseStorage - Block execute result: " . json_encode($blockResult), 'DEBUG');
            $this->writeLog("BlockStorage::saveToDatabaseStorage - Block affected rows: " . $stmt->rowCount(), 'DEBUG');
            
            if (!$blockResult) {
                $this->writeLog("BlockStorage::saveToDatabaseStorage - Failed to execute block insert", 'ERROR');
                throw new \Exception('Failed to save block');
            }
            
            // Process each transaction in the block
            $this->writeLog("BlockStorage::saveToDatabaseStorage - Processing " . count($block->getTransactions()) . " transactions", 'DEBUG');
            foreach ($block->getTransactions() as $index => $transaction) {
                $this->writeLog("BlockStorage::saveToDatabaseStorage - Processing transaction " . ($index + 1) . "/" . count($block->getTransactions()), 'DEBUG');
                try {
                    $this->processTransaction($transaction, $block);
                    $this->writeLog("BlockStorage::saveToDatabaseStorage - Transaction " . ($index + 1) . " processed successfully", 'DEBUG');
                } catch (\Exception $txError) {
                    $this->writeLog("BlockStorage::saveToDatabaseStorage - TRANSACTION ERROR in tx " . ($index + 1) . ": " . $txError->getMessage(), 'ERROR');
                    $this->writeLog("BlockStorage::saveToDatabaseStorage - Transaction data: " . json_encode($transaction), 'DEBUG');
                    throw $txError; // Re-throw to trigger rollback
                }
            }
            
            $this->writeLog("BlockStorage::saveToDatabaseStorage - All transactions processed, committing...", 'DEBUG');
            
            // Commit transaction
            $this->database->commit();
            $this->writeLog("BlockStorage::saveToDatabaseStorage - Block saved successfully: " . $block->getHash(), 'INFO');
            return true;
            
        } catch (\PDOException $e) {
            $this->database->rollBack();
            $this->writeLog("BlockStorage::saveToDatabaseStorage - PDO Exception: " . $e->getMessage(), 'ERROR');
            return false;
        } catch (\Exception $e) {
            $this->database->rollBack();
            $this->writeLog("BlockStorage::saveToDatabaseStorage - Exception: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    /**
     * Process individual transaction and create related records
     */
    private function processTransaction(array $transaction, BlockInterface $block): void
    {
        $this->writeLog("BlockStorage::processTransaction - Starting transaction processing", 'DEBUG');
        $this->writeLog("BlockStorage::processTransaction - Transaction type: " . ($transaction['type'] ?? 'transfer'), 'DEBUG');
        
        // Cast to concrete Block class for access to getIndex method
        if (!($block instanceof \Blockchain\Core\Blockchain\Block)) {
            throw new \Exception('Block must be instance of Block class');
        }
        
        try {
            // Save transaction to transactions table
            $this->writeLog("BlockStorage::processTransaction - Saving transaction to transactions table", 'DEBUG');
            $stmt = $this->database->prepare("
                INSERT INTO transactions (hash, block_hash, block_height, from_address, to_address, amount, fee, gas_limit, gas_used, gas_price, nonce, data, signature, status, timestamp)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?)
                ON DUPLICATE KEY UPDATE status = 'confirmed'
            ");
            
            $txResult = $stmt->execute([
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
            
            $this->writeLog("BlockStorage::processTransaction - Transaction save result: " . json_encode($txResult), 'DEBUG');
            
        } catch (\Exception $e) {
            $this->writeLog("BlockStorage::processTransaction - ERROR saving transaction: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
        
        // Process special transaction types
        $txType = $transaction['type'] ?? 'transfer';
        $this->writeLog("BlockStorage::processTransaction - Processing transaction type: $txType", 'DEBUG');
        
        try {
            switch ($txType) {
                case 'stake':
                    $this->writeLog("BlockStorage::processTransaction - Processing stake transaction", 'DEBUG');
                    // For staking: deduct from sender, process staking record
                    $this->updateWalletBalance($transaction['from'], -$transaction['amount']);
                    $this->processStakeTransaction($transaction, $block);
                    break;
                    
                case 'register_validator':
                    $this->writeLog("BlockStorage::processTransaction - Processing validator registration", 'DEBUG');
                    $this->processValidatorRegistration($transaction, $block);
                    break;
                    
                case 'register_node':
                    $this->writeLog("BlockStorage::processTransaction - Processing node registration", 'DEBUG');
                    $this->processNodeRegistration($transaction, $block);
                    break;
                    
                case 'genesis':
                    $this->writeLog("BlockStorage::processTransaction - Processing genesis transaction", 'DEBUG');
                    // For genesis: only add to receiver (no sender deduction)
                    $this->updateWalletBalance($transaction['to'], $transaction['amount']);
                    break;
                    
                case 'transfer':
                default:
                    $this->writeLog("BlockStorage::processTransaction - Processing transfer transaction", 'DEBUG');
                    // For transfer: deduct from sender, add to receiver
                    if ($transaction['from'] !== 'genesis' && $transaction['from'] !== 'genesis_address') {
                        $this->updateWalletBalance($transaction['from'], -($transaction['amount'] + ($transaction['fee'] ?? 0)));
                    }
                    $this->updateWalletBalance($transaction['to'], $transaction['amount']);
                    break;
            }
            
            $this->writeLog("BlockStorage::processTransaction - Transaction type $txType processed successfully", 'DEBUG');
            
        } catch (\Exception $e) {
            $this->writeLog("BlockStorage::processTransaction - ERROR processing transaction type $txType: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    /**
     * Process staking transaction
     */
    private function processStakeTransaction(array $transaction, BlockInterface $block): void
    {
        $metadata = $transaction['metadata'] ?? [];
        $validator = $metadata['validator'] ?? $transaction['from'];
        $staker = $transaction['from'];
        $amount = $transaction['amount'];
        
        // Use block height if available, otherwise use 0
        $blockHeight = 0;
        if (method_exists($block, 'getHeight')) {
            $blockHeight = $block->getHeight();
        } elseif (method_exists($block, 'getIndex')) {
            $blockHeight = $block->getIndex();
        }
        
        // Check if staking record already exists for this exact combination
        $stmt = $this->database->prepare("
            SELECT id FROM staking 
            WHERE validator = ? AND staker = ? AND amount = ? AND start_block = ?
        ");
        $stmt->execute([$validator, $staker, $amount, $blockHeight]);
        
        if (!$stmt->fetch()) {
            // Only insert if exact record doesn't exist
            $stmt = $this->database->prepare("
                INSERT INTO staking (validator, staker, amount, status, start_block, created_at)
                VALUES (?, ?, ?, 'active', ?, NOW())
            ");
            
            $stmt->execute([$validator, $staker, $amount, $blockHeight]);
        }
    }
    
    /**
     * Process validator registration
     */
    private function processValidatorRegistration(array $transaction, BlockInterface $block): void
    {
        $metadata = $transaction['metadata'] ?? [];
        $validatorAddress = $metadata['validator_address'] ?? $transaction['from'];
        
        // Try to get real public key from wallet or metadata
        $publicKey = 'placeholder_public_key';
        
        if (isset($metadata['public_key'])) {
            $publicKey = $metadata['public_key'];
        } else {
            // Try to get public key from wallets table
            $stmt = $this->database->prepare("SELECT public_key FROM wallets WHERE address = ?");
            $stmt->execute([$validatorAddress]);
            $wallet = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($wallet && $wallet['public_key'] !== 'placeholder_public_key') {
                $publicKey = $wallet['public_key'];
            }
        }
        
        $stmt = $this->database->prepare("
            INSERT INTO validators (address, public_key, status, commission_rate, created_at)
            VALUES (?, ?, 'active', ?, NOW())
            ON DUPLICATE KEY UPDATE 
                status = 'active', 
                commission_rate = VALUES(commission_rate),
                public_key = CASE 
                    WHEN public_key = 'placeholder_public_key' THEN VALUES(public_key)
                    ELSE public_key 
                END
        ");
        
        $stmt->execute([
            $validatorAddress,
            $publicKey,
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
            $this->writeLog("BlockStorage::updateWalletBalance - Skipping system address: $address", 'DEBUG');
            return; // Skip system addresses
        }
        
        $this->writeLog("BlockStorage::updateWalletBalance - Updating balance for $address by $amount", 'DEBUG');
        
        $stmt = $this->database->prepare("
            INSERT INTO wallets (address, public_key, balance, created_at)
            VALUES (?, 'placeholder_public_key', ?, NOW())
            ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance), updated_at = NOW()
        ");
        
        $result = $stmt->execute([$address, $amount]);
        $this->writeLog("BlockStorage::updateWalletBalance - Execute result: " . json_encode($result) . ", affected rows: " . $stmt->rowCount(), 'DEBUG');
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
            if ($block instanceof \Blockchain\Core\Blockchain\Block) {
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

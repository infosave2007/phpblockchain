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
    // Lightweight headers cache storage
    private string $headersFile;
    private array $headers = [];
    
    public function __construct(string $storageFile = 'blockchain.json', ?\PDO $database = null, ?ValidatorManager $validatorManager = null)
    {
        $this->storageFile = $storageFile;
        $this->database = $database;
        $this->validatorManager = $validatorManager;
        $this->headersFile = dirname($this->storageFile) . '/block_headers.json';
        $this->loadBlocks();
        $this->loadHeaders();
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
    \Blockchain\Wallet\WalletLogger::log($message, $level);
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
        
        // Initialize transaction flag BEFORE try block
        $transactionStarted = false;
        
        try {
            $this->writeLog("BlockStorage::saveToDatabaseStorage - Starting to save block " . $block->getHash(), 'DEBUG');
            
            // Check if we're already in a transaction
            if ($this->database->inTransaction()) {
                $this->writeLog("BlockStorage::saveToDatabaseStorage - Already in transaction, using existing transaction", 'DEBUG');
                // Use existing transaction instead of forcing commit
                $transactionStarted = false;
            } else {
                $this->writeLog("BlockStorage::saveToDatabaseStorage - Starting new transaction", 'DEBUG');
                $this->database->beginTransaction();
                $transactionStarted = true;
            }
            
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
            
            // Try UPDATE first for block
            $upd = $this->database->prepare("
                UPDATE blocks 
                SET parent_hash = ?, height = ?, timestamp = ?, validator = ?, signature = ?, 
                    merkle_root = ?, transactions_count = ?, metadata = ?
                WHERE hash = ?
            ");
            
            $metadata = json_encode([
                'genesis' => $block->getIndex() === 0,
                'difficulty' => $block->getDifficulty(),
                'nonce' => $block->getNonce(),
                'validator_manager_used' => $this->validatorManager !== null
            ]);
            
            $upd->execute([
                $block->getPreviousHash(),
                $block->getIndex(),
                $block->getTimestamp(),
                $validatorAddress,
                $blockSignature,
                $block->getMerkleRoot(),
                count($block->getTransactions()),
                $metadata,
                $block->getHash()
            ]);
            
            if ($upd->rowCount() === 0) {
                // No existing block found, INSERT new one
                $ins = $this->database->prepare("
                    INSERT INTO blocks (hash, parent_hash, height, timestamp, validator, signature, merkle_root, transactions_count, metadata) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $blockResult = $ins->execute([
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
                
                $this->writeLog("BlockStorage::saveToDatabaseStorage - Block INSERT result: " . json_encode($blockResult), 'DEBUG');
            } else {
                $this->writeLog("BlockStorage::saveToDatabaseStorage - Block UPDATE affected rows: " . $upd->rowCount(), 'DEBUG');
            }
            
            // Process each transaction in the block
            $this->writeLog("BlockStorage::saveToDatabaseStorage - Processing " . count($block->getTransactions()) . " transactions", 'DEBUG');
            foreach ($block->getTransactions() as $index => $transaction) {
                $this->writeLog("BlockStorage::saveToDatabaseStorage - Processing transaction " . ($index + 1) . "/" . count($block->getTransactions()), 'DEBUG');
                try {
                    // Convert Transaction object to array if needed
                    $txData = $transaction instanceof \Blockchain\Core\Transaction\Transaction 
                        ? $transaction->toArray() 
                        : $transaction;
                    
                    // Normalize array keys for backward compatibility
                    if (isset($txData['from_address'])) {
                        $txData['from'] = $txData['from_address'];
                    }
                    if (isset($txData['to_address'])) {
                        $txData['to'] = $txData['to_address'];
                    }
                    
                    $this->processTransaction($txData, $block);
                    $this->writeLog("BlockStorage::saveToDatabaseStorage - Transaction " . ($index + 1) . " processed successfully", 'DEBUG');
                } catch (\Exception $txError) {
                    $this->writeLog("BlockStorage::saveToDatabaseStorage - TRANSACTION ERROR in tx " . ($index + 1) . ": " . $txError->getMessage(), 'ERROR');
                    $this->writeLog("BlockStorage::saveToDatabaseStorage - Transaction data: " . json_encode($transaction), 'DEBUG');
                    throw $txError; // Re-throw to trigger rollback
                }
            }
            
            $this->writeLog("BlockStorage::saveToDatabaseStorage - All transactions processed, committing...", 'DEBUG');
            
            // Only commit if we started the transaction
            if ($transactionStarted) {
                $this->database->commit();
                $this->writeLog("BlockStorage::saveToDatabaseStorage - Transaction committed successfully", 'DEBUG');
            } else {
                $this->writeLog("BlockStorage::saveToDatabaseStorage - Using existing transaction, not committing", 'DEBUG');
            }
            
            // Clean up mempool by removing confirmed transactions
            $this->cleanMempoolAfterBlockSave($block);
            
            $this->writeLog("BlockStorage::saveToDatabaseStorage - Block saved successfully: " . $block->getHash(), 'INFO');
            return true;
            
        } catch (\PDOException $e) {
            if (isset($transactionStarted) && $transactionStarted && $this->database->inTransaction()) {
                $this->database->rollBack();
                $this->writeLog("BlockStorage::saveToDatabaseStorage - Transaction rolled back due to PDO exception", 'DEBUG');
            }
            $this->writeLog("BlockStorage::saveToDatabaseStorage - PDO Exception: " . $e->getMessage(), 'ERROR');
            return false;
        } catch (\Exception $e) {
            if (isset($transactionStarted) && $transactionStarted && $this->database->inTransaction()) {
                $this->database->rollBack();
                $this->writeLog("BlockStorage::saveToDatabaseStorage - Transaction rolled back due to exception", 'DEBUG');
            }
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
            // Save transaction to transactions table with UPDATE-first approach
            $this->writeLog("BlockStorage::processTransaction - Saving transaction to transactions table", 'DEBUG');
            $txHash = $transaction['hash'] ?? hash('sha256', json_encode($transaction));
            $upd = $this->database->prepare("\n                UPDATE transactions \n                SET block_hash = ?, block_height = ?, status = 'confirmed'\n                WHERE hash = ?\n            ");
            $upd->execute([$block->getHash(), $block->getIndex(), $txHash]);

            if ($upd->rowCount() === 0) {
                // Check for duplicate transactions by content before inserting
                $duplicateCheck = $this->database->prepare("
                    SELECT COUNT(*) FROM transactions 
                    WHERE from_address = ? 
                    AND to_address = ? 
                    AND amount = ? 
                    AND nonce = ? 
                    AND status = 'confirmed'
                ");
                $duplicateCheck->execute([
                    $transaction['from'] ?? 'unknown',
                    $transaction['to'] ?? 'unknown',
                    $transaction['amount'] ?? 0,
                    $transaction['nonce'] ?? 0
                ]);
                
                if ((int)$duplicateCheck->fetchColumn() > 0) {
                    $this->writeLog("BlockStorage::processTransaction - Skipping duplicate transaction by content", 'WARNING');
                    return;
                }
                
                // Dynamic extraction of fields (preserve external signatures & original gas/nonce)
                $gasLimit = $transaction['gas_limit'] ?? $transaction['gasLimit'] ?? 21000;
                $gasUsed  = $transaction['gas_used'] ?? $transaction['gasUsed'] ?? 0;
                $gasPrice = $transaction['gas_price'] ?? $transaction['gasPrice'] ?? 0.00001;
                $nonce    = $transaction['nonce'] ?? 0;
                $fee      = $transaction['fee'] ?? 0;
                $amount   = $transaction['amount'] ?? 0;
                $sig      = $transaction['signature'] ?? 'system_signature';
                if ($sig === '' || $sig === null) { $sig = 'system_signature'; }
                $timestamp = $transaction['timestamp'] ?? $block->getTimestamp();
                // Prefer explicit 'data' (string) else metadata JSON
                if (array_key_exists('data', $transaction) && !is_array($transaction['data'])) {
                    $dataField = is_string($transaction['data']) ? $transaction['data'] : json_encode($transaction['data']);
                } else {
                    $dataField = json_encode($transaction['metadata'] ?? []);
                }
                $ins = $this->database->prepare("
                    INSERT INTO transactions (hash, block_hash, block_height, from_address, to_address, amount, fee, gas_limit, gas_used, gas_price, nonce, data, signature, status, timestamp)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?)
                ");
                $txResult = $ins->execute([
                    $txHash,
                    $block->getHash(),
                    $block->getIndex(),
                    $transaction['from'] ?? 'unknown',
                    $transaction['to'] ?? 'unknown',
                    $amount,
                    $fee,
                    $gasLimit,
                    $gasUsed,
                    $gasPrice,
                    $nonce,
                    $dataField,
                    $sig,
                    $timestamp
                ]);
                $this->writeLog("BlockStorage::processTransaction - Transaction INSERT result: " . json_encode($txResult), 'DEBUG');
            } else {
                $this->writeLog("BlockStorage::processTransaction - Transaction UPDATE affected rows: " . $upd->rowCount(), 'DEBUG');
            }
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
        if (method_exists($block, 'getIndex')) {
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
        $publicKey = null;

        if (!empty($metadata['public_key']) && $metadata['public_key'] !== 'placeholder_public_key') {
            $publicKey = $metadata['public_key'];
        } else {
            // Try to get public key from wallets table (real one only)
            $stmt = $this->database->prepare("SELECT public_key FROM wallets WHERE address = ?");
            $stmt->execute([$validatorAddress]);
            $wallet = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($wallet && !empty($wallet['public_key']) && $wallet['public_key'] !== 'placeholder_public_key') {
                $publicKey = $wallet['public_key'];
            }
        }

        // Fallback: deterministic pseudo public key (avoids NULL constraint issues)
        if (empty($publicKey)) {
            $publicKey = substr(hash('sha256', $validatorAddress . '|auto_pub'), 0, 66);
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
        try {
            // Use INSERT ... ON DUPLICATE KEY UPDATE to avoid race conditions
            $stmt = $this->database->prepare("
                INSERT INTO wallets (address, public_key, balance, created_at, updated_at) 
                VALUES (?, 'placeholder_public_key', ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                balance = balance + VALUES(balance),
                updated_at = NOW()
            ");
            $ok = $stmt->execute([$address, $amount]);
            $affected = $stmt->rowCount();
            
            if ($affected === 1) {
                $this->writeLog("BlockStorage::updateWalletBalance - Inserted new wallet row for $address with balance $amount", 'DEBUG');
            } else {
                $this->writeLog("BlockStorage::updateWalletBalance - Updated existing wallet row for $address, affected_rows=$affected", 'DEBUG');
            }
        } catch (\Throwable $e) {
            $this->writeLog("BlockStorage::updateWalletBalance - ERROR: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
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

    /**
     * Check if any blocks already persisted in database even if in-memory array is empty
     */
    public function hasAnyPersistedBlocks(): bool
    {
        if ($this->getBlockCount() > 0) {
            return true;
        }
        if ($this->database) {
            try {
                $stmt = $this->database->query("SELECT COUNT(*) FROM blocks");
                return ((int)$stmt->fetchColumn()) > 0;
            } catch (\Throwable $e) {
                // Fallback to in-memory only if query fails
            }
        }
        return false;
    }

    /**
     * Get genesis hash from database if present
     */
    public function getGenesisHashFromDatabase(): ?string
    {
        if ($this->database) {
            try {
                $stmt = $this->database->query("SELECT hash FROM blocks WHERE height=0 LIMIT 1");
                $hash = $stmt->fetchColumn();
                if ($hash) return $hash;
                $stmt = $this->database->query("SELECT hash FROM blocks ORDER BY height ASC LIMIT 1");
                $hash = $stmt->fetchColumn();
                return $hash ?: null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
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

    /**
     * Store single block header (light client support)
     */
    public function storeBlockHeader(array $header): bool
    {
        $height = (int)($header['height'] ?? $header['index'] ?? -1);
        if ($height < 0) { return false; }
        $this->headers[$height] = $header;
        return $this->saveHeaders();
    }

    /**
     * Retrieve block header by height
     */
    public function getBlockHeader(int $height): ?array
    {
        return $this->headers[$height] ?? null;
    }

    private function loadHeaders(): void
    {
        if (is_file($this->headersFile)) {
            $data = json_decode((string)@file_get_contents($this->headersFile), true);
            if (is_array($data)) { $this->headers = $data; }
        }
    }

    private function saveHeaders(): bool
    {
        return (bool)@file_put_contents($this->headersFile, json_encode($this->headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Clean up mempool by removing transactions that were confirmed in the block
     */
    private function cleanMempoolAfterBlockSave(BlockInterface $block): void
    {
        if (!$this->database) {
            $this->writeLog("BlockStorage::cleanMempoolAfterBlockSave - No database connection, skipping mempool cleanup", 'WARNING');
            return;
        }
        
        try {
            $this->writeLog("BlockStorage::cleanMempoolAfterBlockSave - Starting mempool cleanup for block " . $block->getHash(), 'DEBUG');
            
            // Get all transaction hashes and from addresses from the block
            $transactionHashes = [];
            $addressesToUpdate = [];
            
            foreach ($block->getTransactions() as $transaction) {
                // Handle both Transaction objects and arrays
                if ($transaction instanceof \Blockchain\Core\Transaction\Transaction) {
                    $txHash = $transaction->getHash();
                    $fromAddress = $transaction->getFromAddress();
                    $nonce = $transaction->getNonce();
                } else {
                    $txHash = $transaction['hash'] ?? hash('sha256', json_encode($transaction));
                    $fromAddress = $transaction['from'] ?? $transaction['from_address'] ?? '';
                    $nonce = (int)($transaction['nonce'] ?? 0);
                }
                
                $transactionHashes[] = $txHash;
                
                // Track the highest nonce for each address
                if (!empty($fromAddress) && $fromAddress !== 'genesis' && $fromAddress !== 'genesis_address') {
                    if (!isset($addressesToUpdate[$fromAddress]) || $nonce > $addressesToUpdate[$fromAddress]) {
                        $addressesToUpdate[$fromAddress] = $nonce;
                    }
                }
            }
            
            if (empty($transactionHashes)) {
                $this->writeLog("BlockStorage::cleanMempoolAfterBlockSave - No transactions to clean from mempool", 'DEBUG');
                return;
            }
            
            // Remove these transactions from mempool
            $placeholders = str_repeat('?,', count($transactionHashes) - 1) . '?';
            $deleteStmt = $this->database->prepare("DELETE FROM mempool WHERE tx_hash IN ($placeholders)");
            $deleteStmt->execute($transactionHashes);
            
            $deletedCount = $deleteStmt->rowCount();
            $this->writeLog("BlockStorage::cleanMempoolAfterBlockSave - Removed $deletedCount transactions from mempool", 'INFO');
            
            // Update wallet nonces to the highest confirmed nonce for each address
            foreach ($addressesToUpdate as $address => $highestNonce) {
                try {
                    // Get the actual highest confirmed nonce from transactions table
                    $maxNonceStmt = $this->database->prepare("
                        SELECT COALESCE(MAX(nonce), -1) as max_confirmed_nonce 
                        FROM transactions 
                        WHERE from_address = ? AND status = 'confirmed'
                    ");
                    $maxNonceStmt->execute([$address]);
                    $result = $maxNonceStmt->fetch();
                    $maxConfirmedNonce = $result ? (int)$result['max_confirmed_nonce'] : -1;
                    
                    // Update wallet nonce to match the highest confirmed transaction nonce
                    $updateNonceStmt = $this->database->prepare("
                        UPDATE wallets 
                        SET nonce = ?, updated_at = NOW() 
                        WHERE address = ?
                    ");
                    $updateNonceStmt->execute([$maxConfirmedNonce, $address]);
                    
                    $affectedRows = $updateNonceStmt->rowCount();
                    if ($affectedRows > 0) {
                        $this->writeLog("BlockStorage::cleanMempoolAfterBlockSave - Updated nonce for $address to $maxConfirmedNonce (was updating to $highestNonce)", 'DEBUG');
                    } else {
                        $this->writeLog("BlockStorage::cleanMempoolAfterBlockSave - No wallet found for address $address, may need auto-creation", 'WARNING');
                    }
                } catch (\Exception $e) {
                    $this->writeLog("BlockStorage::cleanMempoolAfterBlockSave - ERROR updating nonce for $address: " . $e->getMessage(), 'ERROR');
                }
            }
            
        } catch (\Exception $e) {
            $this->writeLog("BlockStorage::cleanMempoolAfterBlockSave - ERROR cleaning mempool: " . $e->getMessage(), 'ERROR');
            // Don't throw exception to avoid blocking block save
        }
    }
}

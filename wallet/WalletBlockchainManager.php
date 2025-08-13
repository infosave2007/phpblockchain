<?php
declare(strict_types=1);

namespace Blockchain\Wallet;

use Blockchain\Core\Blockchain\Block;
use Blockchain\Core\Storage\BlockStorage;
use Blockchain\Core\Consensus\ValidatorManager;
use PDO;
use Exception;

// Include logger
require_once __DIR__ . '/WalletLogger.php';

/**
 * Wallet Transaction Manager for Blockchain Integration
 * Handles wallet operations that need to be recorded in blockchain
 * 
 * REFACTORED: All validator selection and signature logic now uses ValidatorManager
 * - Removed legacy validator selection methods (getActiveValidatorAddress)
 * - Removed legacy signature generation methods (generateBlockSignature)
 * - Removed legacy cryptography methods (getValidatorPrivateKey, getValidatorSigningKey)
 * - All blockchain operations now require ValidatorManager; BlockStorage is disabled to prevent hardcoded validator logic
 * - Centralized validator/signature management ensures consistency
 * 
 * @since 2025-01-20 Refactored to use centralized ValidatorManager
 */
class WalletBlockchainManager
{
    private ?PDO $database;
    private ?BlockStorage $blockStorage;
    private ?ValidatorManager $validatorManager;
    private array $config;
    private array $pendingTransactions = [];
    
    public function __construct(?PDO $database, array $config = [])
    {
        $this->database = $database;
        $this->config = $config;
        
        // Initialize ValidatorManager (required for all validator/signature operations)
        if ($this->database) {
            try {
                // Load ValidatorManager class if needed
                if (!class_exists('\Blockchain\Core\Consensus\ValidatorManager')) {
                    $validatorManagerPath = dirname(__DIR__) . '/core/Consensus/ValidatorManager.php';
                    if (file_exists($validatorManagerPath)) {
                        require_once $validatorManagerPath;
                    }
                }
                
                if (class_exists('\Blockchain\Core\Consensus\ValidatorManager')) {
                    $this->validatorManager = new ValidatorManager($this->database, $this->config);
                    \Blockchain\Wallet\WalletLogger::info("ValidatorManager initialized successfully");
                } else {
                    \Blockchain\Wallet\WalletLogger::error("ValidatorManager class not found - blockchain operations will fail");
                    throw new Exception("ValidatorManager is required but not available");
                }
            } catch (Exception $e) {
                \Blockchain\Wallet\WalletLogger::error("Failed to initialize ValidatorManager: " . $e->getMessage());
                throw $e; // Re-throw since ValidatorManager is required
            }
        } else {
            \Blockchain\Wallet\WalletLogger::warning("No database provided - ValidatorManager cannot be initialized");
        }
        
        // BlockStorage is disabled - using direct database operations with ValidatorManager
        // This ensures all validator/signature operations use the centralized ValidatorManager
        $this->blockStorage = null;
    \Blockchain\Wallet\WalletLogger::info("Using direct database operations with ValidatorManager for all blockchain operations");
    }
    
    /**
     * Create wallet and record in blockchain
     */
    public function createWalletWithBlockchain(array $walletData): array
    {
        try {
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletWithBlockchain - Starting with data: " . json_encode($walletData));
            
            // 1. Create wallet transaction
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletWithBlockchain - Creating wallet transaction");
            $walletTx = $this->createWalletTransaction($walletData);
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletWithBlockchain - Wallet transaction created: " . json_encode($walletTx));
            
            // 2. Add to pending transactions
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletWithBlockchain - Adding to pending transactions");
            $this->addToPendingTransactions($walletTx);
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletWithBlockchain - Added to pending transactions");
            
            // 3. Create a block if we have enough transactions or this is important
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletWithBlockchain - Creating block with transactions");
            $block = $this->createBlockWithTransactions([$walletTx]);
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletWithBlockchain - Block created");
            
            // 4. Save block to blockchain
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletWithBlockchain - Saving block to blockchain");
            if ($this->blockStorage) {
                \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletWithBlockchain - Using BlockStorage");
                $this->blockStorage->saveBlock($block);
            } else {
                \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletWithBlockchain - Using fallback database save");
                // Fallback: save directly to database
                $this->saveBlockToDatabase($block);
            }
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletWithBlockchain - Block saved successfully");
            
            // 5. Broadcast to network (if configured)
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletWithBlockchain - Broadcasting to network");
            $this->broadcastToNetwork($walletTx, $block);
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletWithBlockchain - Broadcast completed");
            
            $result = [
                'wallet' => $walletData,
                'transaction' => $walletTx,
                'block' => [
                    'hash' => $block->getHash(),
                    'height' => $block->getIndex(),
                    'timestamp' => $block->getTimestamp()
                ],
                'blockchain_recorded' => true
            ];
            
            \Blockchain\Wallet\WalletLogger::info("WalletBlockchainManager::createWalletWithBlockchain - Success: " . json_encode($result));
            return $result;
            
        } catch (Exception $e) {
            \Blockchain\Wallet\WalletLogger::error("WalletBlockchainManager::createWalletWithBlockchain - Error: " . $e->getMessage());
            \Blockchain\Wallet\WalletLogger::error("WalletBlockchainManager::createWalletWithBlockchain - Error trace: " . $e->getTraceAsString());
            
            // Fallback: save only to database without blockchain
            $fallbackResult = [
                'wallet' => $walletData,
                'blockchain_recorded' => false,
                'error' => $e->getMessage()
            ];
            
            \Blockchain\Wallet\WalletLogger::warning("WalletBlockchainManager::createWalletWithBlockchain - Fallback result: " . json_encode($fallbackResult));
            return $fallbackResult;
        }
    }
    
    /**
     * Restore wallet and record in blockchain
     */
    public function restoreWalletWithBlockchain(array $walletData): array
    {
        try {
            // 1. Create restore transaction
            $restoreTx = $this->createRestoreTransaction($walletData);
            
            // 2. Create block with restore transaction
            $block = $this->createBlockWithTransactions([$restoreTx]);
            
            // 3. Save to blockchain
            if ($this->blockStorage) {
                $this->blockStorage->saveBlock($block);
            } else {
                // Fallback: save directly to database
                $this->saveBlockToDatabase($block);
            }
            
            // 4. Broadcast to network
            $this->broadcastToNetwork($restoreTx, $block);
            
            return [
                'wallet' => $walletData,
                'transaction' => $restoreTx,
                'block' => [
                    'hash' => $block->getHash(),
                    'height' => $block->getIndex(),
                    'timestamp' => $block->getTimestamp()
                ],
                'blockchain_recorded' => true
            ];
            
        } catch (Exception $e) {
            return [
                'wallet' => $walletData,
                'blockchain_recorded' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create wallet creation transaction
     */
    private function createWalletTransaction(array $walletData): array
    {
    \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletTransaction - Starting transaction creation");
        
        try {
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletTransaction - Getting node ID");
            $nodeId = $this->getNodeId();
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletTransaction - Node ID obtained: " . $nodeId);
            
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletTransaction - Preparing data for signing");
            $dataToSign = [
                'action' => 'create_wallet',
                'address' => $walletData['address'],
                'timestamp' => time()
            ];
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletTransaction - Data to sign: " . json_encode($dataToSign));
            
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletTransaction - Starting signature process");
            $signature = $this->signTransaction($dataToSign);
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletTransaction - Transaction signed successfully");
            
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletTransaction - Creating transaction array");
            $transaction = [
                'hash' => hash('sha256', 'wallet_create_' . $walletData['address'] . '_' . time()),
                'type' => 'wallet_create',
                'from' => 'system',
                'to' => $walletData['address'],
                'amount' => 0.0,
                'fee' => 0.0,
                'timestamp' => time(),
                'data' => [
                    'action' => 'create_wallet',
                    'public_key' => $walletData['public_key'],
                    'wallet_address' => $walletData['address'],
                    'created_via' => 'web_interface',
                    'node_id' => $nodeId,
                    'version' => '1.0.0'
                ],
                'signature' => $signature,
                'status' => 'pending'  // New wallet transactions start as pending
            ];
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletTransaction - Transaction array created");
            
            \Blockchain\Wallet\WalletLogger::debug("WalletBlockchainManager::createWalletTransaction - Transaction created successfully");
            return $transaction;
            
        } catch (Exception $e) {
            \Blockchain\Wallet\WalletLogger::error("WalletBlockchainManager::createWalletTransaction - Error: " . $e->getMessage());
            \Blockchain\Wallet\WalletLogger::error("WalletBlockchainManager::createWalletTransaction - Error file: " . $e->getFile());
            \Blockchain\Wallet\WalletLogger::error("WalletBlockchainManager::createWalletTransaction - Error line: " . $e->getLine());
            \Blockchain\Wallet\WalletLogger::error("WalletBlockchainManager::createWalletTransaction - Error trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
    
    /**
     * Create wallet restore transaction
     */
    private function createRestoreTransaction(array $walletData): array
    {
        return [
            'hash' => hash('sha256', 'wallet_restore_' . $walletData['address'] . '_' . time()),
            'type' => 'wallet_restore',
            'from' => 'system',
            'to' => $walletData['address'],
            'amount' => 0.0,
            'fee' => 0.0,
            'timestamp' => time(),
            'data' => [
                'action' => 'restore_wallet',
                'public_key' => $walletData['public_key'],
                'wallet_address' => $walletData['address'],
                'restored_via' => 'web_interface',
                'node_id' => $this->getNodeId(),
                'version' => '1.0.0',
                'existing_wallet' => $walletData['existing'] ?? false
            ],
            'signature' => $this->signTransaction([
                'action' => 'restore_wallet',
                'address' => $walletData['address'],
                'timestamp' => time()
            ]),
            'status' => 'pending'  // Restore transactions also start as pending
        ];
    }
    
    /**
     * Create block with transactions using centralized ValidatorManager
     */
    private function createBlockWithTransactions(array $transactions): Block
    {
        try {
            // Check if Block class exists, if not try to load it
            if (!class_exists('\Blockchain\Core\Blockchain\Block')) {
                $blockPath = dirname(__DIR__) . '/core/Blockchain/Block.php';
                if (file_exists($blockPath)) {
                    require_once $blockPath;
                }
            }
            
            if (!class_exists('\Blockchain\Core\Blockchain\Block')) {
                throw new Exception('Block class not available');
            }
            
            // Get latest block for previous hash
            $previousBlock = $this->blockStorage ? $this->blockStorage->getLatestBlock() : null;
            $previousHash = $previousBlock ? $previousBlock->getHash() : '0';
            
            // Safe way to get index from BlockInterface
            $concretePreviousBlock = ($previousBlock instanceof \Blockchain\Core\Blockchain\Block) ? $previousBlock : null;
            $nextIndex = $concretePreviousBlock ? $concretePreviousBlock->getIndex() + 1 : 1;
            
            // If no BlockStorage, get latest block from database
            if (!$previousBlock && $this->database) {
                try {
                    $stmt = $this->database->query("SELECT * FROM blocks ORDER BY height DESC LIMIT 1");
                    $latestBlockData = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($latestBlockData) {
                        $previousHash = $latestBlockData['hash'];
                        $nextIndex = $latestBlockData['height'] + 1;
                    }
                } catch (Exception $e) {
                    \WalletLogger::error("Failed to get latest block from database: " . $e->getMessage());
                }
            }
            
            // Create new block
            $block = new Block($nextIndex, $transactions, $previousHash);
            
            // Use ValidatorManager to sign the block (centralized signature logic)
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
                    
                    \WalletLogger::info("Block signed with ValidatorManager - validator: " . $signatureData['validator_address'] . 
                        ", block_hash: " . $blockData['hash'] . 
                        ", signature_prefix: " . substr($signatureData['signature'], 0, 20) . "...");
                    
                    // Store signature in block metadata if possible
                    try {
                        if (method_exists($block, 'setValidator')) {
                            $block->setValidator($signatureData['validator_address']);
                        }
                        if (method_exists($block, 'setSignature')) {
                            $block->setSignature($signatureData['signature']);
                        }
                    } catch (Exception $e) {
                        \WalletLogger::debug("Could not store signature in block object: " . $e->getMessage());
                    }
                    
                } catch (Exception $e) {
                    \WalletLogger::error("Failed to sign block with ValidatorManager: " . $e->getMessage());
                    // Continue without signature for now
                }
            } else {
                \WalletLogger::warning("ValidatorManager not available, block created without centralized signature");
            }
            
            return $block;
        } catch (Exception $e) {
            // Fallback: create a simple block structure
            return $this->createSimpleBlock($transactions);
        }
    }
    
    /**
     * Create simple block structure when Block class is not available
     */
    private function createSimpleBlock(array $transactions): object
    {
        $blockData = new \stdClass();
        $blockData->index = 1;
        $blockData->timestamp = time();
        $blockData->transactions = $transactions;
        $blockData->previousHash = '0';
        $blockData->hash = hash('sha256', json_encode($transactions) . time());
        $blockData->merkleRoot = $this->calculateMerkleRoot($transactions);
        $blockData->nonce = 0;
        
        // Add methods
        $blockData->getHash = function() use ($blockData) { return $blockData->hash; };
        $blockData->getIndex = function() use ($blockData) { return $blockData->index; };
        $blockData->getTimestamp = function() use ($blockData) { return $blockData->timestamp; };
        $blockData->getTransactions = function() use ($blockData) { return $blockData->transactions; };
        $blockData->getPreviousHash = function() use ($blockData) { return $blockData->previousHash; };
        $blockData->getMerkleRoot = function() use ($blockData) { return $blockData->merkleRoot; };
        $blockData->getNonce = function() use ($blockData) { return $blockData->nonce; };
        
        return $blockData;
    }
    
    /**
     * Calculate simple Merkle root
     */
    private function calculateMerkleRoot(array $transactions): string
    {
        if (empty($transactions)) {
            return hash('sha256', '');
        }
        
        $hashes = [];
        foreach ($transactions as $tx) {
            $hashes[] = is_array($tx) ? hash('sha256', json_encode($tx)) : hash('sha256', $tx);
        }
        
        while (count($hashes) > 1) {
            $newHashes = [];
            for ($i = 0; $i < count($hashes); $i += 2) {
                $left = $hashes[$i];
                $right = $hashes[$i + 1] ?? $hashes[$i];
                $newHashes[] = hash('sha256', $left . $right);
            }
            $hashes = $newHashes;
        }
        
        return $hashes[0];
    }
    
    /**
     * Add transaction to pending pool
     */
    private function addToPendingTransactions(array $transaction): void
    {
        \WalletLogger::debug("WalletBlockchainManager::addToPendingTransactions - Adding transaction to pending pool");
        $this->pendingTransactions[] = $transaction;
        
        // Also save to database mempool if available
        if ($this->database) {
            try {
                \WalletLogger::debug("WalletBlockchainManager::addToPendingTransactions - Saving to database mempool");
                
                // Check if transaction already exists in mempool
                $checkStmt = $this->database->prepare("SELECT id FROM mempool WHERE tx_hash = ?");
                $checkStmt->execute([$transaction['hash']]);
                
                if ($checkStmt->fetch()) {
                    \WalletLogger::debug("WalletBlockchainManager::addToPendingTransactions - Transaction already exists in mempool, skipping");
                    return;
                }
                
                $stmt = $this->database->prepare("
                    INSERT INTO mempool (tx_hash, from_address, to_address, amount, fee, data, signature, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $transaction['hash'],
                    $transaction['from'],
                    $transaction['to'],
                    $transaction['amount'],
                    $transaction['fee'],
                    json_encode($transaction['data']),
                    $transaction['signature']
                ]);
                
                \WalletLogger::debug("WalletBlockchainManager::addToPendingTransactions - Transaction added to mempool successfully");
            } catch (Exception $e) {
                \WalletLogger::error("Failed to add transaction to mempool: " . $e->getMessage());
                \WalletLogger::error("Transaction hash: " . $transaction['hash']);
                // Don't throw exception, just log the error
            }
        } else {
            \WalletLogger::debug("WalletBlockchainManager::addToPendingTransactions - No database connection, skipping mempool");
        }
    }
    
    /**
     * Sign transaction data
     */
    private function signTransaction(array $data): string
    {
        \WalletLogger::debug("WalletBlockchainManager::signTransaction - Starting transaction signing");
        try {
            \WalletLogger::debug("WalletBlockchainManager::signTransaction - Preparing data string");
            \WalletLogger::debug("WalletBlockchainManager::signTransaction - Input data: " . print_r($data, true));
            
            // Check if data is valid before json_encode
            if (empty($data)) {
                throw new Exception("Empty data array provided for signing");
            }
            
            \WalletLogger::debug("WalletBlockchainManager::signTransaction - Data validation passed");
            
            // Sort the data keys for consistent signing
            ksort($data);
            $dataString = json_encode($data);
            if ($dataString === false) {
                $jsonError = json_last_error_msg();
                throw new Exception("Failed to encode data to JSON: " . $jsonError);
            }
            
            \WalletLogger::debug("WalletBlockchainManager::signTransaction - Data string created: " . $dataString);
            
            // Try to use proper cryptographic signature if available
            if (isset($data['private_key']) && class_exists('\Blockchain\Core\Cryptography\Signature')) {
                \WalletLogger::debug("WalletBlockchainManager::signTransaction - Using cryptographic signature");
                try {
                    $signature = \Blockchain\Core\Cryptography\Signature::sign($dataString, $data['private_key']);
                    \WalletLogger::debug("WalletBlockchainManager::signTransaction - Cryptographic signature created");
                    return $signature;
                } catch (Exception $e) {
                    \WalletLogger::warning("WalletBlockchainManager::signTransaction - Cryptographic signature failed: " . $e->getMessage());
                    // Continue to fallback
                }
            }
            
            // Fallback to HMAC signature
            \WalletLogger::debug("WalletBlockchainManager::signTransaction - Using HMAC signature (fallback)");
            $secret = $this->config['app_key'] ?? 'default_secret';
            \WalletLogger::debug("WalletBlockchainManager::signTransaction - Secret key available: " . (!empty($secret) ? 'yes' : 'no'));
            
            if (empty($secret)) {
                throw new Exception("No secret key available for HMAC signature");
            }
            
            $signature = hash_hmac('sha256', $dataString, $secret);
            if ($signature === false) {
                throw new Exception("Failed to create HMAC signature");
            }
            
            \WalletLogger::debug("WalletBlockchainManager::signTransaction - HMAC signature created successfully: " . substr($signature, 0, 16) . "...");
            
            \WalletLogger::debug("WalletBlockchainManager::signTransaction - Transaction signed successfully");
            return $signature;
        } catch (Exception $e) {
            \WalletLogger::error("WalletBlockchainManager::signTransaction - Error: " . $e->getMessage());
            \WalletLogger::error("WalletBlockchainManager::signTransaction - Error file: " . $e->getFile());
            \WalletLogger::error("WalletBlockchainManager::signTransaction - Error line: " . $e->getLine());
            \WalletLogger::error("WalletBlockchainManager::signTransaction - Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
    
    /**
     * Get current node ID
     */
    private function getNodeId(): string
    {
        \WalletLogger::debug("WalletBlockchainManager::getNodeId - Starting node ID retrieval");
        
        if ($this->database) {
            try {
                \WalletLogger::debug("WalletBlockchainManager::getNodeId - Querying nodes table");
                $stmt = $this->database->query("SELECT node_id FROM nodes WHERE status = 'active' LIMIT 1");
                $node = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($node) {
                    \WalletLogger::debug("WalletBlockchainManager::getNodeId - Found active node: " . $node['node_id']);
                    return $node['node_id'];
                }
                \WalletLogger::debug("WalletBlockchainManager::getNodeId - No active nodes found");
            } catch (Exception $e) {
                \WalletLogger::warning("WalletBlockchainManager::getNodeId - Database error: " . $e->getMessage());
                // Fallback to generated ID
            }
        } else {
            \WalletLogger::debug("WalletBlockchainManager::getNodeId - No database connection available");
        }
        
        $fallbackId = hash('sha256', gethostname() . time());
        \WalletLogger::debug("WalletBlockchainManager::getNodeId - Using fallback ID: " . $fallbackId);
        return $fallbackId;
    }
    
    /**
     * Broadcast to network nodes
     */
    private function broadcastToNetwork(array $transaction, Block $block): void
    {
        try {
            // Get active nodes from database
            if (!$this->database) {
                return;
            }
            
            $stmt = $this->database->query("SELECT * FROM nodes WHERE status = 'active' AND node_id != '" . $this->getNodeId() . "'");
            $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($nodes as $node) {
                $this->sendToNode($node, [
                    'type' => 'new_wallet_transaction',
                    'transaction' => $transaction,
                    'block' => [
                        'hash' => $block->getHash(),
                        'height' => $block->getIndex(),
                        'timestamp' => $block->getTimestamp(),
                        'transactions' => $block->getTransactions()
                    ],
                    'node_id' => $this->getNodeId(),
                    'timestamp' => time()
                ]);
            }
        } catch (Exception $e) {
            \WalletLogger::error("Failed to broadcast to network: " . $e->getMessage());
        }
    }
    
    /**
     * Send data to specific node
     */
    private function sendToNode(array $node, array $data): bool
    {
        try {
            $url = $node['protocol'] ?? 'https';
            $url .= '://' . $node['ip_address'];
            if (!empty($node['port']) && $node['port'] != 80 && $node['port'] != 443) {
                $url .= ':' . $node['port'];
            }
            $url .= '/api/sync/wallet';
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'User-Agent: PHPBlockchain/1.0',
                    'X-Node-ID: ' . $this->getNodeId()
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200;
            
        } catch (Exception $e) {
            \WalletLogger::error("Failed to send to node {$node['ip_address']}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get wallet transaction history from blockchain
     */
    public function getWalletTransactionHistory(string $address): array
    {
        if (!$this->database) {
            return [];
        }
        
        try {
            $stmt = $this->database->prepare("
                SELECT t.*, b.height as block_height, b.timestamp as block_timestamp
                FROM transactions t
                JOIN blocks b ON t.block_hash = b.hash
                WHERE t.from_address = ? OR t.to_address = ?
                ORDER BY b.height DESC, t.id DESC
                LIMIT 100
            ");
            
            $stmt->execute([$address, $address]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            \WalletLogger::error("Failed to get wallet history: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Verify wallet exists in blockchain
     */
    public function verifyWalletInBlockchain(string $address): bool
    {
        if (!$this->database) {
            return false;
        }
        
        try {
            $stmt = $this->database->prepare("
                SELECT COUNT(*) as count 
                FROM transactions 
                WHERE (from_address = ? OR to_address = ?) 
                AND data LIKE '%wallet_create%' OR data LIKE '%wallet_restore%'
            ");
            
            $stmt->execute([$address, $address]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['count'] > 0;
            
        } catch (Exception $e) {
            \WalletLogger::error("Failed to verify wallet in blockchain: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Save block directly to database using ValidatorManager
     */
    private function saveBlockToDatabase($block): void
    {
        if (!$this->database) {
            return;
        }
        
        $transactionStarted = false;
        try {
            // Check database transaction state
            \WalletLogger::debug("Checking database transaction state");
            
            if ($this->database->inTransaction()) {
                \WalletLogger::debug("Database already in transaction - using existing transaction");
                $transactionStarted = false;
            } else {
                // Start a new transaction only if not already in one
                $this->database->beginTransaction();
                $transactionStarted = true;
                \WalletLogger::debug("New transaction started successfully");
            }
            
            // Get block data safely - cast to Block class for getIndex/getMerkleRoot methods
            $concreteBlock = ($block instanceof \Blockchain\Core\Blockchain\Block) ? $block : null;
            
            $hash = is_callable([$block, 'getHash']) ? $block->getHash() : $block->hash;
            $index = $concreteBlock ? $concreteBlock->getIndex() : ($block->index ?? 1);
            $timestamp = is_callable([$block, 'getTimestamp']) ? $block->getTimestamp() : $block->timestamp;
            $previousHash = is_callable([$block, 'getPreviousHash']) ? $block->getPreviousHash() : $block->previousHash;
            $merkleRoot = $concreteBlock ? $concreteBlock->getMerkleRoot() : ($block->merkleRoot ?? hash('sha256', ''));
            $transactions = is_callable([$block, 'getTransactions']) ? $block->getTransactions() : $block->transactions;
            $nonce = is_callable([$block, 'getNonce']) ? $block->getNonce() : ($block->nonce ?? 0);
            
            // Use ValidatorManager for signature (centralized logic)
            $validatorAddress = null;
            $blockSignature = null;
            
            if ($this->validatorManager) {
                try {
                    $blockData = [
                        'hash' => $hash,
                        'index' => $index,
                        'timestamp' => $timestamp,
                        'previous_hash' => $previousHash,
                        'merkle_root' => $merkleRoot,
                        'transactions_count' => count($transactions)
                    ];
                    
                    $signatureData = $this->validatorManager->signBlock($blockData);
                    $validatorAddress = $signatureData['validator_address'];
                    $blockSignature = $signatureData['signature'];
                    
                    \WalletLogger::info("Block signed with ValidatorManager - validator: $validatorAddress");
                    
                } catch (Exception $e) {
                    \WalletLogger::error("ValidatorManager signing failed: " . $e->getMessage());
                    throw new Exception("Failed to sign block: ValidatorManager is required but failed");
                }
            } else {
                throw new Exception("ValidatorManager is required but not available");
            }
            
            // Save block first
            $stmt = $this->database->prepare("
                INSERT INTO blocks (hash, parent_hash, height, timestamp, validator, signature, merkle_root, transactions_count, metadata) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                parent_hash = VALUES(parent_hash),
                height = VALUES(height),
                timestamp = VALUES(timestamp),
                merkle_root = VALUES(merkle_root),
                transactions_count = VALUES(transactions_count),
                metadata = VALUES(metadata)
            ");
            
            $metadata = json_encode([
                'wallet_block' => true,
                'nonce' => $nonce,
                'created_by' => 'WalletBlockchainManager',
                'validator_manager_used' => $this->validatorManager !== null,
                'consensus' => 'pos',
                'staking_required' => true
            ]);
            
            \WalletLogger::debug("Saving block to database - hash: $hash, validator: $validatorAddress, height: $index");
            
            $stmt->execute([
                $hash,
                $previousHash,
                $index,
                $timestamp,
                $validatorAddress,
                $blockSignature,
                $merkleRoot,
                count($transactions),
                $metadata
            ]);
            
            \WalletLogger::debug("Block saved successfully to database");
            
            // Save transactions
            foreach ($transactions as $tx) {
                // Determine if this is a genesis transaction
                $isGenesisTransaction = ($index == 0) || (isset($tx['data']['genesis']) && $tx['data']['genesis'] === true) || ($tx['from'] === 'genesis' || $tx['from'] === 'genesis_address');
                
                // Transactions should be confirmed only if block has valid height
                $transactionStatus = ($index !== null && $index >= 0) ? 'confirmed' : 'pending';
                
                \WalletLogger::debug("Saving transaction {$tx['hash']} with status: $transactionStatus (genesis: " . ($isGenesisTransaction ? 'yes' : 'no') . ", block_height: " . ($index ?? 'null') . ")");
                
                $stmt = $this->database->prepare("
                    INSERT INTO transactions (hash, block_hash, block_height, from_address, to_address, amount, fee, data, signature, status, timestamp, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))
                    ON DUPLICATE KEY UPDATE 
                    block_hash = VALUES(block_hash),
                    block_height = VALUES(block_height),
                    data = VALUES(data),
                    signature = VALUES(signature),
                    status = CASE 
                        WHEN VALUES(block_height) IS NOT NULL AND VALUES(block_height) >= 0 THEN 'confirmed'
                        ELSE 'pending'
                    END
                ");
                
                $stmt->execute([
                    $tx['hash'],
                    $hash,
                    $index,
                    $tx['from'],
                    $tx['to'],
                    $tx['amount'],
                    $tx['fee'],
                    json_encode($tx['data']),
                    $tx['signature'],
                    $transactionStatus,
                    $tx['timestamp'],
                    $tx['timestamp']
                ]);
            }
            
            \WalletLogger::debug("Processing " . count($transactions) . " transactions for wallet creation");
            
            // Update any existing pending transactions to confirmed status
            \WalletLogger::debug("Updating pending transactions to confirmed status");
            $txHashes = array_column($transactions, 'hash');
            if (!empty($txHashes)) {
                $placeholders = str_repeat('?,', count($txHashes) - 1) . '?';
                $updateStmt = $this->database->prepare("
                    UPDATE transactions 
                    SET status = 'confirmed', block_hash = ?, block_height = ? 
                    WHERE hash IN ($placeholders) AND status = 'pending'
                ");
                $updateParams = array_merge([$hash, $index], $txHashes);
                $updateStmt->execute($updateParams);
                $updatedRows = $updateStmt->rowCount();
                \WalletLogger::debug("Updated $updatedRows pending transactions to confirmed status");
            }
            
            // Process wallet_create transactions to ensure wallets exist in database
            foreach ($transactions as $tx) {
                if (($tx['type'] ?? '') === 'wallet_create' && isset($tx['data']['wallet_address'], $tx['data']['public_key'])) {
                    $walletAddress = $tx['data']['wallet_address'];
                    $publicKey = $tx['data']['public_key'];
                    
                    \WalletLogger::debug("Processing wallet_create transaction for address: $walletAddress");
                    
                            // Ensure wallet exists in wallets table (UPDATE-first to avoid insert side-effects)
                            $upd = $this->database->prepare("UPDATE wallets SET public_key = CASE WHEN public_key = 'placeholder_public_key' OR public_key = '' OR public_key IS NULL THEN ? ELSE public_key END, updated_at = NOW() WHERE address = ?");
                            $upd->execute([$publicKey, $walletAddress]);
                            if ($upd->rowCount() === 0) {
                                $ins = $this->database->prepare("INSERT INTO wallets (address, public_key, balance, created_at, updated_at) VALUES (?, ?, 0, NOW(), NOW())");
                                $walletResult = $ins->execute([$walletAddress, $publicKey]);
                                \WalletLogger::info("Wallet record inserted in database for address: $walletAddress, result: " . json_encode($walletResult));
                            } else {
                                \WalletLogger::info("Wallet record updated in database for address: $walletAddress");
                            }
                }
            }
            
            \WalletLogger::debug("All wallet_create transactions processed successfully");
            
            // Update mempool status for transactions in this block
            foreach ($transactions as $tx) {
                $isGenesisTransaction = ($index == 0) || (isset($tx['data']['genesis']) && $tx['data']['genesis'] === true) || ($tx['from'] === 'genesis' || $tx['from'] === 'genesis_address');
                
                if ($isGenesisTransaction) {
                    // Genesis transactions are marked as processed
                    $mempoolStmt = $this->database->prepare("
                        UPDATE mempool 
                        SET status = 'processed', last_retry_at = NOW() 
                        WHERE tx_hash = ?
                    ");
                    $mempoolStmt->execute([$tx['hash']]);
                    \WalletLogger::debug("Updated mempool status to 'processed' for genesis transaction: " . $tx['hash']);
                } else {
                    // Non-genesis transactions remain pending in mempool until consensus confirms them
                    \WalletLogger::debug("Keeping transaction in mempool as 'pending' until consensus: " . $tx['hash']);
                }
            }
            
            \WalletLogger::debug("All mempool transactions updated to processed status");
            
            // Commit only if we started the transaction
            if ($transactionStarted) {
                \WalletLogger::debug("About to commit transaction...");
                $this->database->commit();
                \WalletLogger::debug("Transaction committed successfully");
            } else {
                \WalletLogger::debug("No transaction to commit (not started by this method)");
            }
            
        } catch (Exception $e) {
            \WalletLogger::error("WalletBlockchainManager::saveToDatabaseDirectly - EXCEPTION CAUGHT: " . $e->getMessage());
            \WalletLogger::error("WalletBlockchainManager::saveToDatabaseDirectly - Exception file: " . $e->getFile() . " line: " . $e->getLine());
            \WalletLogger::error("WalletBlockchainManager::saveToDatabaseDirectly - Stack trace: " . $e->getTraceAsString());
            \WalletLogger::error("WalletBlockchainManager::saveToDatabaseDirectly - Transaction started: " . ($transactionStarted ? 'YES' : 'NO'));
            
            // Rollback only if we started the transaction
            if ($transactionStarted) {
                \WalletLogger::error("WalletBlockchainManager::saveToDatabaseDirectly - ROLLING BACK TRANSACTION");
                $this->database->rollBack();
            }
            
            \WalletLogger::error("Failed to save block to database: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Record any transaction in blockchain
     */
    public function recordTransactionInBlockchain(array $transaction): array
    {
        try {
            // Add to pending transactions
            $this->addToPendingTransactions($transaction);
            
            // Create block with transaction
            $block = $this->createBlockWithTransactions([$transaction]);
            
            // Save block to blockchain
            if ($this->blockStorage) {
                $this->blockStorage->saveBlock($block);
            } else {
                // Fallback to database-only storage
                $this->saveBlockToDatabase($block);
            }
            
            // Broadcast to network
            $this->broadcastToNetwork($transaction, $block);
            
            return [
                'transaction' => $transaction,
                'block' => [
                    'hash' => $block->getHash(),
                    'height' => $block->getIndex(),
                    'timestamp' => $block->getTimestamp()
                ],
                'blockchain_recorded' => true
            ];
            
        } catch (Exception $e) {
            \WalletLogger::error("Failed to record transaction in blockchain: " . $e->getMessage());
            return [
                'transaction' => $transaction,
                'blockchain_recorded' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create wallet transaction and add to mempool (proper blockchain flow)
     */
    public function createWalletViaConsensus(array $walletData): array
    {
        try {
            \WalletLogger::info("WalletBlockchainManager::createWalletViaConsensus - Creating wallet via proper consensus flow");
            
            // 1. Create transaction
            $transaction = $this->createWalletTransaction($walletData);
            \WalletLogger::debug("Created wallet transaction: " . $transaction['hash']);
            
            // 2. Add to mempool (pending status)
            $this->addToPendingTransactions($transaction);
            \WalletLogger::debug("Added transaction to mempool with pending status");
            
            // 3. Return transaction info - consensus will handle block creation
            return [
                'success' => true,
                'transaction' => $transaction,
                'status' => 'pending_consensus',
                'message' => 'Transaction added to mempool, waiting for validator to include in block'
            ];
            
        } catch (Exception $e) {
            \WalletLogger::error("WalletBlockchainManager::createWalletViaConsensus - Error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => 'failed'
            ];
        }
    }
    
    /**
     * Restore wallet transaction and add to mempool (proper blockchain flow)
     */
    public function restoreWalletViaConsensus(array $walletData): array
    {
        try {
            \WalletLogger::info("WalletBlockchainManager::restoreWalletViaConsensus - Restoring wallet via proper consensus flow");
            
            // 1. Create restore transaction
            $transaction = $this->createRestoreTransaction($walletData);
            \WalletLogger::debug("Created restore transaction: " . $transaction['hash']);
            
            // 2. Add to mempool (pending status)
            $this->addToPendingTransactions($transaction);
            \WalletLogger::debug("Added restore transaction to mempool with pending status");
            
            // 3. Return transaction info - consensus will handle block creation
            return [
                'success' => true,
                'transaction' => $transaction,
                'status' => 'pending_consensus',
                'message' => 'Restore transaction added to mempool, waiting for validator to include in block'
            ];
            
        } catch (Exception $e) {
            \WalletLogger::error("WalletBlockchainManager::restoreWalletViaConsensus - Error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => 'failed'
            ];
        }
    }

    // ...existing code...
}

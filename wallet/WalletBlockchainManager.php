<?php
declare(strict_types=1);

namespace Blockchain\Wallet;

use Blockchain\Core\Blockchain\Block;
use Blockchain\Core\Storage\BlockStorage;
use PDO;
use Exception;

/**
 * Wallet Transaction Manager for Blockchain Integration
 * Handles wallet operations that need to be recorded in blockchain
 */
class WalletBlockchainManager
{
    private ?PDO $database;
    private ?BlockStorage $blockStorage;
    private array $config;
    private array $pendingTransactions = [];
    
    public function __construct(?PDO $database, array $config = [])
    {
        $this->database = $database;
        $this->config = $config;
        
        // Initialize BlockStorage if database available
        if ($this->database) {
            try {
                // Check if BlockStorage class exists, if not try to load it
                if (!class_exists('\Blockchain\Core\Storage\BlockStorage')) {
                    $blockStoragePath = dirname(__DIR__) . '/core/Storage/BlockStorage.php';
                    if (file_exists($blockStoragePath)) {
                        require_once $blockStoragePath;
                    }
                }
                
                if (class_exists('\Blockchain\Core\Storage\BlockStorage')) {
                    $this->blockStorage = new BlockStorage('blockchain.json', $this->database);
                } else {
                    error_log("BlockStorage class not found, blockchain integration will be limited");
                }
            } catch (Exception $e) {
                error_log("Failed to initialize BlockStorage: " . $e->getMessage());
                $this->blockStorage = null;
            }
        }
    }
    
    /**
     * Create wallet and record in blockchain
     */
    public function createWalletWithBlockchain(array $walletData): array
    {
        try {
            // 1. Create wallet transaction
            $walletTx = $this->createWalletTransaction($walletData);
            
            // 2. Add to pending transactions
            $this->addToPendingTransactions($walletTx);
            
            // 3. Create a block if we have enough transactions or this is important
            $block = $this->createBlockWithTransactions([$walletTx]);
            
            // 4. Save block to blockchain
            if ($this->blockStorage) {
                $this->blockStorage->saveBlock($block);
            } else {
                // Fallback: save directly to database
                $this->saveBlockToDatabase($block);
            }
            
            // 5. Broadcast to network (if configured)
            $this->broadcastToNetwork($walletTx, $block);
            
            return [
                'wallet' => $walletData,
                'transaction' => $walletTx,
                'block' => [
                    'hash' => $block->getHash(),
                    'height' => $block->getIndex(),
                    'timestamp' => $block->getTimestamp()
                ],
                'blockchain_recorded' => true
            ];
            
        } catch (Exception $e) {
            // Fallback: save only to database without blockchain
            return [
                'wallet' => $walletData,
                'blockchain_recorded' => false,
                'error' => $e->getMessage()
            ];
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
        return [
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
                'node_id' => $this->getNodeId(),
                'version' => '1.0.0'
            ],
            'signature' => $this->signTransaction([
                'action' => 'create_wallet',
                'address' => $walletData['address'],
                'timestamp' => time()
            ]),
            'status' => 'confirmed'
        ];
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
            'status' => 'confirmed'
        ];
    }
    
    /**
     * Create block with transactions
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
            $nextIndex = $previousBlock ? $previousBlock->getIndex() + 1 : 1;
            
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
                    error_log("Failed to get latest block from database: " . $e->getMessage());
                }
            }
            
            // Create new block
            $block = new Block($nextIndex, $transactions, $previousHash);
            
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
        $blockData = new stdClass();
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
        $this->pendingTransactions[] = $transaction;
        
        // Also save to database mempool if available
        if ($this->database) {
            try {
                $stmt = $this->database->prepare("
                    INSERT INTO mempool (tx_hash, from_address, to_address, amount, fee, data, signature, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                    from_address = VALUES(from_address),
                    to_address = VALUES(to_address),
                    amount = VALUES(amount),
                    fee = VALUES(fee),
                    data = VALUES(data),
                    signature = VALUES(signature)
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
            } catch (Exception $e) {
                error_log("Failed to add transaction to mempool: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Sign transaction data
     */
    private function signTransaction(array $data): string
    {
        $dataString = json_encode($data, JSON_SORT_KEYS);
        $secret = $this->config['app_key'] ?? 'default_secret';
        return hash_hmac('sha256', $dataString, $secret);
    }
    
    /**
     * Get current node ID
     */
    private function getNodeId(): string
    {
        if ($this->database) {
            try {
                $stmt = $this->database->query("SELECT node_id FROM nodes WHERE status = 'active' LIMIT 1");
                $node = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($node) {
                    return $node['node_id'];
                }
            } catch (Exception $e) {
                // Fallback to generated ID
            }
        }
        
        return hash('sha256', gethostname() . time());
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
            error_log("Failed to broadcast to network: " . $e->getMessage());
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
            error_log("Failed to send to node {$node['ip_address']}: " . $e->getMessage());
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
            error_log("Failed to get wallet history: " . $e->getMessage());
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
            error_log("Failed to verify wallet in blockchain: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Save block directly to database (fallback method)
     */
    private function saveBlockToDatabase($block): void
    {
        if (!$this->database) {
            return;
        }
        
        try {
            // Start transaction
            $this->database->beginTransaction();
            
            // Get block data safely
            $hash = is_callable([$block, 'getHash']) ? $block->getHash() : $block->hash;
            $index = is_callable([$block, 'getIndex']) ? $block->getIndex() : $block->index;
            $timestamp = is_callable([$block, 'getTimestamp']) ? $block->getTimestamp() : $block->timestamp;
            $previousHash = is_callable([$block, 'getPreviousHash']) ? $block->getPreviousHash() : $block->previousHash;
            $merkleRoot = is_callable([$block, 'getMerkleRoot']) ? $block->getMerkleRoot() : $block->merkleRoot;
            $transactions = is_callable([$block, 'getTransactions']) ? $block->getTransactions() : $block->transactions;
            $nonce = is_callable([$block, 'getNonce']) ? $block->getNonce() : ($block->nonce ?? 0);
            
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
                'created_by' => 'WalletBlockchainManager'
            ]);
            
            $stmt->execute([
                $hash,
                $previousHash,
                $index,
                $timestamp,
                '0x0000000000000000000000000000000000000000', // validator placeholder
                'wallet_block_signature',
                $merkleRoot,
                count($transactions),
                $metadata
            ]);
            
            // Save transactions
            foreach ($transactions as $tx) {
                $stmt = $this->database->prepare("
                    INSERT INTO transactions (tx_hash, block_hash, from_address, to_address, amount, fee, data, signature, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))
                    ON DUPLICATE KEY UPDATE 
                    block_hash = VALUES(block_hash),
                    data = VALUES(data),
                    signature = VALUES(signature)
                ");
                
                $stmt->execute([
                    $tx['hash'],
                    $hash,
                    $tx['from'],
                    $tx['to'],
                    $tx['amount'],
                    $tx['fee'],
                    json_encode($tx['data']),
                    $tx['signature'],
                    $tx['timestamp']
                ]);
            }
            
            $this->database->commit();
            
        } catch (Exception $e) {
            $this->database->rollBack();
            error_log("Failed to save block to database: " . $e->getMessage());
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
            error_log("Failed to record transaction in blockchain: " . $e->getMessage());
            return [
                'transaction' => $transaction,
                'blockchain_recorded' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

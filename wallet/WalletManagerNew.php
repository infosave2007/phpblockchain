<?php
declare(strict_types=1);

namespace Blockchain\Wallet;

use Blockchain\Core\Cryptography\KeyPair;
use Blockchain\Core\Cryptography\Mnemonic;
use Blockchain\Core\Cryptography\Signature;
use Blockchain\Core\Transaction\Transaction;
use Blockchain\Core\Storage\BlockchainBinaryStorage;
use PDO;
use Exception;

/**
 * Professional Wallet Manager with Binary Blockchain Integration
 * 
 * Features:
 * - Dual storage: Database + Binary blockchain  
 * - Automatic synchronization
 * - Network node communication
 * - File-only mode support
 * - Transaction management
 * - Consensus integration
 * - Backup/restore operations
 */
class WalletManager
{
    private ?PDO $database;
    private array $config;
    private string $blockchainStoragePath;
    private bool $fileOnlyMode;
    private ?BlockchainBinaryStorage $binaryStorage = null;
    private array $mempoolTransactions = [];
    
    public function __construct(?PDO $database, array $config = [])
    {
        $this->database = $database;
        $this->config = $config;
        $this->fileOnlyMode = ($database === null);
        $this->blockchainStoragePath = $config['storage_dir'] ?? dirname(__DIR__) . '/storage/blockchain/';
        
        // Create blockchain storage directory if not exists
        if (!is_dir($this->blockchainStoragePath)) {
            mkdir($this->blockchainStoragePath, 0755, true);
        }
        
        // Initialize binary storage
        try {
            $this->binaryStorage = new BlockchainBinaryStorage($this->blockchainStoragePath, $config);
        } catch (Exception $e) {
            error_log("Failed to initialize binary storage: " . $e->getMessage());
        }
    }
    
    /**
     * Create new wallet with blockchain integration
     */
    public function createWallet(?string $password = null): array
    {
        try {
            // Generate key pair
            $keyPair = KeyPair::generate();
            $address = $keyPair->getAddress();
            
            $walletData = [
                'address' => $address,
                'public_key' => $keyPair->getPublicKey(),
                'balance' => 0,
                'created_at' => time()
            ];
            
            // Save to database if available
            if (!$this->fileOnlyMode && $this->database) {
                $stmt = $this->database->prepare("
                    INSERT INTO wallets (address, public_key, balance) 
                    VALUES (?, ?, 0)
                ");
                
                $stmt->execute([
                    $address,
                    $keyPair->getPublicKey()
                ]);
            }
            
            // Create wallet creation transaction for blockchain
            $walletTx = [
                'type' => 'wallet_created',
                'hash' => hash('sha256', $address . time()),
                'from_address' => 'system',
                'to_address' => $address,
                'amount' => 0,
                'fee' => 0,
                'timestamp' => time(),
                'data' => [
                    'action' => 'create_wallet',
                    'public_key' => $keyPair->getPublicKey()
                ],
                'signature' => 'system_signature'
            ];
            
            // Add to pending transactions (will be included in next block)
            $this->addTransactionToMempool($walletTx);
            
            // Synchronize with network nodes
            $this->syncWithNodes($walletData, 'create');
            
            return [
                'address' => $address,
                'public_key' => $keyPair->getPublicKey(),
                'private_key' => $keyPair->getPrivateKey(),
                'mnemonic' => $keyPair->getMnemonic(),
                'balance' => 0,
                'mode' => $this->fileOnlyMode ? 'file_only' : 'database_and_blockchain',
                'created' => true
            ];
            
        } catch (Exception $e) {
            throw new Exception("Failed to create wallet: " . $e->getMessage());
        }
    }
    
    /**
     * Generate a new mnemonic phrase
     */
    public function generateMnemonic(): array
    {
        return Mnemonic::generate();
    }
    
    /**
     * Create wallet from mnemonic phrase
     */
    public function createWalletFromMnemonic(array $mnemonic): array
    {
        try {
            $keyPair = KeyPair::fromMnemonic($mnemonic);
            $address = $keyPair->getAddress();

            // Check if wallet already exists in database
            $existing = null;
            if (!$this->fileOnlyMode && $this->database) {
                $existing = $this->getWalletInfo($address);
            }
            
            // Check in blockchain binary storage
            $blockchainWallet = $this->getWalletFromBlockchain($address);
            
            if ($existing || $blockchainWallet) {
                throw new Exception("Wallet with this address already exists.");
            }

            // Save to database if available
            if (!$this->fileOnlyMode && $this->database) {
                $stmt = $this->database->prepare("
                    INSERT INTO wallets (address, public_key, balance, staked_balance, nonce, created_at, updated_at)
                    VALUES (?, ?, 0, 0, 0, NOW(), NOW())
                ");

                $stmt->execute([
                    $address,
                    $keyPair->getPublicKey()
                ]);
            }
            
            // Create transaction for blockchain
            $walletTx = [
                'type' => 'wallet_from_mnemonic',
                'hash' => hash('sha256', $address . 'mnemonic' . time()),
                'from_address' => 'system',
                'to_address' => $address,
                'amount' => 0,
                'fee' => 0,
                'timestamp' => time(),
                'data' => [
                    'action' => 'create_from_mnemonic',
                    'public_key' => $keyPair->getPublicKey()
                ],
                'signature' => 'system_signature'
            ];
            
            $this->addTransactionToMempool($walletTx);

            return [
                'address' => $address,
                'public_key' => $keyPair->getPublicKey(),
                'private_key' => $keyPair->getPrivateKey(),
                'balance' => 0,
                'mode' => $this->fileOnlyMode ? 'file_only' : 'database_and_blockchain'
            ];

        } catch (Exception $e) {
            throw new Exception("Failed to create wallet from mnemonic: " . $e->getMessage());
        }
    }
    
    /**
     * Restore wallet from mnemonic phrase
     */
    public function restoreWalletFromMnemonic(array $mnemonic): array
    {
        try {
            // Validate mnemonic
            if (!Mnemonic::validate($mnemonic)) {
                throw new Exception('Invalid mnemonic phrase');
            }

            // Generate KeyPair from mnemonic
            $keyPair = KeyPair::fromMnemonic($mnemonic);
            $address = $keyPair->getAddress();

            // Check in database (if available)
            $existingWallet = null;
            if (!$this->fileOnlyMode && $this->database) {
                $existingWallet = $this->getWalletInfo($address);
            }
            
            // Check in blockchain binary storage
            $blockchainWallet = $this->getWalletFromBlockchain($address);
            
            if ($existingWallet || $blockchainWallet) {
                // Wallet exists, return wallet data
                $balance = $existingWallet['balance'] ?? $blockchainWallet['balance'] ?? 0;
                
                return [
                    'address' => $address,
                    'public_key' => $keyPair->getPublicKey(),
                    'private_key' => $keyPair->getPrivateKey(),
                    'balance' => $balance,
                    'existing' => true,
                    'mode' => $this->fileOnlyMode ? 'file_only' : 'database_and_blockchain'
                ];
            }

            $walletData = [
                'address' => $address,
                'public_key' => $keyPair->getPublicKey(),
                'balance' => 0
            ];

            // Create new wallet record in database (if available)
            if (!$this->fileOnlyMode && $this->database) {
                $stmt = $this->database->prepare("
                    INSERT INTO wallets (address, public_key, balance) 
                    VALUES (?, ?, 0)
                ");
                
                $stmt->execute([
                    $address,
                    $keyPair->getPublicKey()
                ]);
            }

            // Create restore transaction for blockchain
            $restoreTx = [
                'type' => 'wallet_restored',
                'hash' => hash('sha256', $address . 'restore' . time()),
                'from_address' => 'system',
                'to_address' => $address,
                'amount' => 0,
                'fee' => 0,
                'timestamp' => time(),
                'data' => [
                    'action' => 'restore_wallet',
                    'public_key' => $keyPair->getPublicKey()
                ],
                'signature' => 'system_signature'
            ];
            
            $this->addTransactionToMempool($restoreTx);
            
            // Synchronize with network nodes
            $this->syncWithNodes($walletData, 'restore');

            return [
                'address' => $address,
                'public_key' => $keyPair->getPublicKey(),
                'private_key' => $keyPair->getPrivateKey(),
                'balance' => 0,
                'restored' => true,
                'mode' => $this->fileOnlyMode ? 'file_only' : 'database_and_blockchain'
            ];
        } catch (Exception $e) {
            throw new Exception('Failed to restore wallet from mnemonic: ' . $e->getMessage());
        }
    }
    
    /**
     * Get wallet balance from multiple sources
     */
    public function getBalance(string $address): float
    {
        $dbBalance = 0.0;
        $blockchainBalance = 0.0;
        
        // Get balance from database
        if (!$this->fileOnlyMode && $this->database) {
            $stmt = $this->database->prepare("
                SELECT balance, staked_balance 
                FROM wallets 
                WHERE address = ?
            ");
            
            $stmt->execute([$address]);
            $result = $stmt->fetch();
            
            if ($result) {
                $dbBalance = (float)$result['balance'] + (float)$result['staked_balance'];
            }
        }
        
        // Get balance from blockchain (calculated from transactions)
        $blockchainBalance = $this->calculateBalanceFromBlockchain($address);
        
        // Return the most recent balance (prefer blockchain as source of truth)
        return $blockchainBalance > 0 ? $blockchainBalance : $dbBalance;
    }
    
    /**
     * Get available balance (excluding staked)
     */
    public function getAvailableBalance(string $address): float
    {
        if (!$this->fileOnlyMode && $this->database) {
            $stmt = $this->database->prepare("
                SELECT balance 
                FROM wallets 
                WHERE address = ?
            ");
            
            $stmt->execute([$address]);
            $result = $stmt->fetch();
            
            return $result ? (float)$result['balance'] : 0.0;
        }
        
        return $this->calculateAvailableBalanceFromBlockchain($address);
    }
    
    /**
     * Get staked balance
     */
    public function getStakedBalance(string $address): float
    {
        if (!$this->fileOnlyMode && $this->database) {
            $stmt = $this->database->prepare("
                SELECT staked_balance 
                FROM wallets 
                WHERE address = ?
            ");
            
            $stmt->execute([$address]);
            $result = $stmt->fetch();
            
            return $result ? (float)$result['staked_balance'] : 0.0;
        }
        
        return $this->calculateStakedBalanceFromBlockchain($address);
    }
    
    /**
     * Get wallet information
     */
    public function getWalletInfo(string $address): ?array
    {
        if (!$this->fileOnlyMode && $this->database) {
            $stmt = $this->database->prepare("
                SELECT * FROM wallets WHERE address = ?
            ");
            
            $stmt->execute([$address]);
            return $stmt->fetch() ?: null;
        }
        
        return $this->getWalletFromBlockchain($address);
    }
    
    /**
     * List wallets with pagination
     */
    public function listWallets(int $limit = 20, int $offset = 0): array
    {
        if (!$this->fileOnlyMode && $this->database) {
            $stmt = $this->database->prepare("
                SELECT address, public_key, balance, staked_balance, created_at 
                FROM wallets 
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?
            ");
            
            $stmt->execute([$limit, $offset]);
            return $stmt->fetchAll();
        }
        
        return $this->getWalletsFromBlockchain($limit, $offset);
    }
    
    /**
     * Synchronize binary blockchain to database
     */
    public function syncBinaryToDatabase(): array
    {
        if (!$this->database || !$this->binaryStorage) {
            throw new Exception('Database or binary storage not available');
        }
        
        return $this->binaryStorage->exportToDatabase($this->database);
    }
    
    /**
     * Synchronize database to binary blockchain
     */
    public function syncDatabaseToBinary(): array
    {
        if (!$this->database || !$this->binaryStorage) {
            throw new Exception('Database or binary storage not available');
        }
        
        return $this->binaryStorage->importFromDatabase($this->database);
    }
    
    /**
     * Validate blockchain integrity
     */
    public function validateBlockchain(): array
    {
        if (!$this->binaryStorage) {
            throw new Exception('Binary storage not available');
        }
        
        return $this->binaryStorage->validateChain();
    }
    
    /**
     * Get blockchain statistics
     */
    public function getBlockchainStats(): array
    {
        $stats = [];
        
        if ($this->binaryStorage) {
            $stats['binary'] = $this->binaryStorage->getChainStats();
        }
        
        if (!$this->fileOnlyMode && $this->database) {
            $stmt = $this->database->query('SELECT COUNT(*) as blocks FROM blocks');
            $dbBlocks = $stmt->fetchColumn();
            
            $stmt = $this->database->query('SELECT COUNT(*) as transactions FROM transactions');
            $dbTransactions = $stmt->fetchColumn();
            
            $stmt = $this->database->query('SELECT COUNT(*) as wallets FROM wallets');
            $dbWallets = $stmt->fetchColumn();
            
            $stats['database'] = [
                'blocks' => $dbBlocks,
                'transactions' => $dbTransactions,
                'wallets' => $dbWallets
            ];
        }
        
        return $stats;
    }
    
    /**
     * Create blockchain backup
     */
    public function createBackup(string $backupPath): bool
    {
        if (!$this->binaryStorage) {
            throw new Exception('Binary storage not available');
        }
        
        return $this->binaryStorage->createBackup($backupPath);
    }
    
    // Private helper methods
    
    private function addTransactionToMempool(array $transaction): void
    {
        // Add to local mempool
        $this->mempoolTransactions[] = $transaction;
        
        // In production, this would also add to database mempool table
        if (!$this->fileOnlyMode && $this->database) {
            try {
                $stmt = $this->database->prepare("
                    INSERT INTO mempool (tx_hash, from_address, to_address, amount, fee, data, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $transaction['hash'],
                    $transaction['from_address'],
                    $transaction['to_address'],
                    $transaction['amount'],
                    $transaction['fee'],
                    json_encode($transaction['data'])
                ]);
            } catch (Exception $e) {
                error_log("Failed to add transaction to mempool: " . $e->getMessage());
            }
        }
    }
    
    private function getWalletFromBlockchain(string $address): ?array
    {
        if (!$this->binaryStorage) {
            return null;
        }
        
        // This would search through blockchain transactions for wallet-related operations
        // For now, return null as this requires more complex implementation
        return null;
    }
    
    private function calculateBalanceFromBlockchain(string $address): float
    {
        if (!$this->binaryStorage) {
            return 0.0;
        }
        
        // This would calculate balance by going through all transactions in blockchain
        // For now, return 0 as this requires complex implementation
        return 0.0;
    }
    
    private function calculateAvailableBalanceFromBlockchain(string $address): float
    {
        return $this->calculateBalanceFromBlockchain($address);
    }
    
    private function calculateStakedBalanceFromBlockchain(string $address): float
    {
        // This would calculate staked balance from staking transactions
        return 0.0;
    }
    
    private function getWalletsFromBlockchain(int $limit, int $offset): array
    {
        // This would extract wallet information from blockchain
        return [];
    }
    
    private function syncWithNodes(array $walletData, string $action): bool
    {
        try {
            $nodes = $this->config['network']['nodes'] ?? [];
            
            if (empty($nodes)) {
                return true;
            }
            
            $syncData = [
                'action' => $action,
                'wallet' => [
                    'address' => $walletData['address'],
                    'public_key' => $walletData['public_key'],
                    'balance' => $walletData['balance'] ?? 0,
                    'timestamp' => time()
                ],
                'signature' => $this->signSyncData($walletData)
            ];
            
            $successCount = 0;
            foreach ($nodes as $nodeUrl) {
                if ($this->sendToNode($nodeUrl, $syncData)) {
                    $successCount++;
                }
            }
            
            $successThreshold = ceil(count($nodes) / 2);
            return $successCount >= $successThreshold;
            
        } catch (Exception $e) {
            error_log("Failed to sync with nodes: " . $e->getMessage());
            return false;
        }
    }
    
    private function signSyncData(array $walletData): string
    {
        $dataToSign = json_encode([
            'address' => $walletData['address'],
            'public_key' => $walletData['public_key'],
            'timestamp' => time()
        ]);
        
        return hash('sha256', $dataToSign . ($this->config['network']['secret'] ?? 'default_secret'));
    }
    
    private function sendToNode(string $nodeUrl, array $data): bool
    {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $nodeUrl . '/api/wallet/sync',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'User-Agent: PHPBlockchain/1.0'
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200;
            
        } catch (Exception $e) {
            error_log("Failed to send data to node {$nodeUrl}: " . $e->getMessage());
            return false;
        }
    }
}

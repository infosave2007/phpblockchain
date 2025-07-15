<?php
declare(strict_types=1);

namespace Blockchain\Wallet;

use Blockchain\Core\Cryptography\KeyPair;
use Blockchain\Core\Cryptography\Mnemonic;
use Blockchain\Core\Cryptography\Signature;
use Blockchain\Core\Transaction\Transaction;
use PDO;
use Exception;

/**
 * Функция для записи логов в файл WalletManager
 */
function writeWalletLog($message, $level = 'INFO') {
    // Проверяем режим отладки из глобального конфига
    global $config;
    $debugMode = $config['debug_mode'] ?? true;
    
    if (!$debugMode && $level === 'DEBUG') {
        return; // Не записываем DEBUG логи если отладка выключена
    }
    
    $baseDir = dirname(__DIR__);
    $logDir = $baseDir . '/logs';
    
    // Создаем папку logs если её нет
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/wallet_api.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [WalletManager] [{$level}] {$message}" . PHP_EOL;
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

/**
 * Wallet Manager
 * 
 * Handles wallet creation, management, and transactions
 */
class WalletManager
{
    private PDO $database;
    private array $config;
    
    public function __construct(PDO $database, array $config = [])
    {
        $this->database = $database;
        $this->config = $config;
    }
    
    /**
     * Create a new wallet
     */
    public function createWallet(?string $password = null): array
    {
        try {
            // Generate proper mnemonic first
            $mnemonic = Mnemonic::generate();
            
            // Create key pair from mnemonic  
            $keyPair = KeyPair::fromMnemonic(implode(' ', $mnemonic), '');
            $address = $keyPair->getAddress();
            
            // Store wallet in database
            $stmt = $this->database->prepare("
                INSERT INTO wallets (address, public_key, balance) 
                VALUES (?, ?, 0)
            ");
            
            $stmt->execute([
                $address,
                $keyPair->getPublicKey()
            ]);
            
            return [
                'address' => $address,
                'public_key' => $keyPair->getPublicKey(),
                'private_key' => $keyPair->getPrivateKey(),
                'mnemonic' => $mnemonic // Use the generated mnemonic, not KeyPair's getMnemonic()
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
     * Create a new wallet from a mnemonic phrase
     */
    public function createWalletFromMnemonic(array $mnemonic): array
    {
        try {
            $keyPair = KeyPair::fromMnemonic($mnemonic);
            $address = $keyPair->getAddress();

            // Check if wallet already exists
            $existing = $this->getWalletInfo($address);
            if ($existing) {
                throw new Exception("Wallet with this address already exists.");
            }

            // Store wallet in database
            $stmt = $this->database->prepare("
                INSERT INTO wallets (address, public_key, balance, staked_balance, nonce, created_at, updated_at)
                VALUES (?, ?, 0, 0, 0, NOW(), NOW())
            ");

            $stmt->execute([
                $address,
                $keyPair->getPublicKey()
            ]);

            return [
                'address' => $address,
                'public_key' => $keyPair->getPublicKey(),
                'private_key' => $keyPair->getPrivateKey()
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
            // Debug: проверим что получили
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Received mnemonic count: " . count($mnemonic), 'DEBUG');
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Mnemonic words: " . implode(' ', $mnemonic), 'DEBUG');
            
            // Validate mnemonic
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Starting mnemonic validation", 'DEBUG');
            $isValid = Mnemonic::validate($mnemonic);
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Mnemonic validation result: " . ($isValid ? 'true' : 'false'), 'DEBUG');
            
            if (!$isValid) {
                writeWalletLog("WalletManager::restoreWalletFromMnemonic - Invalid mnemonic phrase", 'ERROR');
                throw new Exception('Invalid mnemonic phrase');
            }

            // Generate KeyPair from mnemonic - это чисто математическая операция
            try {
                writeWalletLog("WalletManager::restoreWalletFromMnemonic - Starting KeyPair generation", 'DEBUG');
                $keyPair = KeyPair::fromMnemonic($mnemonic);
                writeWalletLog("WalletManager::restoreWalletFromMnemonic - KeyPair generated successfully", 'DEBUG');
            } catch (Exception $e) {
                writeWalletLog("WalletManager::restoreWalletFromMnemonic - KeyPair generation failed: " . $e->getMessage(), 'ERROR');
                throw new Exception('Failed to generate keys from mnemonic: ' . $e->getMessage());
            }
            
            $address = $keyPair->getAddress();
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Generated address: " . $address, 'DEBUG');

            // Проверяем существующий кошелек в БД
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Checking for existing wallet in database", 'DEBUG');
            $existingWallet = $this->getWalletInfo($address);
            
            // Получаем баланс из блокчейна (если есть транзакции)
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Calculating balances from blockchain", 'DEBUG');
            $blockchainBalance = $this->calculateBalanceFromBlockchain($address);
            $blockchainStaked = $this->calculateStakedBalanceFromBlockchain($address);
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Blockchain balance: $blockchainBalance, staked: $blockchainStaked", 'DEBUG');
            
            if ($existingWallet) {
                writeWalletLog("WalletManager::restoreWalletFromMnemonic - Wallet exists in database, updating from blockchain", 'INFO');
                // Кошелек уже есть в БД - обновляем данные из блокчейна
                if ($blockchainBalance > 0 || $blockchainStaked > 0) {
                    $this->updateBalance($address, $blockchainBalance);
                    $this->updateStakedBalance($address, $blockchainStaked);
                }
                
                return [
                    'address' => $address,
                    'public_key' => $keyPair->getPublicKey(),
                    'private_key' => $keyPair->getPrivateKey(),
                    'balance' => max($existingWallet['balance'] ?? 0, $blockchainBalance),
                    'staked_balance' => max($existingWallet['staked_balance'] ?? 0, $blockchainStaked),
                    'existing' => true,
                    'restored_from' => 'database'
                ];
            }
            
            // Кошелек не найден в БД, но может существовать в блокчейне
            if ($blockchainBalance > 0 || $blockchainStaked > 0) {
                writeWalletLog("WalletManager::restoreWalletFromMnemonic - Wallet found in blockchain, creating database record", 'INFO');
                // Кошелек существует в блокчейне - восстанавливаем в БД
                $stmt = $this->database->prepare("
                    INSERT INTO wallets (address, public_key, balance, staked_balance, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, NOW(), NOW())
                ");
                
                $stmt->execute([
                    $address,
                    $keyPair->getPublicKey(),
                    $blockchainBalance,
                    $blockchainStaked
                ]);

                return [
                    'address' => $address,
                    'public_key' => $keyPair->getPublicKey(),
                    'private_key' => $keyPair->getPrivateKey(),
                    'balance' => $blockchainBalance,
                    'staked_balance' => $blockchainStaked,
                    'restored' => true,
                    'restored_from' => 'blockchain'
                ];
            }
            
            // Кошелек не найден ни в БД, ни в блокчейне
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Wallet not found in blockchain or database, creating database record", 'INFO');
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - About to insert wallet: " . $address, 'DEBUG');
            
            // Сохраняем кошелек в базу данных даже если он не найден в блокчейне
            // Это позволит использовать его для получения средств
            $stmt = $this->database->prepare("
                INSERT INTO wallets (address, public_key, balance, staked_balance, created_at, updated_at) 
                VALUES (?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                    public_key = VALUES(public_key),
                    updated_at = NOW()
            ");
            
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - SQL prepared, executing insert", 'DEBUG');
            
            $executeResult = $stmt->execute([
                $address,
                $keyPair->getPublicKey(),
                0,
                0
            ]);
            
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Execute result: " . json_encode($executeResult), 'DEBUG');
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Affected rows: " . $stmt->rowCount(), 'DEBUG');
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Wallet record created in database", 'INFO');
            
            // Возвращаем параметры кошелька с записью в базе
            // ВАЖНО: указываем что нужна регистрация в блокчейне
            return [
                'address' => $address,
                'public_key' => $keyPair->getPublicKey(),
                'private_key' => $keyPair->getPrivateKey(),
                'balance' => 0,
                'staked_balance' => 0,
                'restored' => true,
                'restored_from' => 'mnemonic_needs_blockchain_registration',
                'note' => 'Wallet restored from mnemonic and saved to database. Will be registered in blockchain.',
                'needs_blockchain_registration' => true
            ];
            
        } catch (Exception $e) {
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Exception: " . $e->getMessage(), 'ERROR');
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Stack trace: " . $e->getTraceAsString(), 'DEBUG');
            throw new Exception('Failed to restore wallet from mnemonic: ' . $e->getMessage());
        }
    }
    
    /**
     * Get wallet balance
     */
    public function getBalance(string $address): float
    {
        $availableBalance = $this->getAvailableBalance($address);
        $stakedBalance = $this->getStakedBalance($address);
        
        return $availableBalance + $stakedBalance;
    }
    
    /**
     * Get available balance (excluding staked)
     */
    public function getAvailableBalance(string $address): float
    {
        $stmt = $this->database->prepare("
            SELECT balance FROM wallets WHERE address = ?
        ");
        
        $stmt->execute([$address]);
        $result = $stmt->fetch();
        
        return $result ? (float)$result['balance'] : 0.0;
    }
    
    /**
     * Get staked balance - calculated based on blockchain transactions
     */
    public function getStakedBalance(string $address): float
    {
        // Calculate staked balance from blockchain transactions (most reliable)
        $blockchainStaked = $this->calculateStakedBalanceFromBlockchain($address);
        
        // Get staking records from staking table
        $stmt = $this->database->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_staked 
            FROM staking 
            WHERE staker = ? AND status = 'active'
        ");
        $stmt->execute([$address]);
        $result = $stmt->fetch();
        $tableStaked = (float)$result['total_staked'];
        
        // Get legacy staked balance from wallets table
        $stmt = $this->database->prepare("
            SELECT COALESCE(staked_balance, 0) as legacy_staked
            FROM wallets 
            WHERE address = ?
        ");
        $stmt->execute([$address]);
        $result = $stmt->fetch();
        $legacyStaked = $result ? (float)$result['legacy_staked'] : 0.0;
        
        // Priority: blockchain transactions > staking table > legacy field
        if ($blockchainStaked > 0) {
            return $blockchainStaked;
        } elseif ($tableStaked > 0) {
            return $tableStaked;
        } else {
            return $legacyStaked;
        }
    }
    
    /**
     * Update wallet balance
     */
    public function updateBalance(string $address, float $balance): bool
    {
        $stmt = $this->database->prepare("
            UPDATE wallets 
            SET balance = ?, updated_at = NOW() 
            WHERE address = ?
        ");
        
        return $stmt->execute([$balance, $address]);
    }
    
    /**
     * Update staked balance
     */
    /**
     * Update staked balance directly in wallets table
     * @deprecated This method is for legacy sync purposes only. 
     * New staking should use the staking table.
     */
    public function updateStakedBalance(string $address, float $stakedBalance): bool
    {
        $stmt = $this->database->prepare("
            UPDATE wallets 
            SET staked_balance = ?, updated_at = NOW() 
            WHERE address = ?
        ");
        
        return $stmt->execute([$stakedBalance, $address]);
    }
    
    /**
     * Create and sign transaction
     */
    public function createTransaction(
        string $fromAddress,
        string $toAddress,
        float $amount,
        float $fee = 0.001,
        ?string $privateKey = null,
        ?string $data = null
    ): Transaction {
        // Validate balance
        $availableBalance = $this->getAvailableBalance($fromAddress);
        
        if ($availableBalance < ($amount + $fee)) {
            throw new Exception("Insufficient balance");
        }
        
        // Get nonce
        $nonce = $this->getNextNonce($fromAddress);
        
        // Create transaction
        $transaction = new Transaction(
            $fromAddress,
            $toAddress,
            $amount,
            $fee,
            $nonce,
            $data
        );
        
        // Sign transaction if private key provided
        if ($privateKey) {
            $signature = Signature::sign($transaction->getHash(), $privateKey);
            $transaction->setSignature($signature);
        }
        
        return $transaction;
    }
    
    /**
     * Get next nonce for address
     */
    public function getNextNonce(string $address): int
    {
        // Get current nonce from wallet
        $stmt = $this->database->prepare("
            SELECT nonce FROM wallets WHERE address = ?
        ");
        
        $stmt->execute([$address]);
        $result = $stmt->fetch();
        
        $currentNonce = $result ? (int)$result['nonce'] : 0;
        
        // Check for pending transactions with higher nonce
        $stmt = $this->database->prepare("
            SELECT MAX(nonce) as max_nonce 
            FROM mempool 
            WHERE from_address = ?
        ");
        
        $stmt->execute([$address]);
        $result = $stmt->fetch();
        
        $pendingNonce = $result && $result['max_nonce'] ? (int)$result['max_nonce'] : -1;
        
        return max($currentNonce, $pendingNonce) + 1;
    }
    
    /**
     * Update nonce after transaction confirmation
     */
    public function updateNonce(string $address, int $nonce): bool
    {
        $stmt = $this->database->prepare("
            UPDATE wallets 
            SET nonce = ?, updated_at = NOW() 
            WHERE address = ? AND nonce < ?
        ");
        
        return $stmt->execute([$nonce, $address, $nonce]);
    }
    
    /**
     * Get transaction history
     */
    public function getTransactionHistory(string $address, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->database->prepare("
            SELECT 
                hash,
                block_hash,
                block_height,
                from_address,
                to_address,
                amount,
                fee,
                status,
                timestamp,
                data
            FROM transactions 
            WHERE from_address = ? OR to_address = ?
            ORDER BY timestamp DESC 
            LIMIT $limit OFFSET $offset
        ");
        
        $stmt->execute([$address, $address]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get wallet info
     */
    public function getWalletInfo(string $address): ?array
    {
        $stmt = $this->database->prepare("
            SELECT 
                address,
                public_key,
                balance,
                staked_balance,
                nonce,
                created_at,
                updated_at
            FROM wallets 
            WHERE address = ?
        ");
        
        $stmt->execute([$address]);
        
        return $stmt->fetch() ?: null;
    }
    
    /**
     * List all wallets
     */
    public function listWallets(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->database->prepare("
            SELECT 
                address,
                balance,
                staked_balance,
                created_at
            FROM wallets 
            ORDER BY created_at DESC 
            LIMIT $limit OFFSET $offset
        ");
        
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Import wallet from private key
     */
    public function importWallet(string $privateKey): array
    {
        try {
            $keyPair = KeyPair::fromPrivateKey($privateKey);
            $address = $keyPair->getAddress();
            
            // Check if wallet already exists
            $existing = $this->getWalletInfo($address);
            
            if (!$existing) {
                // Create wallet entry
                $stmt = $this->database->prepare("
                    INSERT INTO wallets (address, public_key, balance) 
                    VALUES (?, ?, 0)
                ");
                
                $stmt->execute([
                    $address,
                    $keyPair->getPublicKey()
                ]);
            }
            
            return [
                'address' => $address,
                'public_key' => $keyPair->getPublicKey(),
                'imported' => true
            ];
            
        } catch (Exception $e) {
            throw new Exception("Failed to import wallet: " . $e->getMessage());
        }
    }
    
    /**
     * Validate address format
     */
    public function isValidAddress(string $address): bool
    {
        // Blockchain addresses should be 42 characters starting with 0x
        return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1;
    }
    
    /**
     * Get total supply
     */
    public function getTotalSupply(): float
    {
        $stmt = $this->database->query("
            SELECT SUM(balance + staked_balance) as total 
            FROM wallets
        ");
        
        $result = $stmt->fetch();
        
        return $result ? (float)$result['total'] : 0.0;
    }

    /**
     * Send transaction to mempool
     */
    public function sendTransaction(Transaction $transaction): bool
    {
        try {
            // Validate transaction
            if (!$this->validateTransaction($transaction)) {
                throw new Exception("Invalid transaction");
            }

            // Add to mempool
            $stmt = $this->database->prepare("
                INSERT INTO mempool (
                    hash, from_address, to_address, amount, fee, 
                    nonce, gas_limit, gas_price, data, signature, 
                    timestamp, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            return $stmt->execute([
                $transaction->getHash(),
                $transaction->getFromAddress(),
                $transaction->getToAddress(),
                $transaction->getAmount(),
                $transaction->getFee(),
                $transaction->getNonce(),
                $transaction->getGasLimit(),
                $transaction->getGasPrice(),
                $transaction->getData(),
                $transaction->getSignature(),
                $transaction->getTimestamp(),
                'pending'
            ]);

        } catch (Exception $e) {
            throw new Exception("Failed to send transaction: " . $e->getMessage());
        }
    }

    /**
     * Validate transaction
     */
    private function validateTransaction(Transaction $transaction): bool
    {
        // Check signature
        if (!$transaction->getSignature()) {
            return false;
        }

        // Check balance
        $availableBalance = $this->getAvailableBalance($transaction->getFromAddress());
        $totalAmount = $transaction->getAmount() + $transaction->getFee();
        
        if ($availableBalance < $totalAmount) {
            return false;
        }

        // Verify signature
        $walletInfo = $this->getWalletInfo($transaction->getFromAddress());
        if (!$walletInfo) {
            return false;
        }

        return Signature::verify(
            $transaction->getHash(),
            $transaction->getSignature(),
            $walletInfo['public_key']
        );
    }

    /**
     * Stake tokens
     */
    public function stake(string $address, float $amount, string $privateKey): bool
    {
        try {
            $availableBalance = $this->getAvailableBalance($address);
            
            if ($availableBalance < $amount) {
                throw new Exception("Insufficient balance for staking");
            }

            // Start transaction
            $this->database->beginTransaction();

            // Update wallet balance (subtract staked amount)
            $newBalance = $availableBalance - $amount;
            $stmt = $this->database->prepare("
                UPDATE wallets 
                SET balance = ?, updated_at = NOW() 
                WHERE address = ?
            ");
            $stmt->execute([$newBalance, $address]);

            // Add staking record
            $stmt = $this->database->prepare("
                INSERT INTO staking (staker, amount, status, start_date, period_days)
                VALUES (?, ?, 'active', NOW(), 30)
            ");
            $stmt->execute([$address, $amount]);

            // Record staking transaction
            $this->recordStakingTransaction($address, $amount, 'stake');

            $this->database->commit();
            return true;

        } catch (Exception $e) {
            $this->database->rollBack();
            throw new Exception("Staking failed: " . $e->getMessage());
        }
    }

    /**
     * Unstake tokens
     */
    public function unstake(string $address, float $amount, string $privateKey): bool
    {
        try {
            $stakedBalance = $this->getStakedBalance($address);
            
            if ($stakedBalance < $amount) {
                throw new Exception("Insufficient staked balance");
            }

            // Start transaction
            $this->database->beginTransaction();

            // Update wallet balance (add unstaked amount)
            $currentBalance = $this->getAvailableBalance($address);
            $newBalance = $currentBalance + $amount;
            $stmt = $this->database->prepare("
                UPDATE wallets 
                SET balance = ?, updated_at = NOW() 
                WHERE address = ?
            ");
            $stmt->execute([$newBalance, $address]);

            // Update staking records - mark oldest active stakes as completed
            $stmt = $this->database->prepare("
                UPDATE staking 
                SET status = 'completed', end_date = NOW()
                WHERE staker = ? AND status = 'active' AND amount <= ?
                ORDER BY start_date ASC
                LIMIT 1
            ");
            $stmt->execute([$address, $amount]);

            // If exact amount match not found, need to split staking record
            if ($stmt->rowCount() == 0) {
                // Find first staking record larger than amount
                $stmt = $this->database->prepare("
                    SELECT id, amount FROM staking 
                    WHERE staker = ? AND status = 'active' AND amount > ?
                    ORDER BY start_date ASC
                    LIMIT 1
                ");
                $stmt->execute([$address, $amount]);
                $stakeRecord = $stmt->fetch();
                
                if ($stakeRecord) {
                    $remainingAmount = $stakeRecord['amount'] - $amount;
                    
                    // Update original record to remaining amount
                    $stmt = $this->database->prepare("
                        UPDATE staking SET amount = ? WHERE id = ?
                    ");
                    $stmt->execute([$remainingAmount, $stakeRecord['id']]);
                }
            }

            // Record unstaking transaction
            $this->recordStakingTransaction($address, $amount, 'unstake');

            $this->database->commit();
            return true;

        } catch (Exception $e) {
            $this->database->rollBack();
            throw new Exception("Unstaking failed: " . $e->getMessage());
        }
    }

    /**
     * Record staking transaction
     */
    private function recordStakingTransaction(string $address, float $amount, string $type): void
    {
        $stmt = $this->database->prepare("
            INSERT INTO transactions (
                hash, from_address, to_address, amount, fee,
                timestamp, status, type, data
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $data = json_encode(['staking_type' => $type]);
        $hash = hash('sha256', $address . $amount . $type . time());

        $stmt->execute([
            $hash,
            $address,
            $type === 'stake' ? 'staking_pool' : $address,
            $amount,
            0, // No fee for staking
            time(),
            'confirmed',
            $type,
            $data
        ]);
    }

    /**
     * Get staking rewards
     */
    public function getStakingRewards(string $address): float
    {
        // Calculate staking rewards based on staked amount and time
        $walletInfo = $this->getWalletInfo($address);
        if (!$walletInfo) {
            return 0.0;
        }

        $stakedBalance = (float)$walletInfo['staked_balance'];
        if ($stakedBalance <= 0) {
            return 0.0;
        }

        // Simple reward calculation: 5% APY
        $stakingPeriod = time() - strtotime($walletInfo['updated_at']);
        $yearSeconds = 365 * 24 * 60 * 60;
        $rewardRate = 0.05; // 5% APY
        
        return ($stakedBalance * $rewardRate * $stakingPeriod) / $yearSeconds;
    }

    /**
     * Claim staking rewards
     */
    public function claimRewards(string $address): float
    {
        $rewards = $this->getStakingRewards($address);
        
        if ($rewards > 0) {
            $currentBalance = $this->getAvailableBalance($address);
            $newBalance = $currentBalance + $rewards;
            
            $this->updateBalance($address, $newBalance);
            $this->recordStakingTransaction($address, $rewards, 'reward_claim');
        }
        
        return $rewards;
    }

    /**
     * Get wallet statistics
     */
    public function getWalletStats(string $address): array
    {
        $walletInfo = $this->getWalletInfo($address);
        if (!$walletInfo) {
            return [];
        }

        // Get transaction count
        $stmt = $this->database->prepare("
            SELECT COUNT(*) as tx_count 
            FROM transactions 
            WHERE from_address = ? OR to_address = ?
        ");
        $stmt->execute([$address, $address]);
        $txCount = $stmt->fetchColumn();

        // Get last transaction
        $stmt = $this->database->prepare("
            SELECT timestamp 
            FROM transactions 
            WHERE from_address = ? OR to_address = ?
            ORDER BY timestamp DESC 
            LIMIT 1
        ");
        $stmt->execute([$address, $address]);
        $lastTx = $stmt->fetchColumn();

        return [
            'address' => $address,
            'balance' => (float)$walletInfo['balance'],
            'staked_balance' => (float)$walletInfo['staked_balance'],
            'total_balance' => (float)$walletInfo['balance'] + (float)$walletInfo['staked_balance'],
            'transaction_count' => (int)$txCount,
            'last_transaction' => $lastTx ? (int)$lastTx : null,
            'staking_rewards' => $this->getStakingRewards($address),
            'created_at' => $walletInfo['created_at'],
            'updated_at' => $walletInfo['updated_at']
        ];
    }

    /**
     * Export wallet data
     */
    public function exportWallet(string $address): array
    {
        $walletInfo = $this->getWalletInfo($address);
        if (!$walletInfo) {
            throw new Exception("Wallet not found");
        }

        $transactions = $this->getTransactionHistory($address, 1000);
        
        return [
            'wallet' => $walletInfo,
            'transactions' => $transactions,
            'stats' => $this->getWalletStats($address),
            'exported_at' => time()
        ];
    }

    /**
     * Backup wallet
     */
    public function backupWallet(string $address, string $password): string
    {
        $walletData = $this->exportWallet($address);
        
        // Encrypt wallet data
        $encrypted = $this->encryptWalletData($walletData, $password);
        
        // Save backup file
        $backupDir = '../storage/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $filename = 'wallet_' . substr($address, 0, 8) . '_' . date('Y-m-d_H-i-s') . '.backup';
        $filepath = $backupDir . '/' . $filename;
        
        file_put_contents($filepath, $encrypted);
        
        return $filepath;
    }

    /**
     * Restore wallet from backup
     */
    public function restoreWallet(string $backupFile, string $password): array
    {
        if (!file_exists($backupFile)) {
            throw new Exception("Backup file not found");
        }
        
        $encrypted = file_get_contents($backupFile);
        $walletData = $this->decryptWalletData($encrypted, $password);
        
        if (!$walletData) {
            throw new Exception("Invalid password or corrupted backup");
        }
        
        // Restore wallet data (implementation would depend on specific requirements)
        return $walletData;
    }

    /**
     * Encrypt wallet data
     */
    private function encryptWalletData(array $data, string $password): string
    {
        $json = json_encode($data);
        $salt = random_bytes(16);
        $key = hash_pbkdf2('sha256', $password, $salt, 10000, 32, true);
        $iv = random_bytes(16);
        
        $encrypted = openssl_encrypt($json, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        
        return base64_encode($salt . $iv . $encrypted);
    }

    /**
     * Decrypt wallet data
     */
    private function decryptWalletData(string $encrypted, string $password): ?array
    {
        try {
            $data = base64_decode($encrypted);
            $salt = substr($data, 0, 16);
            $iv = substr($data, 16, 16);
            $encrypted = substr($data, 32);
            
            $key = hash_pbkdf2('sha256', $password, $salt, 10000, 32, true);
            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            
            return json_decode($decrypted, true);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get database connection
     */
    public function getDatabase(): PDO
    {
        return $this->database;
    }
    
    /**
     * Get transaction by hash
     */
    public function getTransactionByHash(string $hash): ?array
    {
        $stmt = $this->database->prepare("
            SELECT 
                hash,
                block_hash,
                block_height,
                from_address,
                to_address,
                amount,
                fee,
                status,
                timestamp,
                data,
                signature
            FROM transactions 
            WHERE hash = ?
        ");
        
        $stmt->execute([$hash]);
        $transaction = $stmt->fetch();
        
        if (!$transaction) {
            return null;
        }
        
        // Parse data field to get memo
        $transactionData = json_decode($transaction['data'] ?? '{}', true);
        $transaction['memo'] = $transactionData['memo'] ?? '';
        
        return $transaction;
    }

    /**
     * Calculate balance from blockchain transactions
     */
    public function calculateBalanceFromBlockchain(string $address): float
    {
        try {
            writeWalletLog("WalletManager::calculateBalanceFromBlockchain - Calculating for address: $address", 'DEBUG');
            
            // Get all confirmed transactions for this address
            $stmt = $this->database->prepare("
                SELECT 
                    from_address, to_address, amount, fee
                FROM transactions 
                WHERE (from_address = ? OR to_address = ?) 
                AND status = 'confirmed'
                ORDER BY timestamp ASC
            ");
            
            $stmt->execute([$address, $address]);
            $transactions = $stmt->fetchAll();
            
            $balance = 0.0;
            
            foreach ($transactions as $tx) {
                if ($tx['to_address'] === $address) {
                    // Incoming transaction
                    $balance += (float)$tx['amount'];
                    writeWalletLog("WalletManager::calculateBalanceFromBlockchain - Incoming: +" . $tx['amount'], 'DEBUG');
                }
                
                if ($tx['from_address'] === $address && $tx['from_address'] !== 'genesis' && $tx['from_address'] !== 'genesis_address') {
                    // Outgoing transaction (excluding genesis transactions)
                    $balance -= (float)$tx['amount'] + (float)$tx['fee'];
                    writeWalletLog("WalletManager::calculateBalanceFromBlockchain - Outgoing: -" . ($tx['amount'] + $tx['fee']), 'DEBUG');
                }
            }
            
            writeWalletLog("WalletManager::calculateBalanceFromBlockchain - Final balance: $balance", 'DEBUG');
            return max(0.0, $balance); // Balance cannot be negative
            
        } catch (Exception $e) {
            writeWalletLog("WalletManager::calculateBalanceFromBlockchain - Error: " . $e->getMessage(), 'ERROR');
            return 0.0;
        }
    }

    /**
     * Calculate staked balance from blockchain staking records
     */
    public function calculateStakedBalanceFromBlockchain(string $address): float
    {
        try {
            writeWalletLog("WalletManager::calculateStakedBalanceFromBlockchain - Calculating for address: $address", 'DEBUG');
            
            // Calculate staked balance from actual blockchain transactions
            $stmt = $this->database->prepare("
                SELECT 
                    SUM(CASE 
                        WHEN to_address = 'staking_contract' THEN amount
                        WHEN from_address = 'staking_contract' AND to_address = ? THEN -amount  
                        ELSE 0 
                    END) as staked_balance
                FROM transactions 
                WHERE ((from_address = ? AND to_address = 'staking_contract') 
                       OR (from_address = 'staking_contract' AND to_address = ?))
                AND status = 'confirmed'
            ");
            
            $stmt->execute([$address, $address, $address]);
            $result = $stmt->fetch();
            $stakedBalance = max(0.0, (float)($result['staked_balance'] ?? 0));
            
            writeWalletLog("WalletManager::calculateStakedBalanceFromBlockchain - Staked balance: $stakedBalance", 'DEBUG');
            return $stakedBalance;
            
        } catch (Exception $e) {
            writeWalletLog("WalletManager::calculateStakedBalanceFromBlockchain - Error: " . $e->getMessage(), 'ERROR');
            return 0.0;
        }
    }

    /**
     * Get wallet by address
     */
    public function getWalletByAddress(string $address): ?array
    {
        return $this->getWalletInfo($address);
    }
}

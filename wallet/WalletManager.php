<?php
declare(strict_types=1);

namespace Blockchain\Wallet;

use Blockchain\Core\Cryptography\KeyPair;
use Blockchain\Core\Cryptography\Mnemonic;
use Blockchain\Core\Cryptography\Signature;
use Blockchain\Core\Transaction\Transaction;
use PDO;
use Exception;

require_once __DIR__ . '/StakingRateHelper.php';

/**
 * Function to write logs to WalletManager log file
 */
function writeWalletLog($message, $level = 'INFO') {
    // Check debug mode from global config
    global $config;
    $debugMode = $config['debug_mode'] ?? true;
    
    if (!$debugMode && $level === 'DEBUG') {
        return; // Don't write DEBUG logs if debug mode is disabled
    }
    
    $baseDir = dirname(__DIR__);
    $logDir = $baseDir . '/logs';
    
    // Create logs folder if it does not exist
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
    private PDO $pdo; // Alias for backward compatibility with queueBackgroundOperation
    private array $config;
    
    public function __construct(PDO $database, array $config = [])
    {
        $this->database = $database;
        $this->pdo = $database; // Alias for backward compatibility with queueBackgroundOperation
        $this->config = $config;
    }
    
    /**
     * Create a new wallet
     * @param string|null $password Password for wallet encryption (currently unused)
     * @param bool $saveToDatabase Whether to save wallet to database (default: true)
     */
    public function createWallet(?string $password = null, bool $saveToDatabase = true): array
    {
        try {
            // Generate proper mnemonic first
            $mnemonic = Mnemonic::generate();
            
            // Create key pair from mnemonic  
            $keyPair = KeyPair::fromMnemonic($mnemonic);
            $address = $keyPair->getAddress();
            
            // Store wallet in database only if requested
            if ($saveToDatabase) {
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
            // Debug: verify what we received
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

            // Generate KeyPair from mnemonic - pure mathematical operation
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

            // Check existing wallet in the database
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Checking for existing wallet in database", 'DEBUG');
            $existingWallet = $this->getWalletInfo($address);
            
            // Get balance from blockchain (if transactions exist)
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Calculating balances from blockchain", 'DEBUG');
            $blockchainBalance = $this->calculateBalanceFromBlockchain($address);
            $blockchainStaked = $this->calculateStakedBalanceFromBlockchain($address);
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Blockchain balance: $blockchainBalance, staked: $blockchainStaked", 'DEBUG');
            
            if ($existingWallet) {
                writeWalletLog("WalletManager::restoreWalletFromMnemonic - Wallet exists in database, updating from blockchain", 'INFO');
                // If the stored public key is a placeholder, update it with the real one derived from mnemonic
                if (!isset($existingWallet['public_key']) || $existingWallet['public_key'] === '' || $existingWallet['public_key'] === 'placeholder_public_key') {
                    try {
                        $upd = $this->database->prepare("UPDATE wallets SET public_key = ?, updated_at = NOW() WHERE address = ?");
                        $upd->execute([$keyPair->getPublicKey(), $address]);
                        writeWalletLog("WalletManager::restoreWalletFromMnemonic - Replaced placeholder public key with real one for $address", 'INFO');
                        // Refresh existing wallet info after update (optional)
                        $existingWallet['public_key'] = $keyPair->getPublicKey();
                    } catch (Exception $e) {
                        writeWalletLog("WalletManager::restoreWalletFromMnemonic - Failed to update placeholder public key: " . $e->getMessage(), 'ERROR');
                    }
                }
                // Wallet already exists in DB - update data from blockchain
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
            
            // Wallet not found in DB but may exist in blockchain
            if ($blockchainBalance > 0 || $blockchainStaked > 0) {
                writeWalletLog("WalletManager::restoreWalletFromMnemonic - Wallet found in blockchain, creating database record", 'INFO');
                // Wallet exists in the blockchain - restore in DB
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
            
            // Wallet not found in DB or blockchain
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - Wallet not found in blockchain or database, creating database record", 'INFO');
            writeWalletLog("WalletManager::restoreWalletFromMnemonic - About to insert wallet: " . $address, 'DEBUG');
            
            // Save wallet to the database even if not found in blockchain
            // This allows it to be used to receive funds
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
            
            // Return wallet parameters with DB record
            // IMPORTANT: flag that blockchain registration is required
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
        // Normalize address to lowercase to avoid duplicates due to case
        $address = strtolower($address);

        // Check if we're in a transaction but don't force commit
        $inTransaction = $this->database->inTransaction();
        if ($inTransaction) {
            writeWalletLog("WalletManager::getAvailableBalance - Using existing transaction", 'DEBUG');
        }

        $stmt = $this->database->prepare("
            SELECT balance FROM wallets WHERE address = ?
        ");
        
        $stmt->execute([$address]);
        $result = $stmt->fetch();
        
        if (!$result) {
            // Auto-create wallet for MetaMask/external addresses with zero balance
            writeWalletLog("WalletManager::getAvailableBalance - Auto-creating wallet for address $address", 'DEBUG');
            $this->autoCreateWallet($address);
            return 0.0;
        }
        
        return (float)$result['balance'];
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
     * Check if wallet exists in database
     */
    public function walletExists(string $address): bool
    {
        try {
            $stmt = $this->database->prepare("SELECT COUNT(*) FROM wallets WHERE address = ?");
            $stmt->execute([strtolower($address)]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            writeWalletLog("WalletManager::walletExists - Error checking wallet existence: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * Create placeholder wallet for MetaMask/external addresses
     */
    public function createPlaceholderWallet(string $address): array
    {
        try {
            $created = $this->autoCreateWallet($address);
            return [
                'success' => $created,
                'address' => $address,
                'type' => 'placeholder',
                'message' => $created ? 'Placeholder wallet created for MetaMask/external address' : 'Failed to create wallet'
            ];
        } catch (Exception $e) {
            writeWalletLog("WalletManager::createPlaceholderWallet - Error: " . $e->getMessage(), 'ERROR');
            return [
                'success' => false,
                'address' => $address,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Auto-create wallet for MetaMask addresses
     */
    private function autoCreateWallet(string $address): bool
    {
        try {
            // Normalize and validate address format
            $address = strtolower($address);
            if (!preg_match('/^0x[a-f0-9]{40}$/', $address)) {
                return false;
            }
            
            // Use a placeholder public key for externally controlled addresses where we don't know the key yet
            $placeholderPubKey = 'placeholder_public_key';
            
            writeWalletLog("WalletManager::autoCreateWallet - Starting wallet creation for $address", 'DEBUG');
            
            // Check if we're in a transaction
            $inTransaction = $this->database->inTransaction();
            if ($inTransaction) {
                writeWalletLog("WalletManager::autoCreateWallet - Using existing transaction", 'DEBUG');
            }
            
            // First try to UPDATE existing record with placeholder public_key
            $upd = $this->database->prepare("
                UPDATE wallets 
                SET public_key = CASE 
                        WHEN public_key IS NULL OR public_key = '' OR public_key = 'placeholder_public_key' THEN ?
                        ELSE public_key
                    END,
                    updated_at = NOW()
                WHERE address = ?
            ");
            $upd->execute([$placeholderPubKey, $address]);
            
            if ($upd->rowCount() === 0) {
                // No existing record found, INSERT new one with ON DUPLICATE KEY UPDATE for safety
                $ins = $this->database->prepare("
                    INSERT INTO wallets (address, public_key, balance, staked_balance, nonce, created_at, updated_at)
                    VALUES (?, ?, 0.0, 0.0, 0, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                    public_key = CASE 
                        WHEN public_key IS NULL OR public_key = '' OR public_key = 'placeholder_public_key' 
                        THEN VALUES(public_key)
                        ELSE public_key
                    END,
                    updated_at = NOW()
                ");
                $ok = $ins->execute([$address, $placeholderPubKey]);
                writeWalletLog("WalletManager::autoCreateWallet - INSERT for $address result=" . json_encode($ok) . ", affected_rows=" . $ins->rowCount(), 'DEBUG');
                return $ok;
            } else {
                writeWalletLog("WalletManager::autoCreateWallet - UPDATE for $address affected_rows=" . $upd->rowCount(), 'DEBUG');
                return true;
            }
            
        } catch (\Throwable $e) {
            writeWalletLog("WalletManager::autoCreateWallet - Failed for {$address}: " . $e->getMessage(), 'ERROR');
            writeWalletLog("WalletManager::autoCreateWallet - Stack trace: " . $e->getTraceAsString(), 'DEBUG');
            return false;
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
        ?float $fee = null,
        ?string $privateKey = null,
        ?string $data = null
    ): Transaction {
        // Validate balance
        $availableBalance = $this->getAvailableBalance($fromAddress);
        
        if ($fee === null) {
            $fee = \Blockchain\Core\Transaction\FeePolicy::computeFee($this->database, $amount);
        }

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
* Get next nonce for address (improved to prevent RBF conflicts)
     */
    public function getNextNonce(string $address): int
    {
        // Normalize address
        $address = strtolower($address);

        try {
            $this->database->beginTransaction();

            // Get the highest confirmed nonce from transactions table
            $stmt = $this->database->prepare("
                SELECT COALESCE(MAX(nonce), -1) as max_confirmed_nonce 
                FROM transactions 
                WHERE from_address = ? AND status = 'confirmed'
            ");
            $stmt->execute([$address]);
            $result = $stmt->fetch();
            $confirmedNonce = $result ? (int)$result['max_confirmed_nonce'] : -1;

            // Get current nonce from wallet and lock the row
            $stmt = $this->database->prepare("
                SELECT nonce FROM wallets WHERE address = ? FOR UPDATE
            ");
            
            $stmt->execute([$address]);
            $result = $stmt->fetch();
            
            if (!$result) {
                // Auto-create wallet for MetaMask/external addresses
                writeWalletLog("WalletManager::getNextNonce - Auto-creating wallet for address $address", 'DEBUG');
                $this->autoCreateWallet($address);
                $currentNonce = -1;
            } else {
                $currentNonce = (int)$result['nonce'];
            }
            
            // Use the higher of confirmed transactions or wallet nonce
            $baseNonce = max($confirmedNonce, $currentNonce);
            
            // Get the highest pending nonce from mempool
            $stmt = $this->database->prepare("
                SELECT COALESCE(MAX(nonce), -1) as max_pending_nonce 
                FROM mempool 
                WHERE from_address = ?
            ");
            $stmt->execute([$address]);
            $result = $stmt->fetch();
            $pendingNonce = $result ? (int)$result['max_pending_nonce'] : -1;
            
            // Calculate next nonce
            $nextNonce = max($baseNonce, $pendingNonce) + 1;
            
            // The while loop for conflict resolution is still useful in case of edge cases.
            $stmt = $this->database->prepare("
                SELECT COUNT(*) FROM transactions 
                WHERE from_address = ? AND nonce = ? AND status = 'confirmed'
            ");
            $stmt->execute([$address, $nextNonce]);
            $conflictCount = (int)$stmt->fetchColumn();
            
            while ($conflictCount > 0) {
                $nextNonce++;
                $stmt->execute([$address, $nextNonce]);
                $conflictCount = (int)$stmt->fetchColumn();
            }
            
            writeWalletLog("WalletManager::getNextNonce - Address: $address, Confirmed: $confirmedNonce, Wallet: $currentNonce, Pending: $pendingNonce, Next: $nextNonce", 'DEBUG');
            
            $this->database->commit();

            return $nextNonce;

        } catch (Exception $e) {
            $this->database->rollBack();
            writeWalletLog("WalletManager::getNextNonce - Transaction failed: " . $e->getMessage(), 'ERROR');
            throw $e; // re-throw the exception
        }
    }
    
    /**
     * Update nonce after transaction confirmation
     */
    public function updateNonce(string $address, int $nonce): bool
    {
        // Normalize address
        $address = strtolower($address);
        
        // Get the highest confirmed nonce for this address
        $stmt = $this->database->prepare("
            SELECT COALESCE(MAX(nonce), -1) as max_confirmed_nonce 
            FROM transactions 
            WHERE from_address = ? AND status = 'confirmed'
        ");
        $stmt->execute([$address]);
        $result = $stmt->fetch();
        $maxConfirmedNonce = $result ? (int)$result['max_confirmed_nonce'] : -1;
        
        // Update wallet nonce to the highest confirmed nonce
        $stmt = $this->database->prepare("
            UPDATE wallets 
            SET nonce = ?, updated_at = NOW() 
            WHERE address = ?
        ");
        
        $success = $stmt->execute([$maxConfirmedNonce, $address]);
        
        writeWalletLog("WalletManager::updateNonce - Address: $address, Input nonce: $nonce, Max confirmed: $maxConfirmedNonce, Updated: " . ($success ? 'yes' : 'no'), 'DEBUG');
        
        return $success;
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
            LIMIT $offset, $limit
        ");
        
        $stmt->execute([$address, $address]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get wallet info
     */
    public function getWalletInfo(string $address): ?array
    {
        // Normalize to lowercase for consistent lookups
        $address = strtolower($address);
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
    public function importWallet(string $privateKeyOrMnemonic): array
    {
        try {
            // Determine if input is mnemonic phrase or private key
            $words = explode(' ', trim($privateKeyOrMnemonic));
            
            if (count($words) >= 12 && count($words) <= 24) {
                // It's likely a mnemonic phrase - convert to array format
                $mnemonic = array_map('trim', $words);
                
                // Use KeyPair::fromMnemonic directly
                $keyPair = KeyPair::fromMnemonic($mnemonic);
            } else {
                // Treat as private key (hex string)
                $privateKey = $privateKeyOrMnemonic;
                
                // Clean up private key (remove 0x prefix if present)
                if (strpos($privateKey, '0x') === 0) {
                    $privateKey = substr($privateKey, 2);
                }
                
                $keyPair = KeyPair::fromPrivateKey($privateKey);
            }
            
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
                'private_key' => $keyPair->getPrivateKey(),
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

            // If this is a no-op transfer (self-transfer with zero amount), confirm immediately
            $from = strtolower($transaction->getFromAddress());
            $to = strtolower($transaction->getToAddress());
            $isNoop = ($from === $to) && ($transaction->getAmount() <= 0);
            if ($isNoop) {
                // Write directly into transactions as confirmed, skip mempool
                $stmt = $this->database->prepare("
                    INSERT INTO transactions (
                        hash, from_address, to_address, amount, fee, 
                        nonce, gas_limit, gas_price, data, signature, 
                        timestamp, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $ok = $stmt->execute([
                    $transaction->getHash(),
                    $from,
                    $to,
                    $transaction->getAmount(),
                    $transaction->getFee(),
                    $transaction->getNonce(),
                    method_exists($transaction, 'getGasLimit') ? $transaction->getGasLimit() : 21000,
                    method_exists($transaction, 'getGasPrice') ? $transaction->getGasPrice() : 0,
                    $transaction->getData(),
                    $transaction->getSignature(),
                    $transaction->getTimestamp(),
                    'confirmed'
                ]);

                // Best-effort nonce update (no explicit transaction to avoid nested conflicts)
                try { $this->updateNonce($from, $transaction->getNonce()); } catch (\Throwable $e) {}

                return $ok;
            }

            // Add to mempool
            $stmt = $this->database->prepare("
                INSERT INTO mempool (
                    tx_hash, from_address, to_address, amount, fee, 
                    nonce, gas_limit, gas_price, data, signature, 
                    timestamp, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            return $stmt->execute([
                $transaction->getHash(),
                $from,
                $to,
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
     * Stake tokens with full blockchain integration (same as genesis installation)
     */
    public function stake(string $address, float $amount, string $privateKey, int $period = 30): bool
    {
        try {
            $pdo = $this->database;
            $baseDir = dirname(__DIR__);
            
            // Validate period
            if (!in_array($period, [7, 30, 90, 180, 365])) {
                throw new Exception("Invalid staking period. Must be 7, 30, 90, 180, or 365 days");
            }
            
            // Get wallet balance
            $availableBalance = $this->getAvailableBalance($address);
            
            if ($availableBalance < $amount) {
                throw new Exception("Insufficient balance for staking");
            }

            // Include SmartContractManager for contract deployment
            require_once $baseDir . '/contracts/SmartContractManager.php';
            require_once $baseDir . '/core/Storage/StateStorage.php';
            require_once $baseDir . '/core/SmartContract/VirtualMachine.php';
            require_once $baseDir . '/core/Logging/NullLogger.php';
            
            // Initialize SmartContractManager
            $stateStorage = new \Blockchain\Core\Storage\StateStorage($pdo);
            $vm = new \Blockchain\Core\SmartContract\VirtualMachine(8000000); // Gas limit
            $logger = new \Blockchain\Core\Logging\NullLogger();
            $contractManager = new \Blockchain\Contracts\SmartContractManager($vm, $stateStorage, $logger);
            
            // Load config for contract deployment
            $configFile = $baseDir . '/config/config.php';
            $config = [];
            if (file_exists($configFile)) {
                $config = require $configFile;
            }
            
            // Do NOT auto-deploy contracts here; staking must use existing contract
            // Any deployment should be performed explicitly via admin/API with proper gating
            
            // Add validator to ValidatorManager (same as genesis installation)
            require_once $baseDir . '/core/Consensus/ValidatorManager.php';
            $validatorManager = new \Blockchain\Core\Consensus\ValidatorManager($pdo, $config);
            
            // Create validator record
            // Get public key from wallet
            $walletInfo = $this->getWalletInfo($address);
            $publicKey = $walletInfo['public_key'] ?? null;
            
            if ($publicKey) {
                $validatorResult = $validatorManager->addValidator($address, $publicKey, (int)$amount);
                if (!$validatorResult) {
                    writeWalletLog("Failed to add validator: " . ($validatorResult['error'] ?? 'Unknown error'), 'WARNING');
                } else {
                    writeWalletLog("Added validator: " . $address, 'INFO');
                }
            } else {
                writeWalletLog("Cannot add validator: public key not found for address " . $address, 'WARNING');
            }
            
            // Execute staking transaction through smart contract
            if (!empty($contractAddresses['staking'])) {
                $stakingContractAddress = $contractAddresses['staking'];
                writeWalletLog("Executing staking through contract: " . $stakingContractAddress, 'INFO');
                
                // Create staking transaction using existing blockchain manager
                require_once $baseDir . '/wallet/WalletBlockchainManager.php';
                $blockchainManager = new \Blockchain\Wallet\WalletBlockchainManager($pdo, $config);
                
                // Create transaction data for staking
                $transactionData = [
                    'hash' => '0x' . hash('sha256', 'stake_' . $address . '_' . $amount . '_' . time()),
                    'type' => 'stake',
                    'from' => $address,
                    'to' => $stakingContractAddress,
                    'amount' => $amount,
                    'fee' => 0.0,
                    'timestamp' => time(),
                    'data' => [
                        'method' => 'stake',
                        'params' => [
                            'staker' => $address,
                            'amount' => $amount,
                            'period' => 30, // Default period
                            'contract_address' => $stakingContractAddress
                        ],
                        'action' => 'stake_tokens'
                    ],
                    'signature' => '', // Will be signed by ValidatorManager
                    'status' => 'pending'
                ];
                
                // Record transaction in blockchain
                $result = $blockchainManager->recordTransactionInBlockchain($transactionData);
                
                if ($result['blockchain_recorded']) {
                    writeWalletLog("Staking transaction recorded in blockchain: " . $result['block']['hash'], 'INFO');
                } else {
                    writeWalletLog("Failed to record staking transaction in blockchain: " . ($result['error'] ?? 'Unknown error'), 'WARNING');
                }
            }
            
            // Start transaction
            $this->database->beginTransaction();

            // Update wallet balance (subtract staked amount)
            $newBalance = $availableBalance - $amount;
            $stmt = $pdo->prepare("
                UPDATE wallets
                SET balance = ?, updated_at = NOW()
                WHERE address = ?
            ");
            $stmt->execute([$newBalance, $address]);

            // Add staking record with contract address and dynamic period
            $contractAddress = !empty($contractAddresses['staking']) ? $contractAddresses['staking'] : null;
            
            // Calculate end block based on current block and period
            $currentBlock = $this->getCurrentBlockHeight();
            $blocksPerDay = 86400 / 10; // Assuming 10-second blocks
            $periodBlocks = (int)($period * $blocksPerDay);
            $endBlock = $currentBlock + $periodBlocks;
            
            $stmt = $pdo->prepare("
                INSERT INTO staking (staker, amount, status, start_block, end_block, reward_rate)
                VALUES (?, ?, 'active', ?, ?, ?)
            ");
            
            // Calculate reward rate based on period
            $rewardRate = $this->getRewardRateForPeriod($period);
            $stmt->execute([$address, $amount, $currentBlock, $endBlock, $rewardRate]);

            // Record staking transaction
            $this->recordStakingTransaction($address, $amount, 'stake');

            $this->database->commit();
            return true;

        } catch (Exception $e) {
            $this->database->rollBack();
            writeWalletLog("Error in WalletManager::stake: " . $e->getMessage(), 'ERROR');
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

        // Get active staking records with their reward rates
        $stmt = $this->database->prepare("
            SELECT start_block, end_block, reward_rate, amount
            FROM staking
            WHERE staker = ? AND status = 'active'
            ORDER BY start_block ASC
        ");
        $stmt->execute([$address]);
        $stakingRecords = $stmt->fetchAll();

        if (empty($stakingRecords)) {
            return 0.0;
        }

        $totalRewards = 0.0;
        $currentBlock = $this->getCurrentBlockHeight();

        foreach ($stakingRecords as $record) {
            $startBlock = (int)$record['start_block'];
            $endBlock = (int)$record['end_block'];
            $rewardRate = (float)$record['reward_rate'];
            $amount = (float)$record['amount'];
            
            // Calculate blocks elapsed
            $blocksElapsed = min($currentBlock - $startBlock, $endBlock - $startBlock);
            if ($blocksElapsed <= 0) {
                continue;
            }

            // Calculate rewards based on blocks elapsed
            // Assuming 10-second blocks and 365 days = 3153600 seconds = 315360 blocks
            $blocksInYear = 315360;
            $recordRewards = ($amount * $rewardRate * $blocksElapsed) / $blocksInYear;
            $totalRewards += $recordRewards;
        }

        return $totalRewards;
    }

    /**
     * Get reward rate based on staking period
     */
    private function getRewardRateForPeriod(int $periodDays): float
    {
        // Delegate calculation to the unified helper
        return StakingRateHelper::getRewardRateForPeriod($periodDays);
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
            
            // Reset reward calculation timestamps for active staking records
            $stmt = $this->database->prepare("
                UPDATE staking
                SET last_reward_claim = NOW()
                WHERE staker = ? AND status = 'active'
            ");
            $stmt->execute([$address]);
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

            // Resolve staking contract address from DB (prefer active 'Staking' contract)
            $contractStmt = $this->database->prepare(
                "SELECT address FROM smart_contracts WHERE status = 'active' AND name = 'Staking' ORDER BY deployment_block DESC, id DESC LIMIT 1"
            );
            $contractStmt->execute();
            $stakingContract = $contractStmt->fetchColumn();

            if (!$stakingContract) {
                writeWalletLog("WalletManager::calculateStakedBalanceFromBlockchain - No active staking contract found", 'WARNING');
                return 0.0;
            }
            
            // Calculate staked balance from actual blockchain transactions
            $stmt = $this->database->prepare("
                SELECT 
                    (
                        COALESCE(SUM(CASE
                            WHEN from_address = ?
                                 AND to_address = ?
                                 AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.action')) = 'stake_tokens'
                            THEN amount ELSE 0 END), 0)
                        -
                        COALESCE(SUM(CASE
                            WHEN from_address = ?
                                 AND to_address = ?
                                 AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.action')) = 'unstake_tokens'
                            THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.principal')) AS DECIMAL(20,8))
                            ELSE 0 END), 0)
                    ) AS staked_balance
                FROM transactions
                WHERE status = 'confirmed'
                  AND (
                    (from_address = ? AND to_address = ?)
                    OR
                    (from_address = ? AND to_address = ?)
                  )
            ");
            
            $stmt->execute([
                $address,
                $stakingContract,
                $stakingContract,
                $address,
                $address,
                $stakingContract,
                $stakingContract,
                $address
            ]);
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

    /**
     * Get current block height
     */
    private function getCurrentBlockHeight(): int
    {
        try {
            $stmt = $this->database->prepare("
                SELECT MAX(height) as max_height FROM blocks
            ");
            $stmt->execute();
            $result = $stmt->fetch();
            
            return $result && $result['max_height'] ? (int)$result['max_height'] : 0;
        } catch (Exception $e) {
            writeWalletLog("Error getting current block height: " . $e->getMessage(), 'ERROR');
            return 0;
        }
    }
}

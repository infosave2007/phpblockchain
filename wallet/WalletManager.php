<?php
declare(strict_types=1);

namespace Blockchain\Wallet;

use Blockchain\Core\Cryptography\KeyPair;
use Blockchain\Core\Cryptography\Signature;
use Blockchain\Core\Transaction\Transaction;
use PDO;
use Exception;

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
    public function createWallet(string $password = null): array
    {
        try {
            // Generate key pair
            $keyPair = KeyPair::generate();
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
                'private_key' => $keyPair->getPrivateKey(), // In production, encrypt this
                'mnemonic' => $keyPair->getMnemonic()
            ];
            
        } catch (Exception $e) {
            throw new Exception("Failed to create wallet: " . $e->getMessage());
        }
    }
    
    /**
     * Get wallet balance
     */
    public function getBalance(string $address): float
    {
        $stmt = $this->database->prepare("
            SELECT balance, staked_balance 
            FROM wallets 
            WHERE address = ?
        ");
        
        $stmt->execute([$address]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return 0.0;
        }
        
        return (float)$result['balance'] + (float)$result['staked_balance'];
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
     * Get staked balance
     */
    public function getStakedBalance(string $address): float
    {
        $stmt = $this->database->prepare("
            SELECT staked_balance FROM wallets WHERE address = ?
        ");
        
        $stmt->execute([$address]);
        $result = $stmt->fetch();
        
        return $result ? (float)$result['staked_balance'] : 0.0;
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
        string $privateKey = null,
        string $data = null
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
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$address, $address, $limit, $offset]);
        
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
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$limit, $offset]);
        
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
}

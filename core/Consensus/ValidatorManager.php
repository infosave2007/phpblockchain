<?php
declare(strict_types=1);

namespace Blockchain\Core\Consensus;

use Blockchain\Core\Cryptography\Signature;
use Blockchain\Core\Cryptography\KeyPair;
use PDO;
use Exception;

/**
 * Centralized Validator Management and Block Signing Service
 * 
 * This service provides a unified interface for:
 * - Validator selection and management
 * - Block signature generation and verification
 * - Cryptographic operations for consensus
 * 
 * All validator and signature logic should go through this service
 * to ensure consistency and eliminate duplication.
 */
class ValidatorManager
{
    private PDO $database;
    private array $config;
    private ?array $cachedValidators = null;
    private string $nodeId;
    
    public function __construct(PDO $database, array $config = [])
    {
        $this->database = $database;
        $this->config = $config;
        $this->nodeId = $this->generateNodeId();
    }
    
    /**
     * Get the current active validator for block signing
     * This is the single source of truth for validator selection
     */
    public function getActiveValidator(): array
    {
        try {
            // Check cache first
            if ($this->cachedValidators !== null) {
                foreach ($this->cachedValidators as $validator) {
                    if ($validator['status'] === 'active') {
                        return $validator;
                    }
                }
            }
            
            // Query database for active validator with highest stake
            // Note: private_key is not stored in database for security reasons
            $stmt = $this->database->prepare("
                SELECT v.*, w.balance 
                FROM validators v 
                LEFT JOIN wallets w ON v.address = w.address 
                WHERE v.status = 'active' 
                ORDER BY v.stake DESC, v.created_at ASC 
                LIMIT 1
            ");
            $stmt->execute();
            $validator = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($validator && !empty($validator['address'])) {
                // Add placeholder private_key for compatibility (will not be used for real ECDSA)
                $validator['private_key'] = null;
                return $validator;
            }
            
            // If no active validator exists, create one
            return $this->createSystemValidator();
            
        } catch (Exception $e) {
            throw new Exception("Failed to get active validator: " . $e->getMessage());
        }
    }
    
    /**
     * Get all active validators for consensus operations
     */
    public function getActiveValidators(): array
    {
        try {
            if ($this->cachedValidators !== null) {
                return array_filter($this->cachedValidators, fn($v) => $v['status'] === 'active');
            }
            
            // Query without private_key field (not stored in database for security)
            $stmt = $this->database->prepare("
                SELECT v.*, w.balance 
                FROM validators v 
                LEFT JOIN wallets w ON v.address = w.address 
                WHERE v.status = 'active' 
                ORDER BY v.stake DESC
            ");
            $stmt->execute();
            $validators = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add placeholder private_key for compatibility
            foreach ($validators as &$validator) {
                $validator['private_key'] = null;
            }
            
            // Cache for performance
            $this->cachedValidators = $validators;
            
            return $validators;
            
        } catch (Exception $e) {
            throw new Exception("Failed to get active validators: " . $e->getMessage());
        }
    }
    
    /**
     * Sign a block using the active validator's credentials
     * This is the single source of truth for block signing
     */
    public function signBlock(array $blockData): array
    {
        try {
            $validator = $this->getActiveValidator();
            
            // Prepare standardized block data for signing
            $signingData = $this->prepareBlockSigningData($blockData);
            $dataString = json_encode($signingData);
            
            // Generate signature using validator's private key
            $signature = $this->generateSignature($dataString, $validator);
            
            return [
                'validator_address' => $validator['address'],
                'signature' => $signature,
                'signing_data' => $signingData,
                'timestamp' => time()
            ];
            
        } catch (Exception $e) {
            throw new Exception("Failed to sign block: " . $e->getMessage());
        }
    }
    
    /**
     * Verify a block signature
     */
    public function verifyBlockSignature(array $blockData, string $signature, string $validatorAddress): bool
    {
        try {
            // Get validator info
            $validator = $this->getValidatorByAddress($validatorAddress);
            if (!$validator) {
                return false;
            }
            
            // Prepare the same signing data format
            $signingData = $this->prepareBlockSigningData($blockData);
            $dataString = json_encode($signingData);
            
            // Verify signature
            return $this->verifySignature($dataString, $signature, $validator);
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Create a system validator when none exists
     */
    public function createSystemValidator(): array
    {
        try {
            // Generate cryptographic key pair
            if (class_exists('\Blockchain\Core\Cryptography\KeyPair')) {
                $keyPair = KeyPair::generate();
                $address = $keyPair->getAddress();
                $publicKey = $keyPair->getPublicKey();
                $privateKey = $keyPair->getPrivateKey();
            } else {
                // Fallback key generation
                $privateKey = bin2hex(random_bytes(32));
                $publicKey = hash('sha256', $privateKey . 'public');
                $address = '0x' . substr(hash('sha256', $publicKey), 0, 40);
            }
            
            // Start transaction
            $this->database->beginTransaction();
            
            try {
                // Insert validator
                $stmt = $this->database->prepare("
                    INSERT INTO validators (address, public_key, status, stake, commission_rate, created_at) 
                    VALUES (?, ?, 'active', 1000000, 0.1, NOW())
                    ON DUPLICATE KEY UPDATE status = 'active'
                ");
                $stmt->execute([$address, $publicKey]);
                
                // Insert wallet (without private_key for security)
                $stmt = $this->database->prepare("
                    INSERT INTO wallets (address, public_key, balance, created_at) 
                    VALUES (?, ?, 1000000, NOW())
                    ON DUPLICATE KEY UPDATE balance = VALUES(balance)
                ");
                $stmt->execute([$address, $publicKey]);
                
                $this->database->commit();
                
                // Clear cache
                $this->cachedValidators = null;
                
                return [
                    'address' => $address,
                    'public_key' => $publicKey,
                    'private_key' => null, // Not stored for security
                    'status' => 'active',
                    'stake' => 1000000,
                    'commission_rate' => 0.1,
                    'balance' => 1000000,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
            } catch (Exception $e) {
                $this->database->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            throw new Exception("Failed to create system validator: " . $e->getMessage());
        }
    }
    
    /**
     * Get validator by address
     */
    public function getValidatorByAddress(string $address): ?array
    {
        try {
            // Query without private_key field (not stored in database for security)
            $stmt = $this->database->prepare("
                SELECT v.*, w.balance 
                FROM validators v 
                LEFT JOIN wallets w ON v.address = w.address 
                WHERE v.address = ?
            ");
            $stmt->execute([$address]);
            $validator = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($validator) {
                // Add placeholder private_key for compatibility
                $validator['private_key'] = null;
                return $validator;
            }
            
            return null;
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Prepare standardized block data for signing
     */
    private function prepareBlockSigningData(array $blockData): array
    {
        // Standardize the signing data format
        $signingData = [
            'hash' => $blockData['hash'] ?? '',
            'index' => $blockData['index'] ?? $blockData['height'] ?? 0,
            'timestamp' => $blockData['timestamp'] ?? time(),
            'previous_hash' => $blockData['previous_hash'] ?? $blockData['parent_hash'] ?? '',
            'merkle_root' => $blockData['merkle_root'] ?? '',
            'transactions_count' => $blockData['transactions_count'] ?? 0,
            'signature_version' => '1.0',
            'node_id' => $this->nodeId
        ];
        
        // Sort keys for deterministic signing
        ksort($signingData);
        
        return $signingData;
    }
    
    /**
     * Generate cryptographic signature
     */
    private function generateSignature(string $data, array $validator): string
    {
        try {
            // Try ECDSA signature first (if private key available and Signature class exists)
            // Note: Private keys are not stored in database for security
            // In production, use hardware security modules or secure key stores
            if (!empty($validator['private_key']) && class_exists('\Blockchain\Core\Cryptography\Signature')) {
                $signature = Signature::sign($data, $validator['private_key']);
                return 'ecdsa:' . $signature;
            }
            
            // Use HMAC with validator-specific key (secure fallback)
            $signingKey = $this->getValidatorSigningKey($validator['address']);
            $signature = hash_hmac('sha256', $data, $signingKey);
            return 'hmac_sha256:' . $signature;
            
        } catch (Exception $e) {
            throw new Exception("Failed to generate signature: " . $e->getMessage());
        }
    }
    
    /**
     * Verify cryptographic signature
     */
    private function verifySignature(string $data, string $signature, array $validator): bool
    {
        try {
            // Parse signature type
            $parts = explode(':', $signature, 2);
            if (count($parts) !== 2) {
                return false;
            }
            
            [$type, $signatureValue] = $parts;
            
            switch ($type) {
                case 'ecdsa':
                    if (!empty($validator['public_key']) && class_exists('\Blockchain\Core\Cryptography\Signature')) {
                        return Signature::verify($data, $signatureValue, $validator['public_key']);
                    }
                    return false;
                    
                case 'hmac_sha256':
                    $signingKey = $this->getValidatorSigningKey($validator['address']);
                    $expectedSignature = hash_hmac('sha256', $data, $signingKey);
                    return hash_equals($expectedSignature, $signatureValue);
                    
                default:
                    return false;
            }
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get validator-specific signing key
     */
    private function getValidatorSigningKey(string $validatorAddress): string
    {
        $appKey = $this->config['app_key'] ?? 'default_blockchain_secret_2025';
        return hash('sha256', $appKey . $validatorAddress . 'validator_signing_key');
    }
    
    /**
     * Generate node ID
     */
    private function generateNodeId(): string
    {
        try {
            // Try to get from database
            $stmt = $this->database->query("SELECT node_id FROM nodes WHERE status = 'active' LIMIT 1");
            $node = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($node) {
                return $node['node_id'];
            }
        } catch (Exception $e) {
            // Fall through to generated ID
        }
        
        return hash('sha256', gethostname() . getmypid() . time());
    }
    
    /**
     * Clear validator cache
     */
    public function clearCache(): void
    {
        $this->cachedValidators = null;
    }
    
    /**
     * Add new validator
     */
    public function addValidator(string $address, string $publicKey, int $stake = 1000000): bool
    {
        try {
            $stmt = $this->database->prepare("
                INSERT INTO validators (address, public_key, status, stake, commission_rate, created_at) 
                VALUES (?, ?, 'active', ?, 0.1, NOW())
                ON DUPLICATE KEY UPDATE 
                status = 'active', 
                stake = VALUES(stake),
                public_key = VALUES(public_key)
            ");
            
            $result = $stmt->execute([$address, $publicKey, $stake]);
            
            if ($result) {
                $this->clearCache();
            }
            
            return $result;
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Remove validator
     */
    public function removeValidator(string $address): bool
    {
        try {
            $stmt = $this->database->prepare("
                UPDATE validators 
                SET status = 'inactive', updated_at = NOW() 
                WHERE address = ?
            ");
            
            $result = $stmt->execute([$address]);
            
            if ($result) {
                $this->clearCache();
            }
            
            return $result;
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get genesis validator for initial block creation
     * Returns the first active validator or creates one if needed
     */
    public function getGenesisValidator(): ?string
    {
        try {
            $stmt = $this->database->prepare("
                SELECT address FROM validators 
                WHERE status = 'active' 
                ORDER BY created_at ASC 
                LIMIT 1
            ");
            
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return $result['address'];
            }
            
            // No active validators found - try to find any validator
            $stmt = $this->database->prepare("
                SELECT address FROM validators 
                ORDER BY created_at ASC 
                LIMIT 1
            ");
            
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? $result['address'] : null;
            
        } catch (Exception $e) {
            return null;
        }
    }
}

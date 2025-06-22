<?php
declare(strict_types=1);

namespace Blockchain\Core\Security;

use Exception;

/**
 * Secure Key Storage with encryption
 */
class SecureKeyStorage
{
    private string $encryptionKey;
    private string $storageDir;
    
    public function __construct(string $masterPassword, string $storageDir = null)
    {
        $this->storageDir = $storageDir ?? __DIR__ . '/../../storage/keys/';
        $this->ensureStorageDirectory();
        $this->encryptionKey = $this->deriveKey($masterPassword);
    }

    /**
     * Store encrypted private key
     */
    public function storePrivateKey(string $address, string $privateKey, string $password = null): bool
    {
        try {
            $keyData = [
                'private_key' => $privateKey,
                'address' => $address,
                'created_at' => time(),
                'version' => '1.0'
            ];

            $encryptionKey = $password ? $this->deriveKey($password) : $this->encryptionKey;
            $encrypted = $this->encrypt(json_encode($keyData), $encryptionKey);
            
            $filename = $this->getKeyFilename($address);
            $success = file_put_contents($filename, $encrypted) !== false;
            
            if ($success) {
                chmod($filename, 0600); // Only owner can read/write
            }
            
            return $success;
        } catch (Exception $e) {
            error_log("SecureKeyStorage::storePrivateKey failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieve and decrypt private key
     */
    public function getPrivateKey(string $address, string $password = null): ?string
    {
        try {
            $filename = $this->getKeyFilename($address);
            
            if (!file_exists($filename)) {
                return null;
            }

            $encryptedData = file_get_contents($filename);
            if ($encryptedData === false) {
                return null;
            }

            $encryptionKey = $password ? $this->deriveKey($password) : $this->encryptionKey;
            $decrypted = $this->decrypt($encryptedData, $encryptionKey);
            
            $keyData = json_decode($decrypted, true);
            
            return $keyData['private_key'] ?? null;
        } catch (Exception $e) {
            error_log("SecureKeyStorage::getPrivateKey failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Store encrypted seed phrase
     */
    public function storeSeedPhrase(string $address, string $seedPhrase, string $password): bool
    {
        try {
            $seedData = [
                'seed_phrase' => $seedPhrase,
                'address' => $address,
                'created_at' => time(),
                'version' => '1.0'
            ];

            $encrypted = $this->encrypt(json_encode($seedData), $this->deriveKey($password));
            
            $filename = $this->getSeedFilename($address);
            $success = file_put_contents($filename, $encrypted) !== false;
            
            if ($success) {
                chmod($filename, 0600);
            }
            
            return $success;
        } catch (Exception $e) {
            error_log("SecureKeyStorage::storeSeedPhrase failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieve and decrypt seed phrase
     */
    public function getSeedPhrase(string $address, string $password): ?string
    {
        try {
            $filename = $this->getSeedFilename($address);
            
            if (!file_exists($filename)) {
                return null;
            }

            $encryptedData = file_get_contents($filename);
            if ($encryptedData === false) {
                return null;
            }

            $decrypted = $this->decrypt($encryptedData, $this->deriveKey($password));
            $seedData = json_decode($decrypted, true);
            
            return $seedData['seed_phrase'] ?? null;
        } catch (Exception $e) {
            error_log("SecureKeyStorage::getSeedPhrase failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete key file securely
     */
    public function deleteKey(string $address): bool
    {
        $keyFile = $this->getKeyFilename($address);
        $seedFile = $this->getSeedFilename($address);
        
        $success = true;
        
        if (file_exists($keyFile)) {
            // Secure deletion - overwrite with random data
            $this->secureDelete($keyFile);
        }
        
        if (file_exists($seedFile)) {
            $this->secureDelete($seedFile);
        }
        
        return $success;
    }

    /**
     * List all stored addresses
     */
    public function listAddresses(): array
    {
        $addresses = [];
        $files = glob($this->storageDir . '*.key');
        
        foreach ($files as $file) {
            $basename = basename($file, '.key');
            if (preg_match('/^[a-f0-9]{40}$/', $basename)) {
                $addresses[] = '0x' . $basename;
            }
        }
        
        return $addresses;
    }

    /**
     * Encrypt data using AES-256-GCM
     */
    private function encrypt(string $data, string $key): string
    {
        $nonce = random_bytes(12); // 96-bit nonce for GCM
        $encrypted = openssl_encrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        
        if ($encrypted === false) {
            throw new Exception('Encryption failed');
        }
        
        return base64_encode($nonce . $tag . $encrypted);
    }

    /**
     * Decrypt data using AES-256-GCM
     */
    private function decrypt(string $encryptedData, string $key): string
    {
        $data = base64_decode($encryptedData);
        
        if ($data === false || strlen($data) < 28) { // 12 + 16 minimum
            throw new Exception('Invalid encrypted data');
        }
        
        $nonce = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $encrypted = substr($data, 28);
        
        $decrypted = openssl_decrypt($encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        
        if ($decrypted === false) {
            throw new Exception('Decryption failed');
        }
        
        return $decrypted;
    }

    /**
     * Derive encryption key from password using PBKDF2
     */
    private function deriveKey(string $password): string
    {
        $salt = hash('sha256', 'blockchain_platform_salt_v1', true);
        return hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);
    }

    /**
     * Ensure storage directory exists with proper permissions
     */
    private function ensureStorageDirectory(): void
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0700, true);
        }
        chmod($this->storageDir, 0700); // Only owner can access
    }

    /**
     * Get key filename for address
     */
    private function getKeyFilename(string $address): string
    {
        $cleanAddress = str_replace('0x', '', strtolower($address));
        return $this->storageDir . $cleanAddress . '.key';
    }

    /**
     * Get seed filename for address
     */
    private function getSeedFilename(string $address): string
    {
        $cleanAddress = str_replace('0x', '', strtolower($address));
        return $this->storageDir . $cleanAddress . '.seed';
    }

    /**
     * Securely delete file by overwriting with random data
     */
    private function secureDelete(string $filename): bool
    {
        if (!file_exists($filename)) {
            return true;
        }
        
        $filesize = filesize($filename);
        
        // Overwrite with random data 3 times
        for ($i = 0; $i < 3; $i++) {
            $randomData = random_bytes($filesize);
            file_put_contents($filename, $randomData);
            fsync(fopen($filename, 'r'));
        }
        
        return unlink($filename);
    }
}

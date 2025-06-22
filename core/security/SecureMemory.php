<?php

namespace Core\Security;

/**
 * Secure memory management for sensitive data
 */
class SecureMemory 
{
    private static array $encryptedStorage = [];
    private static ?string $masterKey = null;
    
    /**
     * Secure storage of sensitive data in memory
     */
    public static function store(string $key, string $sensitiveData): void 
    {
        if (!self::$masterKey) {
            self::$masterKey = self::deriveMasterKey();
        }
        
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encrypted = sodium_crypto_secretbox($sensitiveData, $nonce, self::$masterKey);
        
        self::$encryptedStorage[$key] = $nonce . $encrypted;
        
        // Clear original data from memory
        if (function_exists('sodium_memzero')) {
            sodium_memzero($sensitiveData);
        }
    }
    
    /**
     * Get decrypted data
     */
    public static function retrieve(string $key): ?string 
    {
        if (!isset(self::$encryptedStorage[$key])) {
            return null;
        }
        
        $data = self::$encryptedStorage[$key];
        $nonce = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encrypted = substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        
        $decrypted = sodium_crypto_secretbox_open($encrypted, $nonce, self::$masterKey);
        
        return $decrypted !== false ? $decrypted : null;
    }
    
    /**
     * Clear data from secure storage
     */
    public static function delete(string $key): void 
    {
        unset(self::$encryptedStorage[$key]);
    }
    
    /**
     * Clear entire secure storage
     */
    public static function clear(): void 
    {
        self::$encryptedStorage = [];
        if (self::$masterKey && function_exists('sodium_memzero')) {
            sodium_memzero(self::$masterKey);
        }
        self::$masterKey = null;
    }
    
    /**
     * Master key derivation
     */
    private static function deriveMasterKey(): string 
    {
        $salt = getenv('MEMORY_ENCRYPTION_SALT') ?: 'default-salt-change-in-production';
        $seed = getenv('MASTER_SEED') ?: random_bytes(32);
        
        return sodium_crypto_pwhash(
            32,
            $seed,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }
}

<?php
declare(strict_types=1);

namespace Blockchain\Core\Cryptography;

use Exception;

/**
 * Message Encryption Class
 * 
 * Handles encryption and decryption of messages using various cryptographic methods
 */
class MessageEncryption
{
    const ENCRYPTION_AES256 = 'aes256';
    const ENCRYPTION_RSA = 'rsa';
    const ENCRYPTION_HYBRID = 'hybrid'; // AES + RSA
    
    /**
     * Encrypt message using hybrid encryption (AES + RSA)
     * This is the most secure and efficient method for larger messages
     */
    public static function encryptHybrid(string $message, string $recipientPublicKey): array
    {
        // Generate random AES key
        $aesKey = openssl_random_pseudo_bytes(32); // 256-bit key
        $iv = openssl_random_pseudo_bytes(16); // 128-bit IV
        
        // Encrypt message with AES
        $encryptedMessage = openssl_encrypt($message, 'AES-256-CBC', $aesKey, 0, $iv);
        
        if ($encryptedMessage === false) {
            throw new Exception('AES encryption failed');
        }
        
        // Encrypt AES key with RSA public key
        $publicKeyResource = openssl_pkey_get_public($recipientPublicKey);
        if (!$publicKeyResource) {
            throw new Exception('Invalid public key');
        }
        
        $encryptedKey = '';
        if (!openssl_public_encrypt($aesKey, $encryptedKey, $publicKeyResource)) {
            throw new Exception('RSA encryption of AES key failed');
        }
        
        return [
            'method' => self::ENCRYPTION_HYBRID,
            'encrypted_message' => base64_encode($encryptedMessage),
            'encrypted_key' => base64_encode($encryptedKey),
            'iv' => base64_encode($iv),
            'timestamp' => time()
        ];
    }
    
    /**
     * Decrypt message using hybrid decryption (RSA + AES)
     */
    public static function decryptHybrid(array $encryptedData, string $recipientPrivateKey): string
    {
        if ($encryptedData['method'] !== self::ENCRYPTION_HYBRID) {
            throw new Exception('Invalid encryption method');
        }
        
        // Get private key resource
        $privateKeyResource = openssl_pkey_get_private($recipientPrivateKey);
        if (!$privateKeyResource) {
            throw new Exception('Invalid private key');
        }
        
        // Decrypt AES key with RSA private key
        $encryptedKey = base64_decode($encryptedData['encrypted_key']);
        $aesKey = '';
        if (!openssl_private_decrypt($encryptedKey, $aesKey, $privateKeyResource)) {
            throw new Exception('RSA decryption of AES key failed');
        }
        
        // Decrypt message with AES
        $encryptedMessage = base64_decode($encryptedData['encrypted_message']);
        $iv = base64_decode($encryptedData['iv']);
        
        $decryptedMessage = openssl_decrypt($encryptedMessage, 'AES-256-CBC', $aesKey, 0, $iv);
        
        if ($decryptedMessage === false) {
            throw new Exception('AES decryption failed');
        }
        
        return $decryptedMessage;
    }
    
    /**
     * Encrypt message using AES-256
     */
    public static function encryptAES(string $message, string $password): array
    {
        $salt = openssl_random_pseudo_bytes(16);
        $key = hash_pbkdf2('sha256', $password, $salt, 10000, 32, true);
        $iv = openssl_random_pseudo_bytes(16);
        
        $encryptedMessage = openssl_encrypt($message, 'AES-256-CBC', $key, 0, $iv);
        
        if ($encryptedMessage === false) {
            throw new Exception('AES encryption failed');
        }
        
        return [
            'method' => self::ENCRYPTION_AES256,
            'encrypted_message' => base64_encode($encryptedMessage),
            'salt' => base64_encode($salt),
            'iv' => base64_encode($iv),
            'timestamp' => time()
        ];
    }
    
    /**
     * Decrypt message using AES-256
     */
    public static function decryptAES(array $encryptedData, string $password): string
    {
        if ($encryptedData['method'] !== self::ENCRYPTION_AES256) {
            throw new Exception('Invalid encryption method');
        }
        
        $salt = base64_decode($encryptedData['salt']);
        $key = hash_pbkdf2('sha256', $password, $salt, 10000, 32, true);
        $iv = base64_decode($encryptedData['iv']);
        $encryptedMessage = base64_decode($encryptedData['encrypted_message']);
        
        $decryptedMessage = openssl_decrypt($encryptedMessage, 'AES-256-CBC', $key, 0, $iv);
        
        if ($decryptedMessage === false) {
            throw new Exception('AES decryption failed');
        }
        
        return $decryptedMessage;
    }
    
    /**
     * Generate RSA key pair
     */
    public static function generateRSAKeyPair(int $keySize = 2048): array
    {
        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => $keySize,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        
        $resource = openssl_pkey_new($config);
        
        if (!$resource) {
            throw new Exception('Failed to generate RSA key pair');
        }
        
        // Extract private key
        openssl_pkey_export($resource, $privateKey);
        
        // Extract public key
        $publicKeyDetails = openssl_pkey_get_details($resource);
        $publicKey = $publicKeyDetails['key'];
        
        return [
            'private_key' => $privateKey,
            'public_key' => $publicKey
        ];
    }
    
    /**
     * Sign message with private key
     */
    public static function signMessage(string $message, string $privateKey): string
    {
        $signature = '';
        $privateKeyResource = openssl_pkey_get_private($privateKey);
        
        if (!$privateKeyResource) {
            throw new Exception('Invalid private key');
        }
        
        if (!openssl_sign($message, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256)) {
            throw new Exception('Message signing failed');
        }
        
        return base64_encode($signature);
    }
    
    /**
     * Verify message signature
     */
    public static function verifySignature(string $message, string $signature, string $publicKey): bool
    {
        $publicKeyResource = openssl_pkey_get_public($publicKey);
        
        if (!$publicKeyResource) {
            throw new Exception('Invalid public key');
        }
        
        $decodedSignature = base64_decode($signature);
        $result = openssl_verify($message, $decodedSignature, $publicKeyResource, OPENSSL_ALGO_SHA256);
        
        return $result === 1;
    }
    
    /**
     * Create secure message package with encryption and signing
     */
    public static function createSecureMessage(
        string $message,
        string $recipientPublicKey,
        string $senderPrivateKey,
        string $encryptionMethod = self::ENCRYPTION_HYBRID
    ): array {
        // Encrypt message
        $encryptedData = match($encryptionMethod) {
            self::ENCRYPTION_HYBRID => self::encryptHybrid($message, $recipientPublicKey),
            self::ENCRYPTION_AES256 => throw new Exception('AES256 requires password, use hybrid encryption instead'),
            default => throw new Exception('Unsupported encryption method')
        };
        
        // Sign the encrypted message for integrity
        $messageToSign = json_encode($encryptedData);
        $signature = self::signMessage($messageToSign, $senderPrivateKey);
        
        return [
            'encrypted_data' => $encryptedData,
            'signature' => $signature,
            'created_at' => time()
        ];
    }
    
    /**
     * Verify and decrypt secure message
     */
    public static function decryptSecureMessage(
        array $secureMessage,
        string $recipientPrivateKey,
        string $senderPublicKey
    ): string {
        // Verify signature first
        $messageToVerify = json_encode($secureMessage['encrypted_data']);
        
        if (!self::verifySignature($messageToVerify, $secureMessage['signature'], $senderPublicKey)) {
            throw new Exception('Message signature verification failed - message may be tampered');
        }
        
        // Decrypt message
        return self::decryptHybrid($secureMessage['encrypted_data'], $recipientPrivateKey);
    }
}

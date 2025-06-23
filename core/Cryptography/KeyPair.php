<?php
declare(strict_types=1);

namespace Blockchain\Core\Cryptography;

use Exception;

/**
 * Cryptographic Key Pair
 * 
 * Handles generation and management of cryptographic key pairs
 */
class KeyPair
{
    private string $privateKey;
    private string $publicKey;
    private string $address;
    
    public function __construct(string $privateKey, string $publicKey, string $address)
    {
        $this->privateKey = $privateKey;
        $this->publicKey = $publicKey;
        $this->address = $address;
    }
    
    /**
     * Generate new key pair
     */
    public static function generate(): self
    {
        // Generate private key (32 bytes)
        $privateKey = random_bytes(32);
        $privateKeyHex = bin2hex($privateKey);
        
        // Generate public key using secp256k1 curve
        $publicKeyHex = self::generatePublicKeyHex($privateKeyHex);
        
        // Generate address from public key
        $address = self::generateAddressFromHex($publicKeyHex);
        
        return new self($privateKeyHex, $publicKeyHex, $address);
    }
    
    /**
     * Create key pair from private key
     */
    public static function fromPrivateKey(string $privateKeyHex): self
    {
        $privateKey = hex2bin($privateKeyHex);
        
        if (strlen($privateKey) !== 32) {
            throw new Exception("Invalid private key length");
        }
        
        $publicKeyHex = self::generatePublicKeyHex($privateKeyHex);
        $address = self::generateAddressFromHex($publicKeyHex);
        
        return new self($privateKeyHex, $publicKeyHex, $address);
    }
    
    /**
     * Generate public key hex from private key hex using secp256k1
     */
    private static function generatePublicKeyHex(string $privateKeyHex): string
    {
        // Generate public key using elliptic curve cryptography
        $publicKeyPoint = EllipticCurve::generatePublicKey($privateKeyHex);
        
        // Compress the public key and return as hex string
        return EllipticCurve::compressPublicKey($publicKeyPoint);
    }
    
    /**
     * Generate public key from private key using secp256k1 (legacy method)
     */
    private static function generatePublicKey(string $privateKey): string
    {
        $privateKeyHex = bin2hex($privateKey);
        
        // Generate public key using elliptic curve cryptography
        $publicKeyPoint = EllipticCurve::generatePublicKey($privateKeyHex);
        
        // Compress the public key
        $compressedPublicKey = EllipticCurve::compressPublicKey($publicKeyPoint);
        
        return hex2bin($compressedPublicKey);    }

    /**
     * Generate address from public key hex using Keccak-256 (Ethereum-style)
     */
    private static function generateAddressFromHex(string $publicKeyHex): string
    {
        // For compressed public key, decompress first
        if (strlen($publicKeyHex) === 66) { // Compressed public key
            $publicKeyPoint = EllipticCurve::decompressPublicKey($publicKeyHex);
            $uncompressedKey = '04' . str_pad($publicKeyPoint['x'], 64, '0', STR_PAD_LEFT) . 
                              str_pad($publicKeyPoint['y'], 64, '0', STR_PAD_LEFT);
        } else {
            $uncompressedKey = $publicKeyHex;
        }
        
        // Remove '04' prefix if present
        if (substr($uncompressedKey, 0, 2) === '04') {
            $uncompressedKey = substr($uncompressedKey, 2);
        }
        
        // Calculate Keccak-256 hash (fallback using SHA-256)
        $hash = hash('sha256', hex2bin($uncompressedKey));
        
        // Take last 20 bytes (40 hex characters) as address
        $address = substr($hash, -40);
        
        return '0x' . $address;
    }

    /**
     * Generate address from public key using Keccak-256 (Ethereum-style)
     */
    private static function generateAddress(string $publicKey): string
    {
        // For compressed public key, decompress first
        $publicKeyHex = bin2hex($publicKey);
        
        if (strlen($publicKeyHex) === 66) { // Compressed public key
            $publicKeyPoint = EllipticCurve::decompressPublicKey($publicKeyHex);
            $uncompressedKey = '04' . str_pad($publicKeyPoint['x'], 64, '0', STR_PAD_LEFT) . 
                              str_pad($publicKeyPoint['y'], 64, '0', STR_PAD_LEFT);
        } else {
            $uncompressedKey = $publicKeyHex;
        }
        
        // Remove '04' prefix if present
        if (substr($uncompressedKey, 0, 2) === '04') {
            $uncompressedKey = substr($uncompressedKey, 2);
        }
        
        // Calculate Keccak-256 hash
        $hash = self::keccak256(hex2bin($uncompressedKey));
        
        // Take last 20 bytes and add 0x prefix
        $address = '0x' . substr($hash, -40);
        
        return $address;
    }
    
    /**
     * Keccak-256 hash function
     */
    private static function keccak256(string $data): string
    {
        // Simple implementation - in production use a proper Keccak library
        $hash = hash('sha3-256', $data);
        return $hash;
    }
    
    /**
     * Get private key
     */
    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }
    
    /**
     * Get public key
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }
    
    /**
     * Get address
     */
    public function getAddress(): string
    {
        return $this->address;
    }
    
    /**
     * Generate mnemonic phrase
     */
    public function getMnemonic(): string
    {
        // Simple mnemonic generation - in production use BIP39
        $words = [
            'abandon', 'ability', 'able', 'about', 'above', 'absent', 'absorb', 'abstract',
            'absurd', 'abuse', 'access', 'accident', 'account', 'accuse', 'achieve', 'acid',
            'acoustic', 'acquire', 'across', 'action', 'actor', 'actress', 'actual', 'adapt',
            'add', 'addict', 'address', 'adjust', 'admit', 'adult', 'advance', 'advice'
        ];
        
        $mnemonic = [];
        $seed = $this->privateKey;
        
        for ($i = 0; $i < 12; $i++) {
            $index = hexdec(substr($seed, $i * 2, 2)) % count($words);
            $mnemonic[] = $words[$index];
        }
        
        return implode(' ', $mnemonic);
    }
    
    /**
     * Sign message
     */
    public function sign(string $message): string
    {
        return Signature::sign($message, $this->privateKey);
    }
    
    /**
     * Verify signature
     */
    public function verify(string $message, string $signature): bool
    {
        return Signature::verify($message, $signature, $this->publicKey);
    }
}

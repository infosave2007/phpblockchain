<?php
declare(strict_types=1);

namespace Blockchain\Core\Cryptography;

use Exception;

/**
 * Digital Signature Implementation
 * 
 * Handles message signing and verification
 */
class Signature
{
    /**
     * Sign a message with private key using ECDSA
     */
    public static function sign(string $message, string $privateKeyHex): string
    {
        try {
            // Create double SHA-256 hash of message (Bitcoin-style)
            $messageHash = hash('sha256', hash('sha256', $message, true), true);
            $messageHashHex = bin2hex($messageHash);
            
            // Generate ECDSA signature
            $signature = EllipticCurve::sign($messageHashHex, $privateKeyHex);
            
            // Encode signature in DER format
            $r = $signature['r'];
            $s = $signature['s'];
            
            // Pad with zeros if needed
            $r = str_pad($r, 64, '0', STR_PAD_LEFT);
            $s = str_pad($s, 64, '0', STR_PAD_LEFT);
            
            return $r . $s;
            
        } catch (Exception $e) {
            throw new Exception("Signing failed: " . $e->getMessage());
        }
    }
    
    /**
     * Verify ECDSA signature
     */
    public static function verify(string $message, string $signatureHex, string $publicKeyHex): bool
    {
        try {
            if (strlen($signatureHex) !== 128) { // 64 bytes for r + s
                return false;
            }
            
            // Parse signature
            $r = substr($signatureHex, 0, 64);
            $s = substr($signatureHex, 64, 64);
            
            $signature = ['r' => $r, 's' => $s];
            
            // Create double SHA-256 hash of message
            $messageHash = hash('sha256', hash('sha256', $message, true), true);
            $messageHashHex = bin2hex($messageHash);
            
            // Parse public key - ensure it's in the right format
            if (strlen($publicKeyHex) === 66) { // Compressed
                $publicKey = EllipticCurve::decompressPublicKey($publicKeyHex);
            } elseif (strlen($publicKeyHex) === 130) { // Uncompressed with 04 prefix
                $cleanPublicKey = substr($publicKeyHex, 2); // Remove 04 prefix
                $publicKey = [
                    'x' => substr($cleanPublicKey, 0, 64),
                    'y' => substr($cleanPublicKey, 64, 64)
                ];
            } elseif (strlen($publicKeyHex) === 128) { // Uncompressed without prefix
                $publicKey = [
                    'x' => substr($publicKeyHex, 0, 64),
                    'y' => substr($publicKeyHex, 64, 64)
                ];
            } else {
                return false; // Invalid public key format
            }
            
            // Verify signature
            return EllipticCurve::verify($messageHashHex, $signature, $publicKey);
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Recover public key from ECDSA signature
     */
    public static function recoverPublicKey(string $message, string $signatureHex): ?string
    {
        try {
            if (strlen($signatureHex) !== 128) {
                return null;
            }
            
            // Parse signature
            $r = substr($signatureHex, 0, 64);
            $s = substr($signatureHex, 64, 64);
            
            // Create double SHA-256 hash of message
            $messageHash = hash('sha256', hash('sha256', $message, true), true);
            $messageHashHex = bin2hex($messageHash);
            
            // Try both recovery IDs (0 and 1)
            for ($recoveryId = 0; $recoveryId <= 1; $recoveryId++) {
                $publicKey = self::recoverPublicKeyFromSignature($messageHashHex, $r, $s, $recoveryId);
                if ($publicKey !== null) {
                    return EllipticCurve::compressPublicKey($publicKey);
                }
            }
            
            return null;
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Recover public key from signature components
     */
    private static function recoverPublicKeyFromSignature(string $messageHash, string $r, string $s, int $recoveryId): ?array
    {
        // This is a simplified recovery - in production use proper ECDSA recovery
        // For now, return null as this requires complex elliptic curve operations
        return null;
    }
    
    /**
     * Generate deterministic k value for signing
     */
    private static function generateK(string $privateKey, string $messageHash): string
    {
        // RFC 6979 deterministic k generation (simplified)
        $v = str_repeat("\x01", 32);
        $k = str_repeat("\x00", 32);
        
        $k = hash_hmac('sha256', $v . "\x00" . $privateKey . $messageHash, $k, true);
        $v = hash_hmac('sha256', $v, $k, true);
        
        $k = hash_hmac('sha256', $v . "\x01" . $privateKey . $messageHash, $k, true);
        $v = hash_hmac('sha256', $v, $k, true);
        
        return $v;
    }
    
    /**
     * Validate signature format
     */
    public static function isValidSignature(string $signatureHex): bool
    {
        return preg_match('/^[a-fA-F0-9]{66}$/', $signatureHex) === 1;
    }
    
    /**
     * Create compact signature format
     */
    public static function toCompactFormat(string $signatureHex): string
    {
        if (!self::isValidSignature($signatureHex)) {
            throw new Exception("Invalid signature format");
        }
        
        // Already in compact format (32 bytes + recovery ID)
        return $signatureHex;
    }
    
    /**
     * Parse signature components
     */
    public static function parseSignature(string $signatureHex): array
    {
        if (!self::isValidSignature($signatureHex)) {
            throw new Exception("Invalid signature format");
        }
        
        return [
            'r' => substr($signatureHex, 0, 32),
            's' => substr($signatureHex, 32, 32),
            'recovery_id' => hexdec(substr($signatureHex, 64, 2))
        ];
    }
}

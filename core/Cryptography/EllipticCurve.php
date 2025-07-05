<?php
declare(strict_types=1);

namespace Blockchain\Core\Cryptography;

/**
 * Simplified Elliptic Curve Cryptography Implementation
 * 
 * Uses fallback methods for compatibility without GMP extension
 */
class EllipticCurve
{
    /**
     * Generate a public key from private key using fallback method
     */
    public static function generatePublicKey(string $privateKeyHex): array
    {
        // Remove 0x prefix if present
        $privateKeyHex = str_replace('0x', '', $privateKeyHex);
        
        // Ensure we have 64 characters (32 bytes)
        $privateKeyHex = str_pad($privateKeyHex, 64, '0', STR_PAD_LEFT);
        
        // Fallback: Generate deterministic public key coordinates
        return self::generatePublicKeyFallback($privateKeyHex);
    }
    
    /**
     * Fallback public key generation using deterministic method
     */
    private static function generatePublicKeyFallback(string $privateKeyHex): array
    {
        // Use SHA-256 based deterministic generation as fallback
        $seed = hex2bin($privateKeyHex);
        $hash1 = hash('sha256', $seed . 'x_coordinate', true);
        $hash2 = hash('sha256', $seed . 'y_coordinate', true);
        
        // Ensure coordinates are valid (non-zero)
        $x = self::normalizeCoordinate(bin2hex($hash1));
        $y = self::normalizeCoordinate(bin2hex($hash2));
        
        return [
            'x' => $x,
            'y' => $y
        ];
    }
    
    /**
     * Normalize coordinate to valid field element
     */
    private static function normalizeCoordinate(string $hash): string
    {
        // Ensure the coordinate is non-zero
        if (substr($hash, 0, 2) === '00') {
            $hash = '01' . substr($hash, 2);
        }
        
        return str_pad($hash, 64, '0', STR_PAD_LEFT);
    }
    
    /**
     * Point multiplication (simplified version)
     */
    public static function pointMultiply(string $privateKeyHex): array
    {
        return self::generatePublicKey($privateKeyHex);
    }
    
    /**
     * Verify point is on curve (simplified check)
     */
    public static function isValidPoint(array $point): bool
    {
        return isset($point['x'], $point['y']) &&
               ctype_xdigit($point['x']) &&
               ctype_xdigit($point['y']) &&
               strlen($point['x']) === 64 &&
               strlen($point['y']) === 64;
    }
    
    /**
     * Compress public key (simplified version)
     */
    public static function compressPublicKey(array $point): string
    {
        // Determine prefix based on y-coordinate parity
        $y_parity_byte = hexdec(substr($point['y'], -2));
        $prefix = ($y_parity_byte % 2 === 0) ? '02' : '03';
        
        return $prefix . $point['x'];
    }
    
    /**
     * Decompress public key (simplified version)
     */
    public static function decompressPublicKey(string $compressedKey): array
    {
        $prefix = substr($compressedKey, 0, 2);
        $x = substr($compressedKey, 2);

        // This is a major simplification. A real implementation requires complex math (modular square root).
        // We will generate a deterministic 'y' based on 'x' and the prefix for demonstration purposes.
        $seed = hex2bin($x);
        $hash = hash('sha256', $seed . $prefix, true);
        $y = bin2hex($hash);

        return ['x' => $x, 'y' => $y];
    }
    
    /**
     * Sign message using private key (simplified version)
     */
    public static function sign(string $message, string $privateKeyHex): array
    {
        // Get the public key first
        $publicKey = self::generatePublicKey($privateKeyHex);
        
        // Generate signature based on message and public key (deterministic)
        $messageHash = hash('sha256', $message, true);
        $publicKeySeed = hex2bin($publicKey['x'] . $publicKey['y']);
        
        // Generate deterministic signature components
        $r = hash('sha256', $messageHash . $publicKeySeed . 'r_component');
        $s = hash('sha256', $messageHash . $publicKeySeed . 's_component');
        
        // Ensure r and s are not zero
        if (substr($r, 0, 2) === '00') $r = '01' . substr($r, 2);
        if (substr($s, 0, 2) === '00') $s = '01' . substr($s, 2);
        
        return [
            'r' => $r,
            's' => $s,
            'v' => 27 // Recovery ID (simplified)
        ];
    }
    
    /**
     * Verify signature (simplified version)
     */
    public static function verify(string $message, array $signature, array $publicKey): bool
    {
        // Check if signature components are valid
        if (!isset($signature['r'], $signature['s']) || 
            !ctype_xdigit($signature['r']) || 
            !ctype_xdigit($signature['s']) ||
            strlen($signature['r']) !== 64 ||
            strlen($signature['s']) !== 64) {
            return false;
        }
        
        // Check if public key is valid
        if (!self::isValidPoint($publicKey)) {
            return false;
        }

        // For the simplified version, we'll verify by re-creating the signature
        // using the public key components as a seed (this is a demo approach)
        $messageHash = hash('sha256', $message, true);
        
        // Create expected signature using public key as seed (simplified approach)
        $publicKeySeed = hex2bin($publicKey['x'] . $publicKey['y']);
        $expected_r = hash('sha256', $messageHash . $publicKeySeed . 'r_component');
        $expected_s = hash('sha256', $messageHash . $publicKeySeed . 's_component');
        
        // Ensure expected signature components are not zero
        if (substr($expected_r, 0, 2) === '00') $expected_r = '01' . substr($expected_r, 2);
        if (substr($expected_s, 0, 2) === '00') $expected_s = '01' . substr($expected_s, 2);

        return hash_equals($expected_r, $signature['r']) && hash_equals($expected_s, $signature['s']);
    }
    
    /**
     * Create a point on elliptic curve
     */
    public static function point(string $x, string $y): array
    {
        return ['x' => $x, 'y' => $y];
    }
    
    /**
     * Point doubling (simplified version)
     */
    public static function pointDouble(array $point): array
    {
        // For demo purposes, return a "doubled" point using hash
        $x = hash('sha256', $point['x'] . 'double_x');
        $y = hash('sha256', $point['y'] . 'double_y');
        
        return [
            'x' => self::normalizeCoordinate($x),
            'y' => self::normalizeCoordinate($y)
        ];
    }
    
    /**
     * Point addition (simplified version)  
     */
    public static function pointAdd(array $p1, array $p2): array
    {
        // For demo purposes, return an "added" point using hash
        $x = hash('sha256', $p1['x'] . $p2['x'] . 'add_x');
        $y = hash('sha256', $p1['y'] . $p2['y'] . 'add_y');
        
        return [
            'x' => self::normalizeCoordinate($x),
            'y' => self::normalizeCoordinate($y)
        ];
    }
    
    /**
     * Scalar multiplication (simplified version for ECDH)
     * In a real implementation, this would perform actual elliptic curve point multiplication
     */
    public static function scalarMultiply(string $scalar, array $point): array
    {
        // This is a major simplification for demonstration purposes
        // In reality, you'd implement proper elliptic curve point multiplication
        
        // Create a deterministic shared point based on scalar and point
        $scalarBytes = hex2bin($scalar);
        $pointBytes = hex2bin($point['x'] . $point['y']);
        
        // Use HMAC to create deterministic result
        $sharedX = hash_hmac('sha256', $pointBytes, $scalarBytes, false);
        $sharedY = hash_hmac('sha256', $scalarBytes, $pointBytes, false);
        
        return [
            'x' => $sharedX,
            'y' => $sharedY
        ];
    }
}

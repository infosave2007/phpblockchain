<?php
declare(strict_types=1);

namespace Blockchain\Core\Cryptography;

/**
 * Professional Elliptic Curve Cryptography Implementation
 * 
 * Implements secp256k1 curve operations for Bitcoin/Ethereum compatibility
 */
class EllipticCurve
{
    // secp256k1 parameters
    private const P = '0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F';
    private const A = 0;
    private const B = 7;
    private const GX = '0x79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798';
    private const GY = '0x483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8';
    private const N = '0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';

    /**
     * Point on elliptic curve
     */
    public static function point(string $x, string $y): array
    {
        return ['x' => $x, 'y' => $y];
    }

    /**
     * Point addition on elliptic curve
     */
    public static function pointAdd(?array $p1, ?array $p2): ?array
    {
        if ($p1 === null) return $p2;
        if ($p2 === null) return $p1;

        $x1 = gmp_init($p1['x'], 16);
        $y1 = gmp_init($p1['y'], 16);
        $x2 = gmp_init($p2['x'], 16);
        $y2 = gmp_init($p2['y'], 16);
        $p = gmp_init(self::P, 16);

        if (gmp_cmp($x1, $x2) === 0) {
            if (gmp_cmp($y1, $y2) === 0) {
                // Point doubling
                return self::pointDouble($p1);
            } else {
                // Points are inverses, return point at infinity
                return null;
            }
        }

        // Calculate slope
        $dx = gmp_mod(gmp_sub($x2, $x1), $p);
        $dy = gmp_mod(gmp_sub($y2, $y1), $p);
        $s = gmp_mod(gmp_mul($dy, self::modInverse($dx, $p)), $p);

        // Calculate new point
        $x3 = gmp_mod(gmp_sub(gmp_sub(gmp_pow($s, 2), $x1), $x2), $p);
        $y3 = gmp_mod(gmp_sub(gmp_mul($s, gmp_sub($x1, $x3)), $y1), $p);

        return [
            'x' => gmp_strval($x3, 16),
            'y' => gmp_strval($y3, 16)
        ];
    }

    /**
     * Point doubling on elliptic curve
     */
    public static function pointDouble(?array $p): ?array
    {
        if ($p === null) return null;

        $x = gmp_init($p['x'], 16);
        $y = gmp_init($p['y'], 16);
        $p_mod = gmp_init(self::P, 16);

        // Calculate slope: s = (3*x^2 + a) / (2*y)
        $numerator = gmp_mod(gmp_add(gmp_mul(3, gmp_pow($x, 2)), self::A), $p_mod);
        $denominator = gmp_mod(gmp_mul(2, $y), $p_mod);
        $s = gmp_mod(gmp_mul($numerator, self::modInverse($denominator, $p_mod)), $p_mod);

        // Calculate new point
        $x3 = gmp_mod(gmp_sub(gmp_pow($s, 2), gmp_mul(2, $x)), $p_mod);
        $y3 = gmp_mod(gmp_sub(gmp_mul($s, gmp_sub($x, $x3)), $y), $p_mod);

        return [
            'x' => gmp_strval($x3, 16),
            'y' => gmp_strval($y3, 16)
        ];
    }

    /**
     * Scalar multiplication on elliptic curve
     */
    public static function pointMultiply(string $k, ?array $point = null): ?array
    {
        if ($point === null) {
            // Use generator point
            $point = [
                'x' => self::GX,
                'y' => self::GY
            ];
        }

        $k_int = gmp_init($k, 16);
        $result = null;
        $addend = $point;

        while (gmp_cmp($k_int, 0) > 0) {
            if (gmp_testbit($k_int, 0)) {
                $result = self::pointAdd($result, $addend);
            }
            $addend = self::pointDouble($addend);
            $k_int = gmp_div($k_int, 2);
        }

        return $result;
    }

    /**
     * Generate public key from private key
     */
    public static function generatePublicKey(string $privateKeyHex): array
    {
        // Remove 0x prefix if present
        $privateKeyHex = str_replace('0x', '', $privateKeyHex);
        
        // Ensure private key is in valid range
        $privateKey = gmp_init($privateKeyHex, 16);
        $n = gmp_init(self::N, 16);
        
        if (gmp_cmp($privateKey, 1) < 0 || gmp_cmp($privateKey, $n) >= 0) {
            throw new \Exception('Private key out of range');
        }

        return self::pointMultiply($privateKeyHex);
    }

    /**
     * Modular inverse using extended Euclidean algorithm
     */
    private static function modInverse($a, $m)
    {
        return gmp_invert($a, $m);
    }

    /**
     * Generate ECDSA signature
     */
    public static function sign(string $messageHash, string $privateKeyHex): array
    {
        $privateKey = gmp_init($privateKeyHex, 16);
        $msgHash = gmp_init($messageHash, 16);
        $n = gmp_init(self::N, 16);

        do {
            // Generate random k
            $k = gmp_random_range(1, gmp_sub($n, 1));
            
            // Calculate r = (k * G).x mod n
            $point = self::pointMultiply(gmp_strval($k, 16));
            $r = gmp_mod(gmp_init($point['x'], 16), $n);
            
            if (gmp_cmp($r, 0) === 0) continue;
            
            // Calculate s = k^-1 * (hash + r * privateKey) mod n
            $k_inv = gmp_invert($k, $n);
            $s = gmp_mod(gmp_mul($k_inv, gmp_add($msgHash, gmp_mul($r, $privateKey))), $n);
            
        } while (gmp_cmp($s, 0) === 0);

        return [
            'r' => gmp_strval($r, 16),
            's' => gmp_strval($s, 16)
        ];
    }

    /**
     * Verify ECDSA signature
     */
    public static function verify(string $messageHash, array $signature, array $publicKey): bool
    {
        $msgHash = gmp_init($messageHash, 16);
        $r = gmp_init($signature['r'], 16);
        $s = gmp_init($signature['s'], 16);
        $n = gmp_init(self::N, 16);

        // Verify r and s are in valid range
        if (gmp_cmp($r, 1) < 0 || gmp_cmp($r, $n) >= 0) return false;
        if (gmp_cmp($s, 1) < 0 || gmp_cmp($s, $n) >= 0) return false;

        // Calculate w = s^-1 mod n
        $w = gmp_invert($s, $n);
        
        // Calculate u1 = hash * w mod n
        $u1 = gmp_mod(gmp_mul($msgHash, $w), $n);
        
        // Calculate u2 = r * w mod n
        $u2 = gmp_mod(gmp_mul($r, $w), $n);
        
        // Calculate point = u1 * G + u2 * publicKey
        $point1 = self::pointMultiply(gmp_strval($u1, 16));
        $point2 = self::pointMultiply(gmp_strval($u2, 16), $publicKey);
        $point = self::pointAdd($point1, $point2);
        
        if ($point === null) return false;
        
        // Verify r === point.x mod n
        $x = gmp_mod(gmp_init($point['x'], 16), $n);
        return gmp_cmp($r, $x) === 0;
    }

    /**
     * Compress public key
     */
    public static function compressPublicKey(array $publicKey): string
    {
        $x = $publicKey['x'];
        $y = gmp_init($publicKey['y'], 16);
        
        // If y is even, prefix is 02, if odd, prefix is 03
        $prefix = gmp_testbit($y, 0) ? '03' : '02';
        
        return $prefix . str_pad($x, 64, '0', STR_PAD_LEFT);
    }

    /**
     * Decompress public key
     */
    public static function decompressPublicKey(string $compressedKey): array
    {
        $prefix = substr($compressedKey, 0, 2);
        $x = substr($compressedKey, 2);
        
        $x_coord = gmp_init($x, 16);
        $p = gmp_init(self::P, 16);
        
        // Calculate y^2 = x^3 + 7 (mod p)
        $y_squared = gmp_mod(gmp_add(gmp_pow($x_coord, 3), self::B), $p);
        
        // Calculate y = sqrt(y^2) mod p
        $y = self::modSqrt($y_squared, $p);
        
        // Choose correct y based on prefix
        if (($prefix === '02' && gmp_testbit($y, 0)) || 
            ($prefix === '03' && !gmp_testbit($y, 0))) {
            $y = gmp_sub($p, $y);
        }
        
        return [
            'x' => $x,
            'y' => gmp_strval($y, 16)
        ];
    }

    /**
     * Modular square root (Tonelli-Shanks algorithm)
     */
    private static function modSqrt($a, $p)
    {
        return gmp_powm($a, gmp_div(gmp_add($p, 1), 4), $p);
    }
}

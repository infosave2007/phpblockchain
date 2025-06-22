<?php

namespace Blockchain\Core\Crypto;

/**
 * Cryptographic Hash utilities
 */
class Hash
{
    /**
     * Hash data using Keccak-256
     *
     * @param string $data Data to hash
     * @return string Hex-encoded hash
     */
    public static function keccak256(string $data): string
    {
        return hash('sha3-256', $data);
    }

    /**
     * Hash data using SHA-256
     *
     * @param string $data Data to hash
     * @return string Hex-encoded hash
     */
    public static function sha256(string $data): string
    {
        return hash('sha256', $data);
    }

    /**
     * Hash data using RIPEMD-160
     *
     * @param string $data Data to hash
     * @return string Hex-encoded hash
     */
    public static function ripemd160(string $data): string
    {
        return hash('ripemd160', $data);
    }

    /**
     * Double SHA-256 hash (Bitcoin-style)
     *
     * @param string $data Data to hash
     * @return string Hex-encoded hash
     */
    public static function doubleSha256(string $data): string
    {
        return hash('sha256', hex2bin(hash('sha256', $data)));
    }
}

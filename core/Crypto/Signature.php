<?php

namespace Blockchain\Core\Crypto;

use Blockchain\Core\Cryptography\KeyPair;
use Blockchain\Core\Cryptography\Signature as EcdsaSignature;

/**
 * Signature utilities wrapper
 */
class Signature
{
    /**
     * Create ECDSA signature
     *
     * @param string $message Message to sign
     * @param KeyPair $keyPair Key pair for signing
     * @return string Signature
     */
    public static function sign(string $message, KeyPair $keyPair): string
    {
        $signature = new EcdsaSignature();
        return $signature->sign($message, $keyPair->getPrivateKey());
    }

    /**
     * Verify ECDSA signature
     *
     * @param string $message Original message
     * @param string $signature Signature to verify
     * @param string $publicKey Public key for verification
     * @return bool True if valid
     */
    public static function verify(string $message, string $signature, string $publicKey): bool
    {
        $signatureClass = new EcdsaSignature();
        return $signatureClass->verify($message, $signature, $publicKey);
    }

    /**
     * Recover public key from signature
     *
     * @param string $message Original message
     * @param string $signature Signature
     * @return string|null Recovered public key or null
     */
    public static function recover(string $message, string $signature): ?string
    {
        $signatureClass = new EcdsaSignature();
        return $signatureClass->recoverPublicKey($message, $signature);
    }
}

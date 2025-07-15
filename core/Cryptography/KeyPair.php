<?php
declare(strict_types=1);

namespace Blockchain\Core\Cryptography;

use Blockchain\Wallet\HDWallet;
use Exception;
use kornrunner\Keccak;

/**
 * Class KeyPair
 *
 * Represents an EVM-compatible key pair with derived address.
 * Supports key generation, derivation from mnemonic, and compression.
 *
 * @package RtnChain\Wallet
 */
class KeyPair
{
    /**
     * @var string The private key in hexadecimal format
     */
    private string $privateKey;

    /**
     * @var string The compressed public key in hexadecimal format
     */
    private string $publicKey;

    /**
     * @var string The Ethereum-compatible address derived from the public key
     */
    private string $address;

    /**
     * KeyPair constructor.
     *
     * @param string $priv Private key in hex
     * @param string $pub Public key in hex (compressed)
     * @param string $addr Ethereum-style address (0x-prefixed)
     */
    public function __construct(string $priv, string $pub, string $addr)
    {
        $this->privateKey = $priv;
        $this->publicKey = $pub;
        $this->address = $addr;
    }

    /**
     * Generate a new random key pair.
     *
     * @return self
     * @throws Exception If random_bytes fails
     */
    public static function generate(): self
    {
        $privHex = bin2hex(random_bytes(32));
        return self::fromPrivateKey($privHex);
    }

    /**
     * Derive a key pair from a BIP-39 mnemonic and optional passphrase.
     *
     * @param string $mnemonic Space-separated mnemonic phrase
     * @param string $passphrase Optional passphrase for seed derivation
     * @return self
     * @throws Exception If private key derivation fails
     */
    public static function fromMnemonic(string $mnemonic, string $passphrase = ''): self
    {
        $privHex = HDWallet::derivePrivateKeyFromMnemonic($mnemonic, $passphrase);
        return self::fromPrivateKey($privHex);
    }

    /**
     * Derive the full key pair and address from a private key.
     *
     * @param string $privHex Private key in hexadecimal format
     * @return self
     * @throws Exception If the private key is invalid or processing fails
     */
    public static function fromPrivateKey(string $privHex): self
    {
        $privBin = hex2bin($privHex);
        if ($privBin === false || strlen($privBin) !== 32) {
            throw new Exception("Invalid private key");
        }

        $pt = self::generatePublicKey($privHex);
        $pubHex = self::compressPublicKey($pt);
        $rawXY = hex2bin($pt['x'] . $pt['y']);
        $hash = Keccak::hash($rawXY, 256);
        $addr = '0x' . substr($hash, -40);

        return new self($privHex, $pubHex, $addr);
    }

    /**
     * Get the private key.
     *
     * @return string Private key in hex
     */
    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    /**
     * Get the compressed public key.
     *
     * @return string Public key in hex
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Get the Ethereum-compatible address.
     *
     * @return string Address in 0x-prefixed hex format
     */
    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * Generate a public key (x and y coordinates) from a private key.
     *
     * @param string $privateKeyHex Private key in hexadecimal format
     * @return array{x: string, y: string} Associative array of x and y coordinates in hex
     * @throws Exception If OpenSSL fails to parse or extract coordinates
     */
    public static function generatePublicKey(string $privateKeyHex): array
    {
        $privateKeyBin = hex2bin($privateKeyHex);
        if ($privateKeyBin === false || strlen($privateKeyBin) !== 32) {
            throw new Exception("Invalid private key");
        }

        $pem = self::buildECPrivateKeyPEM($privateKeyHex);
        $key = openssl_pkey_get_private($pem);

        if (!$key) {
            throw new Exception("Failed to parse EC private key from PEM");
        }

        $details = openssl_pkey_get_details($key);
        if (!isset($details['ec']['x'], $details['ec']['y'])) {
            throw new Exception("Failed to extract public key coordinates");
        }

        return [
            'x' => bin2hex($details['ec']['x']),
            'y' => bin2hex($details['ec']['y']),
        ];
    }

    /**
     * Compress an uncompressed public key point (x, y) into a 33-byte hex string.
     *
     * @param array{x: string, y: string} $point Elliptic curve point
     * @return string Compressed public key (hex)
     */
    public static function compressPublicKey(array $point): string
    {
        $yLastByte = hexdec(substr($point['y'], -2));
        $prefix = ($yLastByte % 2 === 0) ? '02' : '03';
        return $prefix . $point['x'];
    }

    /**
     * Build a PEM-encoded EC private key from hex.
     *
     * @param string $privateKeyHex Hex-encoded 32-byte private key
     * @return string PEM-encoded EC private key
     */
    private static function buildECPrivateKeyPEM(string $privateKeyHex): string
    {
        $der = self::buildECPrivateKeyDER($privateKeyHex);
        $pem = "-----BEGIN EC PRIVATE KEY-----\n";
        $pem .= chunk_split(base64_encode($der), 64, "\n");
        $pem .= "-----END EC PRIVATE KEY-----\n";
        return $pem;
    }

    /**
     * Construct a raw ASN.1 DER-encoded EC private key using secp256k1 parameters.
     *
     * @param string $privateKeyHex 32-byte private key in hex
     * @return string Binary DER-encoded EC private key
     */
    private static function buildECPrivateKeyDER(string $privateKeyHex): string
    {
        $privateKeyBin = hex2bin($privateKeyHex);
        $ecParams = hex2bin('a00706052b8104000a'); // secp256k1 OID
        $version = hex2bin('020101'); // version = 1

        $keyOctet = hex2bin('04') . chr(strlen($privateKeyBin)) . $privateKeyBin;
        $keySeq = $version . chr(0x04) . chr(strlen($privateKeyBin)) . $privateKeyBin . $ecParams;
        $seq = chr(0x30) . chr(strlen($keySeq)) . $keySeq;

        return $seq;
    }

}

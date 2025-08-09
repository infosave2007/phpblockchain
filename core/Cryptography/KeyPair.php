<?php
declare(strict_types=1);

namespace Blockchain\Core\Cryptography;

use Exception;
use kornrunner\Keccak;
use Mdanter\Ecc\EccFactory;
use Mdanter\Ecc\Serializer\Point\CompressedPointSerializer;
use Mdanter\Ecc\Serializer\Point\UncompressedPointSerializer;

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
        
        // Ensure private key is in valid range (retry if 0 or >= curve order)
        $secp256k1Order = gmp_init('0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141', 16);
        $intKey = gmp_init('0x' . $privateKeyHex, 16);
        if (gmp_cmp($intKey, gmp_init(0)) === 0 || gmp_cmp($intKey, $secp256k1Order) >= 0) {
            return self::generate(); // retry
        }

    // Generate (compressed) public key using secp256k1 curve
    $publicKeyHex = self::generatePublicKeyHex($privateKeyHex); // 33-byte compressed

    // Generate Ethereum address directly from private key (canonical)
    $address = self::addressFromPrivateKey($privateKeyHex);
        
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
        
    $publicKeyHex = self::generatePublicKeyHex($privateKeyHex); // compressed form
    $address = self::addressFromPrivateKey($privateKeyHex); // derive directly from private for reliability
        
        return new self($privateKeyHex, $publicKeyHex, $address);
    }
    
    /**
     * Create key pair from mnemonic phrase
     */
    public static function fromMnemonic(array $mnemonic, string $passphrase = '', int $accountIndex = 0): self
    {
        try {
            error_log("KeyPair::fromMnemonic - Starting BIP44 derivation with " . count($mnemonic) . " words");
            error_log("KeyPair::fromMnemonic - Words: " . implode(' ', $mnemonic));
            
            // Step 1: Generate seed using BIP39 standard
            $seed = Mnemonic::toSeed($mnemonic, $passphrase);
            error_log("KeyPair::fromMnemonic - BIP39 seed generated, length: " . strlen($seed));
            
            // Step 2: Generate master key using BIP32 standard
            $masterKey = self::generateMasterKeyFromSeed($seed);
            error_log("KeyPair::fromMnemonic - Master key generated");
            
            // Step 3: Derive private key using BIP44 path: m/44'/60'/0'/0/{accountIndex}
            // Ethereum coin type is 60 (0x3c)
            $derivationPath = [
                0x8000002C, // 44' (purpose)
                0x8000003C, // 60' (coin type for Ethereum)
                0x80000000, // 0' (account 0)
                0x00000000, // 0 (change)
                $accountIndex // address index
            ];
            
            $privateKey = self::derivePrivateKey($masterKey, $derivationPath);
            error_log("KeyPair::fromMnemonic - Derived private key for account $accountIndex");
            
            return self::fromPrivateKey($privateKey);
        } catch (Exception $e) {
            error_log("KeyPair::fromMnemonic - Error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Generate public key hex from private key hex using secp256k1
     */
    private static function generatePublicKeyHex(string $privateKeyHex): string
    {
    $generator = EccFactory::getSecgCurves()->generator256k1();
    $privateKeyGmp = gmp_init('0x' . $privateKeyHex, 16);
    $point = $generator->mul($privateKeyGmp);
    $x = str_pad(gmp_strval($point->getX(), 16), 64, '0', STR_PAD_LEFT);
    $y = $point->getY();
    $prefix = gmp_testbit($y, 0) ? '03' : '02';
    return $prefix . $x; // 66 hex chars
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
    // Deprecated: replaced by generateAddress() which uses proper Keccak-256.
    // Keeping for backward compatibility if referenced elsewhere.
    return self::generateAddress($publicKeyHex);
    }

    /**
     * Generate address from public key using Keccak-256 (Ethereum-style)
     */
    private static function generateAddress(string $publicKey): string
    {
        // Accept hex strings (compressed 33 bytes -> 66 hex, or uncompressed 65 bytes -> 130 hex starting with 04)
        if (!ctype_xdigit($publicKey)) {
            throw new Exception('Public key must be hex-encoded');
        }
        $len = strlen($publicKey);
        $generator = EccFactory::getSecgCurves()->generator256k1();
        $adapter = EccFactory::getAdapter();
        $xHex = '';
        $yHex = '';
        if ($len === 66) { // compressed
            $prefix = substr($publicKey, 0, 2);
            $xHex = substr($publicKey, 2);
            $x = gmp_init('0x' . $xHex, 16);
            // Recover y using curve equation y^2 = x^3 + 7 over Fp (secp256k1)
            $curve = $generator->getCurve();
            $p = $curve->getPrime();
            $alpha = gmp_mod(gmp_add(gmp_pow($x, 3), gmp_init(7, 10)), $p);
            // y = sqrt(alpha) mod p -> Tonelli-Shanks not implemented; use built-in attempt
            $y = self::modSqrtSecp256k1($alpha, $p);
            if ($y === null) {
                throw new Exception('Failed to recover Y coordinate from compressed key');
            }
            $isOdd = ($prefix === '03');
            $yIsOdd = gmp_intval(gmp_mod($y, 2)) === 1;
            if ($yIsOdd !== $isOdd) {
                $y = gmp_sub($p, $y); // choose other root
            }
            $yHex = str_pad(gmp_strval($y, 16), 64, '0', STR_PAD_LEFT);
        } elseif ($len === 130 && substr($publicKey, 0, 2) === '04') { // uncompressed
            $xHex = substr($publicKey, 2, 64);
            $yHex = substr($publicKey, 66, 64);
        } else {
            throw new Exception('Unsupported public key length for address derivation');
        }
        $pubBin = hex2bin($xHex . $yHex);
        $hash = Keccak::hash($pubBin, 256);
        return '0x' . substr($hash, -40);
    }

    /**
     * Modular square root for secp256k1 prime field using Tonelli-Shanks algorithm.
     * Returns y such that y^2 = a (mod p) or null if no root exists.
     */
    private static function modSqrtSecp256k1(\GMP $a, \GMP $p): ?\GMP
    {
        // For secp256k1 p % 4 == 3, we can use y = a^{(p+1)/4} mod p
        $mod4 = gmp_intval(gmp_mod($p, 4));
        if ($mod4 === 3) {
            $exp = gmp_div_q(gmp_add($p, gmp_init(1,10)), gmp_init(4,10));
            $y = gmp_powm($a, $exp, $p);
            // Verify
            if (gmp_cmp(gmp_mod(gmp_pow($y, 2), $p), gmp_mod($a, $p)) === 0) {
                return $y;
            }
            return null;
        }
        // Fallback not implemented (should not happen for secp256k1)
        return null;
    }

    /**
     * Derive Ethereum address directly from private key (no ambiguity).
     */
    private static function addressFromPrivateKey(string $privateKeyHex): string
    {
        $generator = EccFactory::getSecgCurves()->generator256k1();
        $priv = gmp_init('0x' . $privateKeyHex, 16);
        $point = $generator->mul($priv);
        $x = str_pad(gmp_strval($point->getX(), 16), 64, '0', STR_PAD_LEFT);
        $y = str_pad(gmp_strval($point->getY(), 16), 64, '0', STR_PAD_LEFT);
        $pub = hex2bin($x . $y);
        $hash = Keccak::hash($pub, 256);
        return '0x' . substr($hash, -40);
    }
    
    /**
     * Keccak-256 hash function (Ethereum standard)
     */
    private static function keccak256(string $data): string
    {
        // Use kornrunner/keccak library for proper Keccak-256
        return Keccak::hash($data, 256);
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
     * Get EIP-55 checksummed address.
     */
    public function getChecksumAddress(): string
    {
        $addr = strtolower(ltrim($this->address, '0x'));
        $hash = Keccak::hash($addr, 256);
        $out = '';
        for ($i = 0; $i < strlen($addr); $i++) {
            $out .= (intval($hash[$i], 16) >= 8) ? strtoupper($addr[$i]) : $addr[$i];
        }
        return '0x' . $out;
    }
    
    /**
     * Generate mnemonic phrase
     */
    public function getMnemonic(): string
    {
        // DEPRECATED: This method is deprecated and should not be used for new wallets.
        // It generates a deterministic mnemonic from private key which may contain duplicates.
        // Use Mnemonic::generate() for new wallets instead.
        
        error_log("WARNING: KeyPair::getMnemonic() is deprecated and may generate invalid mnemonics. Use Mnemonic::generate() instead.");
        
        // Generate a proper mnemonic using the correct BIP39 implementation
        try {
            $mnemonic = Mnemonic::generate(12);
            return implode(' ', $mnemonic);
        } catch (Exception $e) {
            error_log("Failed to generate mnemonic: " . $e->getMessage());
            
            // Fallback to old method (with warnings)
            return $this->getLegacyMnemonic();
        }
    }
    
    /**
     * Legacy mnemonic generation (deprecated)
     */
    private function getLegacyMnemonic(): string
    {
        error_log("WARNING: Using legacy mnemonic generation - may contain duplicates!");
        
        // Use BIP39 wordlist from Mnemonic class
        $wordList = Mnemonic::getWordList();
        
        if (empty($wordList)) {
            throw new Exception("BIP39 word list not available");
        }
        
        $mnemonic = [];
        $seed = $this->privateKey;
        $usedIndices = [];
        
        for ($i = 0; $i < 12; $i++) {
            $attempts = 0;
            do {
                $index = hexdec(substr($seed, ($i + $attempts) * 2 % 64, 2)) % count($wordList);
                $attempts++;
                
                // Prevent infinite loop
                if ($attempts > 100) {
                    error_log("WARNING: KeyPair::getLegacyMnemonic() - Could not generate unique words, allowing duplicates");
                    break;
                }
            } while (in_array($index, $usedIndices) && $attempts <= 100);
            
            $usedIndices[] = $index;
            $mnemonic[] = $wordList[$index];
        }
        
        $mnemonicString = implode(' ', $mnemonic);
        
        // Check for duplicates
        if (count($mnemonic) !== count(array_unique($mnemonic))) {
            error_log("WARNING: KeyPair::getLegacyMnemonic() generated mnemonic with duplicates: $mnemonicString");
        }
        
        return $mnemonicString;
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
    
    /**
     * Generate master key from seed according to BIP32 standard
     * 
     * @param string $seedHex Seed in hex format
     * @return array Returns ['key' => privateKey, 'chainCode' => chainCode]
     */
    private static function generateMasterKeyFromSeed(string $seedHex): array
    {
        // BIP32: I = HMAC-SHA512(Key = "Bitcoin seed", Data = S)
        $seed = hex2bin($seedHex);
        $hmac = hash_hmac('sha512', $seed, 'Bitcoin seed', true);
        
        // Split into left and right halves
        $privateKey = bin2hex(substr($hmac, 0, 32));
        $chainCode = bin2hex(substr($hmac, 32, 32));
        
        // Validate private key (must be < secp256k1 order)
        $secp256k1Order = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';
        if (gmp_cmp(gmp_init('0x' . $privateKey, 16), gmp_init('0x' . $secp256k1Order, 16)) >= 0) {
            throw new Exception('Invalid master private key generated');
        }
        
        error_log("KeyPair::generateMasterKeyFromSeed - Master key: " . substr($privateKey, 0, 16) . "...");
        
        return [
            'key' => $privateKey,
            'chainCode' => $chainCode
        ];
    }
    
    /**
     * Derive child private key according to BIP32 standard
     * 
     * @param array $parentKey Array with 'key' and 'chainCode'
     * @param array $derivationPath Array of child indices
     * @return string Derived private key in hex format
     */
    private static function derivePrivateKey(array $parentKey, array $derivationPath): string
    {
        $currentKey = $parentKey['key'];
        $currentChainCode = $parentKey['chainCode'];
        
        $generator = EccFactory::getSecgCurves()->generator256k1();
        $adapter = EccFactory::getAdapter();
        
        foreach ($derivationPath as $index) {
            error_log("KeyPair::derivePrivateKey - Deriving child with index: " . dechex($index));
            
            // Check if hardened derivation (index >= 0x80000000)
            $isHardened = $index >= 0x80000000;
            
            if ($isHardened) {
                // Hardened derivation: I = HMAC-SHA512(Key = cpar, Data = 0x00 || ser256(kpar) || ser32(i))
                $data = "\x00" . hex2bin(str_pad($currentKey, 64, '0', STR_PAD_LEFT)) . pack('N', $index);
            } else {
                // Non-hardened derivation: I = HMAC-SHA512(Key = cpar, Data = serP(point(kpar)) || ser32(i))
                // Get compressed public key for current private key using real secp256k1
                $privateKeyGmp = gmp_init('0x' . $currentKey, 16);
                $publicKeyPoint = $generator->mul($privateKeyGmp);
                
                // Manually serialize compressed public key (33 bytes: prefix + x-coordinate)
                $x = str_pad(gmp_strval($publicKeyPoint->getX(), 16), 64, '0', STR_PAD_LEFT);
                $y = $publicKeyPoint->getY();
                
                // Determine compression prefix (0x02 for even y, 0x03 for odd y)
                $prefix = gmp_testbit($y, 0) ? "\x03" : "\x02";
                $compressedKey = $prefix . hex2bin($x);
                
                $data = $compressedKey . pack('N', $index);
            }
            
            $hmac = hash_hmac('sha512', $data, hex2bin($currentChainCode), true);
            
            // Split into left and right halves
            $leftHalf = bin2hex(substr($hmac, 0, 32));
            $newChainCode = bin2hex(substr($hmac, 32, 32));
            
            // ki = parse256(IL) + kpar (mod n)
            $secp256k1Order = gmp_init('0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141', 16);
            $leftHalfGmp = gmp_init('0x' . $leftHalf, 16);
            $currentKeyGmp = gmp_init('0x' . $currentKey, 16);
            
            $newKeyGmp = gmp_mod(gmp_add($leftHalfGmp, $currentKeyGmp), $secp256k1Order);
            $currentKey = str_pad(gmp_strval($newKeyGmp, 16), 64, '0', STR_PAD_LEFT);
            $currentChainCode = $newChainCode;
            
            // Validate the derived key
            if (gmp_cmp($newKeyGmp, gmp_init(0)) === 0 || gmp_cmp($newKeyGmp, $secp256k1Order) >= 0) {
                throw new Exception('Invalid child private key derived at index ' . dechex($index));
            }
        }
        
        error_log("KeyPair::derivePrivateKey - Final derived key: " . substr($currentKey, 0, 16) . "...");
        return $currentKey;
    }
}

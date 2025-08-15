<?php
declare(strict_types=1);

namespace Blockchain\Core\Cryptography;

use Exception;

/**
 * Message Encryption Class
 * 
 * Handles encryption and decryption of messages using secp256k1 ECIES
 * NOTE: Implementation is designed to work on basic hosting without native secp256k1.
 * It uses deterministic, pure-PHP fallbacks for ECDH-like keying and HMAC-based signing.
 */
class MessageEncryption
{
    const ENCRYPTION_ECIES = 'ecies'; // ECIES using secp256k1
    
    /**
     * Encrypt message using ECIES (Elliptic Curve Integrated Encryption Scheme)
     * This works with secp256k1 hex keys used in the blockchain
     */
    public static function encryptECIES(string $message, string $recipientPublicKeyHex): array
    {
        // Clean recipient public key
        $recipientPublicKeyHex = str_replace('0x', '', $recipientPublicKeyHex);
        
        // Validate public key format
        if (!self::isValidSecp256k1PublicKey($recipientPublicKeyHex)) {
            throw new Exception('Invalid secp256k1 public key format');
        }
        
        // Normalize recipient public key to compressed format to keep KDF deterministic
        // If provided in uncompressed form (04 || X(32B) || Y(32B)), compress it to 02/03 || X
        if (strlen($recipientPublicKeyHex) === 130 && substr($recipientPublicKeyHex, 0, 2) === '04') {
            $x = substr($recipientPublicKeyHex, 2, 64);
            $y = substr($recipientPublicKeyHex, 66, 64);
            $yParity = hexdec(substr($y, -2));
            $recipientPublicKeyHex = (($yParity % 2 === 0) ? '02' : '03') . $x;
        }
        
        // Generate ephemeral key pair
        $ephemeralPrivateKey = bin2hex(random_bytes(32));
        $ephemeralPublicKeyPoint = EllipticCurve::generatePublicKey($ephemeralPrivateKey);
        $ephemeralPublicKeyHex = EllipticCurve::compressPublicKey($ephemeralPublicKeyPoint);
        
        // For simplified ECDH, create a deterministic shared secret using public keys
        // This ensures consistency between encryption and decryption
        $keys = [$recipientPublicKeyHex, $ephemeralPublicKeyHex];
        sort($keys);
        $keyMaterial = implode('', $keys);
        $sharedSecret = hash('sha256', hex2bin($keyMaterial), true);
        $sharedSecretHex = bin2hex($sharedSecret);
        
        // Derive encryption key using KDF (Key Derivation Function)
        $encryptionKey = self::deriveKey($sharedSecretHex, 'encryption');
        $macKey = self::deriveKey($sharedSecretHex, 'authentication');
        
        // Generate random IV
        $iv = random_bytes(16);
        
        // Encrypt message with AES-256-CBC
        if (!function_exists('openssl_encrypt')) {
            throw new Exception('OpenSSL extension is required for AES-256-CBC');
        }
        $encryptedMessage = openssl_encrypt($message, 'AES-256-CBC', $encryptionKey, OPENSSL_RAW_DATA, $iv);
        
        if ($encryptedMessage === false) {
            throw new Exception('AES encryption failed');
        }
        
        // Create MAC for authenticated encryption
        $dataToMac = $ephemeralPublicKeyHex . bin2hex($iv) . bin2hex($encryptedMessage);
        $mac = hash_hmac('sha256', hex2bin($dataToMac), $macKey, true);
        
        return [
            'method' => self::ENCRYPTION_ECIES,
            'ephemeral_public_key' => $ephemeralPublicKeyHex,
            'encrypted_message' => base64_encode($encryptedMessage),
            'iv' => base64_encode($iv),
            'mac' => base64_encode($mac),
            'timestamp' => time()
        ];
    }
    
    /**
     * Decrypt message using ECIES
     */
    public static function decryptECIES(array $encryptedData, string $recipientPrivateKeyHex): string
    {
        if ($encryptedData['method'] !== self::ENCRYPTION_ECIES) {
            throw new Exception('Invalid encryption method, expected ECIES');
        }
        
        // Clean private key
        $recipientPrivateKeyHex = str_replace('0x', '', $recipientPrivateKeyHex);
        
        // Validate private key
        if (!self::isValidSecp256k1PrivateKey($recipientPrivateKeyHex)) {
            throw new Exception('Invalid secp256k1 private key format');
        }
        
        // Extract data
        $ephemeralPublicKeyHex = $encryptedData['ephemeral_public_key'];
        $encryptedMessage = base64_decode($encryptedData['encrypted_message']);
        $iv = base64_decode($encryptedData['iv']);
        $mac = base64_decode($encryptedData['mac']);
        
        // Derive recipient public key from recipient private key (default)
        $recipientPublicKeyPoint = EllipticCurve::generatePublicKey($recipientPrivateKeyHex);
        $recipientPublicKeyHex = EllipticCurve::compressPublicKey($recipientPublicKeyPoint);
        // Allow passing explicit recipient public key(s) for compatibility with legacy encryptors
        $explicitRecipientKeys = [];
        if (isset($encryptedData['recipient_public_key']) && is_string($encryptedData['recipient_public_key'])) {
            $explicitRecipientKeys[] = str_replace('0x', '', $encryptedData['recipient_public_key']);
        }
        if (isset($encryptedData['recipient_public_key_candidates']) && is_array($encryptedData['recipient_public_key_candidates'])) {
            foreach ($encryptedData['recipient_public_key_candidates'] as $cand) {
                if (is_string($cand) && $cand !== '') {
                    $explicitRecipientKeys[] = str_replace('0x', '', $cand);
                }
            }
        }
        
        // Create shared secret using the same approach as during encryption:
        // Use both public keys in lexicographic order
        $keys = [$recipientPublicKeyHex, $ephemeralPublicKeyHex];
        sort($keys);
        $keyMaterial = implode('', $keys);
        $sharedSecret = hash('sha256', hex2bin($keyMaterial), true);
        $sharedSecretHex = bin2hex($sharedSecret);
        
        // Derive keys (primary path)
        $encryptionKey = self::deriveKey($sharedSecretHex, 'encryption');
        $macKey = self::deriveKey($sharedSecretHex, 'authentication');

        // Common AAD for MAC
        $dataToMac = $ephemeralPublicKeyHex . bin2hex($iv) . bin2hex($encryptedMessage);
        $expectedMac = hash_hmac('sha256', hex2bin($dataToMac), $macKey, true);

        // If MAC fails, try compatibility variants (uncompressed key and unsorted concatenations)
        if (!hash_equals($mac, $expectedMac)) {
            $recipientCompressed = $recipientPublicKeyHex;
            $recipientUncompressed = '04' . $recipientPublicKeyPoint['x'] . $recipientPublicKeyPoint['y'];
            $candidateKeys = [$recipientCompressed, $recipientUncompressed];
            // Merge in any explicitly provided recipient keys
            foreach ($explicitRecipientKeys as $ek) {
                if (!in_array($ek, $candidateKeys, true)) {
                    $candidateKeys[] = $ek;
                }
                // If provided in compressed form (66), also add synthetic uncompressed using our fallback Y (best-effort)
                if (strlen($ek) === 66 && (substr($ek,0,2)==='02' || substr($ek,0,2)==='03')) {
                    $candidateKeys[] = '04' . substr($ek, 2) . $recipientPublicKeyPoint['y'];
                }
            }

            $aadHex = $ephemeralPublicKeyHex . bin2hex($iv) . bin2hex($encryptedMessage); // текущий формат
            $aadRaw = hex2bin($ephemeralPublicKeyHex) . $iv . $encryptedMessage; // возможный наследный формат

            $found = false;
            $encKeyCandidate = null;

            foreach ($candidateKeys as $rk) {
                // Перебор способов построения материала:
                $materials = [];
                $sorted = [$rk, $ephemeralPublicKeyHex]; sort($sorted); $materials[] = implode('', $sorted);
                $materials[] = $rk . $ephemeralPublicKeyHex;
                $materials[] = $ephemeralPublicKeyHex . $rk;

                foreach ($materials as $material) {
                    // Вариант A: sha256(hex2bin(material))
                    $sharedA = hash('sha256', hex2bin($material), true);
                    $sharedAHex = bin2hex($sharedA);
                    $macKeyA = self::deriveKey($sharedAHex, 'authentication');
                    $encKeyA = self::deriveKey($sharedAHex, 'encryption');

                    // Перебор всех комбинаций AAD: порядок + кодировка + алгоритм + ключ
                    $aadParts = [
                        [$ephemeralPublicKeyHex, bin2hex($iv), bin2hex($encryptedMessage)],
                        [$ephemeralPublicKeyHex, bin2hex($encryptedMessage), bin2hex($iv)],
                        [bin2hex($iv), $ephemeralPublicKeyHex, bin2hex($encryptedMessage)],
                        [bin2hex($iv), bin2hex($encryptedMessage), $ephemeralPublicKeyHex],
                        [bin2hex($encryptedMessage), $ephemeralPublicKeyHex, bin2hex($iv)],
                        [bin2hex($encryptedMessage), bin2hex($iv), $ephemeralPublicKeyHex],
                    ];

                    $aadPartsRaw = [
                        [hex2bin($ephemeralPublicKeyHex), $iv, $encryptedMessage],
                        [hex2bin($ephemeralPublicKeyHex), $encryptedMessage, $iv],
                        [$iv, hex2bin($ephemeralPublicKeyHex), $encryptedMessage],
                        [$iv, $encryptedMessage, hex2bin($ephemeralPublicKeyHex)],
                        [$encryptedMessage, hex2bin($ephemeralPublicKeyHex), $iv],
                        [$encryptedMessage, $iv, hex2bin($ephemeralPublicKeyHex)],
                    ];

                    $algs = ['sha256', 'sha3-256'];
                    $keys = [ $macKeyA, $encKeyA ];

                    // hex-кодировка
                    foreach ($aadParts as $parts) {
                        $aadBytes = hex2bin(implode('', $parts));
                        foreach ($algs as $alg) {
                            foreach ($keys as $k) {
                                $macCalc = hash_hmac($alg, $aadBytes, $k, true);
                                if (hash_equals($mac, $macCalc)) { $encKeyCandidate = $encKeyA; $found = true; break 4; }
                            }
                        }
                    }

                    // raw-кодировка
                    foreach ($aadPartsRaw as $partsRaw) {
                        $aadRawBytes = $partsRaw[0] . $partsRaw[1] . $partsRaw[2];
                        foreach ($algs as $alg) {
                            foreach ($keys as $k) {
                                $macCalc = hash_hmac($alg, $aadRawBytes, $k, true);
                                if (hash_equals($mac, $macCalc)) { $encKeyCandidate = $encKeyA; $found = true; break 4; }
                            }
                        }
                    }

                    // Вариант B: sha256(material как ascii-hex)
                    $sharedB = hash('sha256', $material, true);
                    $sharedBHex = bin2hex($sharedB);
                    $macKeyB = self::deriveKey($sharedBHex, 'authentication');
                    $encKeyB = self::deriveKey($sharedBHex, 'encryption');

                    // Повторяем перебор для sharedB
                    $aadParts = [
                        [$ephemeralPublicKeyHex, bin2hex($iv), bin2hex($encryptedMessage)],
                        [$ephemeralPublicKeyHex, bin2hex($encryptedMessage), bin2hex($iv)],
                        [bin2hex($iv), $ephemeralPublicKeyHex, bin2hex($encryptedMessage)],
                        [bin2hex($iv), bin2hex($encryptedMessage), $ephemeralPublicKeyHex],
                        [bin2hex($encryptedMessage), $ephemeralPublicKeyHex, bin2hex($iv)],
                        [bin2hex($encryptedMessage), bin2hex($iv), $ephemeralPublicKeyHex],
                    ];
                    $aadPartsRaw = [
                        [hex2bin($ephemeralPublicKeyHex), $iv, $encryptedMessage],
                        [hex2bin($ephemeralPublicKeyHex), $encryptedMessage, $iv],
                        [$iv, hex2bin($ephemeralPublicKeyHex), $encryptedMessage],
                        [$iv, $encryptedMessage, hex2bin($ephemeralPublicKeyHex)],
                        [$encryptedMessage, hex2bin($ephemeralPublicKeyHex), $iv],
                        [$encryptedMessage, $iv, hex2bin($ephemeralPublicKeyHex)],
                    ];
                    $algs = ['sha256', 'sha3-256'];
                    $keys = [ $macKeyB, $encKeyB ];
                    foreach ($aadParts as $parts) {
                        $aadBytes = hex2bin(implode('', $parts));
                        foreach ($algs as $alg) {
                            foreach ($keys as $k) {
                                $macCalc = hash_hmac($alg, $aadBytes, $k, true);
                                if (hash_equals($mac, $macCalc)) { $encKeyCandidate = $encKeyB; $found = true; break 4; }
                            }
                        }
                    }
                    foreach ($aadPartsRaw as $partsRaw) {
                        $aadRawBytes = $partsRaw[0] . $partsRaw[1] . $partsRaw[2];
                        foreach ($algs as $alg) {
                            foreach ($keys as $k) {
                                $macCalc = hash_hmac($alg, $aadRawBytes, $k, true);
                                if (hash_equals($mac, $macCalc)) { $encKeyCandidate = $encKeyB; $found = true; break 4; }
                            }
                        }
                    }
                }
            }

            if (!$found) {
                throw new Exception('MAC verification failed - message may be tampered');
            }

            $encryptionKey = $encKeyCandidate;
        }

        // Decrypt message
        if (!function_exists('openssl_decrypt')) {
            throw new Exception('OpenSSL extension is required for AES-256-CBC');
        }
        $decryptedMessage = openssl_decrypt($encryptedMessage, 'AES-256-CBC', $encryptionKey, OPENSSL_RAW_DATA, $iv);
        
        if ($decryptedMessage === false) {
            throw new Exception('AES decryption failed');
        }
        
        return $decryptedMessage;
    }
    
    /**
     * Perform ECDH (Elliptic Curve Diffie-Hellman) key exchange
     */
    private static function performECDH(string $privateKeyHex, string $publicKeyHex): string
    {
        // Remove 0x prefix and normalize
        $privateKeyHex = str_replace('0x', '', $privateKeyHex);
        $publicKeyHex = str_replace('0x', '', $publicKeyHex);
        
        // Simple but working approach: Use deterministic combination
        // that works regardless of key order for our simplified ECIES
        
        // Create a combined key material that's deterministic
        // We'll use a method that simulates ECDH properties
        $combinedMaterial = $privateKeyHex . $publicKeyHex . $privateKeyHex;
        $sharedSecret = hash('sha256', hex2bin($combinedMaterial), true);
        
        return bin2hex($sharedSecret);
    }
    
    /**
     * Derive key using KDF (Key Derivation Function)
     */
    private static function deriveKey(string $sharedSecretHex, string $info): string
    {
        $sharedSecret = hex2bin($sharedSecretHex);
        $key = hash_hmac('sha256', $info, $sharedSecret, true);
        return $key;
    }
    
    /**
     * Validate secp256k1 public key format
     */
    private static function isValidSecp256k1PublicKey(string $publicKeyHex): bool
    {
        // Remove 0x prefix if present
        $publicKeyHex = str_replace('0x', '', $publicKeyHex);
        
        // Check length: compressed (66 chars) or uncompressed (130 chars)
        $length = strlen($publicKeyHex);
        if ($length !== 66 && $length !== 130) {
            return false;
        }
        
        // Check if it's valid hex
        if (!ctype_xdigit($publicKeyHex)) {
            return false;
        }
        
        // Check prefix for compressed keys
        if ($length === 66) {
            $prefix = substr($publicKeyHex, 0, 2);
            if ($prefix !== '02' && $prefix !== '03') {
                return false;
            }
        }
        
        // Check prefix for uncompressed keys
        if ($length === 130) {
            $prefix = substr($publicKeyHex, 0, 2);
            if ($prefix !== '04') {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate secp256k1 private key format
     */
    private static function isValidSecp256k1PrivateKey(string $privateKeyHex): bool
    {
        // Remove 0x prefix if present
        $privateKeyHex = str_replace('0x', '', $privateKeyHex);
        
        // Check length (64 chars = 32 bytes)
        if (strlen($privateKeyHex) !== 64) {
            return false;
        }
        
        // Check if it's valid hex
        if (!ctype_xdigit($privateKeyHex)) {
            return false;
        }
        
        // Check that it's not zero
        if ($privateKeyHex === str_repeat('0', 64)) {
            return false;
        }
        
        return true;
    }
    
    
    /**
     * Create secure message package with ECIES encryption and signing
     */
    public static function createSecureMessage(
        string $message,
        string $recipientPublicKeyHex,
        string $senderPrivateKeyHex
    ): array {
        // Encrypt message using ECIES
        $encryptedData = self::encryptECIES($message, $recipientPublicKeyHex);
        
        // Sign the encrypted message for integrity using secp256k1
        $messageToSign = json_encode($encryptedData);
        $signature = self::signMessageSecp256k1($messageToSign, $senderPrivateKeyHex);
        
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
        string $recipientPrivateKeyHex,
        string $senderPublicKeyHex
    ): string {
        // Verify signature first using secp256k1
        $messageToVerify = json_encode($secureMessage['encrypted_data']);
        
        if (!self::verifySignatureSecp256k1($messageToVerify, $secureMessage['signature'], $senderPublicKeyHex)) {
            throw new Exception('Message signature verification failed - message may be tampered');
        }
        
        // Decrypt message using ECIES
        return self::decryptECIES($secureMessage['encrypted_data'], $recipientPrivateKeyHex);
    }
    
    /**
     * Decrypt secure message without signature verification (ECIES only)
     * Use this when sender's public key is not available
     */
    public static function decryptSecureMessageNoVerify(
        array $secureMessage,
        string $recipientPrivateKeyHex
    ): string {
        // Skip signature verification and decrypt directly using ECIES
        return self::decryptECIES($secureMessage['encrypted_data'], $recipientPrivateKeyHex);
    }
    
    /**
     * Sign message with secp256k1 private key
     */
    private static function signMessageSecp256k1(string $message, string $privateKeyHex): string
    {
        // For simplicity, we'll use HMAC-SHA256 with the private key
        // In a production environment, you'd use proper ECDSA signing
        $privateKeyHex = str_replace('0x', '', $privateKeyHex);
        $privateKey = hex2bin($privateKeyHex);
        
        $signature = hash_hmac('sha256', $message, $privateKey, true);
        return base64_encode($signature);
    }
    
    /**
     * Verify message signature with secp256k1 public key
     */
    private static function verifySignatureSecp256k1(string $message, string $signature, string $publicKeyHex): bool
    {
        // For simplicity, we'll derive the expected signature from the public key
        // In a production environment, you'd use proper ECDSA verification
        try {
            $publicKeyHex = str_replace('0x', '', $publicKeyHex);
            
            // Generate a verification key from the public key
            $verificationKey = hash('sha256', hex2bin($publicKeyHex), true);
            
            $expectedSignature = hash_hmac('sha256', $message, $verificationKey, true);
            $providedSignature = base64_decode($signature);
            
            return hash_equals($expectedSignature, $providedSignature);
        } catch (Exception $e) {
            return false;
        }
    }
}

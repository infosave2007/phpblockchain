<?php
declare(strict_types=1);

namespace Blockchain\Core\Cryptography;

use Exception;

/**
 * BIP39 Mnemonic Phrase Generator and Validator
 */
class Mnemonic
{
    private static array $wordList = [];

    /**
     * Generate a new mnemonic phrase.
     *
     * @param int $strength The number of words (12, 15, 18, 21, 24)
     * @return array The mnemonic phrase as an array of words.
     * @throws Exception
     */
    public static function generate(int $strength = 12): array
    {
        if (!in_array($strength, [12, 15, 18, 21, 24])) {
            throw new Exception('Invalid strength, must be 12, 15, 18, 21, or 24.');
        }

        // Правильный расчет энтропии для BIP39
        $entropyLengthBits = match($strength) {
            12 => 128,
            15 => 160, 
            18 => 192,
            21 => 224,
            24 => 256
        };
        
        $entropyBytes = $entropyLengthBits / 8;
        $entropy = random_bytes((int)$entropyBytes);
        
        error_log("Mnemonic::generate - Entropy bytes: " . $entropyBytes . ", hex: " . bin2hex($entropy));
        
        $entropyBits = self::bytesToBits($entropy);
        $checksumBits = self::deriveChecksumBits($entropy);
        $bits = $entropyBits . $checksumBits;
        
        error_log("Mnemonic::generate - Total bits length: " . strlen($bits) . " (expected: " . ($strength * 11) . ")");

        $chunks = str_split($bits, 11); // Each chunk maps to one word index
        self::loadWordList();
        $words = [];
        foreach ($chunks as $chunk) {
            $index = bindec($chunk);
            if ($index >= count(self::$wordList)) {
                throw new Exception('Word index out of range while generating mnemonic');
            }
            // NOTE: Unlike previous implementation we DO NOT enforce uniqueness of words.
            // BIP39 allows repeated words; enforcing uniqueness reduced entropy and broke compatibility.
            $words[] = self::$wordList[$index];
        }
        error_log("Mnemonic::generate - Generated BIP39 mnemonic: " . implode(' ', $words));
        return $words;
    }

    /**
     * Converts a mnemonic phrase to a seed.
     *
     * @param array $mnemonic The mnemonic phrase.
     * @param string $passphrase Optional passphrase.
     * @return string The resulting seed as a hex string.
     */
    public static function toSeed(array $mnemonic, string $passphrase = ''): string
    {
        error_log("Mnemonic::toSeed - Starting with " . count($mnemonic) . " words");
        
        $mnemonicString = implode(' ', $mnemonic);
        error_log("Mnemonic::toSeed - Mnemonic string: " . $mnemonicString);
        
        $salt = 'mnemonic' . $passphrase;
        $result = bin2hex(hash_pbkdf2('sha512', $mnemonicString, $salt, 2048, 64, true));
        
        error_log("Mnemonic::toSeed - Generated seed length: " . strlen($result));
        return $result;
    }

    /**
     * Validate a mnemonic phrase.
     *
     * @param array $mnemonic The mnemonic phrase.
     * @return bool True if valid, false otherwise.
     */
    public static function validate(array $mnemonic): bool
    {
        try {
            self::loadWordList();
            $bits = '';
            foreach ($mnemonic as $word) {
                $index = array_search($word, self::$wordList);
                if ($index === false) {
                    return false; // Word not in list
                }
                $bits .= str_pad(decbin($index), 11, '0', STR_PAD_LEFT);
            }

            $entropyBitsLength = (int)(floor(strlen($bits) / 33) * 32);
            $checksumLength = strlen($bits) - $entropyBitsLength;

            if (!in_array($checksumLength, [4, 5, 6, 7, 8])) {
                return false;
            }

            $entropyBits = substr($bits, 0, $entropyBitsLength);
            $checksumBits = substr($bits, $entropyBitsLength);

            $entropyBytes = self::bitsToBytes($entropyBits);
            $derivedChecksum = self::deriveChecksumBits($entropyBytes);

            return $checksumBits === $derivedChecksum;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get word list (public access)
     */
    public static function getWordList(): array
    {
        self::loadWordList();
        return self::$wordList;
    }
    
    /**
     * Load word list (public access)
     */
    public static function loadWordListPublic(): void
    {
        self::loadWordList();
    }

    private static function deriveChecksumBits(string $entropy): string
    {
        $entropyLength = strlen($entropy) * 8;
        $checksumLength = $entropyLength / 32;
        $hash = hash('sha256', $entropy, true);
        $hashBits = self::bytesToBits($hash);
        return substr($hashBits, 0, (int)$checksumLength);
    }

    private static function bytesToBits(string $bytes): string
    {
        $bits = '';
        for ($i = 0; $i < strlen($bytes); $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        return $bits;
    }

    private static function bitsToBytes(string $bits): string
    {
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            $bytes .= chr(bindec($chunk));
        }
        return $bytes;
    }

    private static function loadWordList(): void
    {
        if (!empty(self::$wordList)) { return; }

        $path = __DIR__ . '/english.txt';
        if (is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $words = [];
            foreach ($lines as $line) {
                $w = trim($line);
                if ($w === '') { continue; }
                if (!preg_match('/^[a-z]+$/', $w)) { continue; }
                $words[] = $w;
            }
            $uniqueCount = count(array_unique($words));
            if (count($words) === 2048 && $uniqueCount === 2048) {
                self::$wordList = $words;
                return;
            }
            error_log('Mnemonic::loadWordList - english.txt failed validation (count=' . count($words) . ', unique=' . $uniqueCount . '), using minimal fallback');
        } else {
            error_log('Mnemonic::loadWordList - english.txt not found, using minimal fallback');
        }

        // Minimal fallback (NOT standard) just to avoid fatal crashes; generation should be avoided until proper list provided.
        self::$wordList = ['abandon','ability','able','about','above','absent','absorb','abstract','absurd','abuse'];
    }
}
?>

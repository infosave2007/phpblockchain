<?php
declare(strict_types=1);

namespace Blockchain\Core\Cryptography;

use Exception;

/**
 * BIP39 Mnemonic Phrase Generator and Validator
 */
class Mnemonic
{
    /**
     * @var string[] Cached word list used for generating mnemonics
     */
    protected static array $wordList = [];

    /**
     * Generate a 12-word BIP-39 compliant mnemonic phrase.
     *
     * @return string Array of 12 words representing the mnemonic phrase
     * @throws Exception If the word list file cannot be read
     */
	public static function generate(): string
	{
		if (empty(self::$wordList)) {
			self::$wordList = explode(
				"\n",
				trim(file_get_contents(__DIR__ . '/../../storage/word_list.txt'))
			);
		}

		$entropy = random_bytes(16); // 128 bits
		$entropyBits = self::bytesToBits($entropy);
		$checksumBits = substr(self::bytesToBits(hash('sha256', $entropy, TRUE)), 0, 4);
		$bits = $entropyBits . $checksumBits;

		$chunks = str_split($bits, 11);
		$mnemonic = [];

		foreach ($chunks as $chunk) {
			$idx = bindec($chunk);
			$mnemonic[] = preg_replace('/\s+/', ' ', trim(self::$wordList[$idx]));
		}

		return implode(' ', $mnemonic);
	}

    /**
     * Converts a mnemonic phrase and optional passphrase into a BIP-39 seed.
     *
     * @param string $mnemonic The mnemonic phrase as a space-separated string
     * @param string $passphrase Optional passphrase (empty by default)
     * @return string 512-bit binary seed (64 bytes)
     * @throws Exception If `ext-intl` is not installed (normalizer required)
     */
    public static function toSeed(string $mnemonic, string $passphrase = ''): string
{
    if (!function_exists('normalizer_normalize')) {
        throw new Exception("ext-intl is required for BIP-39 compliance (normalizer_normalize)");
    }

    $mnemonicN = normalizer_normalize($mnemonic, Normalizer::FORM_KD);
    $saltN = normalizer_normalize('mnemonic' . $passphrase, Normalizer::FORM_KD);

    return hash_pbkdf2('sha512', $mnemonicN, $saltN, 2048, 64, true);
}

    /**
     * Converts a binary string into a string of bits.
     *
     * @param string $bytes Binary string (raw bytes)
     * @return string Binary representation of the input (e.g., "11001010...")
     */
    private static function bytesToBits(string $bytes): string
{
    return implode('', array_map(
        fn($c) => str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT),
        str_split($bytes)
    ));
}
}
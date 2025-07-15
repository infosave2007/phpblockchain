<?php
declare(strict_types=1);

namespace Blockchain\Wallet;

use Blockchain\Core\Cryptography\KeyPair;
use Exception;
use kornrunner\Keccak;
use GMP;

/**
 * BIP-44 HD wallet derivation (m/44'/60'/0'/0/0)
 */
class HDWallet
{
    // secp256k1 curve order
    private const CURVE_ORDER_HEX =
        'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';

    /**
     * Given a BIP-39 mnemonic and optional passphrase, derive the exact
     * Ethereum private key for account #0 (m/44'/60'/0'/0/0).
     *
     * @param string $mnemonic 12- or 24-word phrase
     * @param string $passphrase optional BIP-39 passphrase
     * @return string 64-char hex private key
     * @throws Exception
     */
    public static function derivePrivateKeyFromMnemonic(string $mnemonic, string $passphrase = ''): string
    {
        // 1) BIP-39 seed
        $salt = 'mnemonic' . $passphrase;
        $seed = hash_pbkdf2('sha512', $mnemonic, $salt, 2048, 64, true);

        // 2) Master key & chain code = HMAC-SHA512("Bitcoin seed", seed)
        $I = hash_hmac('sha512', $seed, 'Bitcoin seed', true);
        $k = substr($I, 0, 32);  // master private key
        $c = substr($I, 32, 32); // master chain code

        // 3) Derivation path indices
        $path = [
            44 | 0x80000000,  // 44'
            60 | 0x80000000,  // 60'
            0 | 0x80000000,  // 0'
            0,                // change = 0
            0                 // address_index = 0
        ];

        // 4) Walk path
        foreach ($path as $index) {
            [$k, $c] = self::ckdPriv($k, $c, $index);
        }

        return bin2hex($k);
    }

    /**
     * Child private-key derivation (BIP-32)
     *
     * @param string $kpar raw 32-byte parent private key
     * @param string $cpar raw 32-byte parent chain code
     * @param int $i child index
     * @return array [ raw 32-byte child privkey, raw 32-byte child chain code ]
     * @throws Exception
     */
    private static function ckdPriv(string $kpar, string $cpar, int $i): array
    {
        // data = (hardened? 0x00||kpar : Secp256k1_CompressedPub(kpar)) || ser32(i)
        if ($i & 0x80000000) {
            $data = "\x00" . $kpar;
        } else {
            $point = KeyPair::generatePublicKey(bin2hex($kpar));
            $compressed = hex2bin(KeyPair::compressPublicKey($point));
            $data = $compressed;
        }
        $data .= pack('N', $i);

        // I = HMAC-SHA512(key=cpar, data)
        $I = hash_hmac('sha512', $data, $cpar, true);
        $IL = substr($I, 0, 32);
        $IR = substr($I, 32, 32);

        // kchild = (IL + kpar) mod n
        $n = gmp_init(self::CURVE_ORDER_HEX, 16);
        $ILn = gmp_init(bin2hex($IL), 16);
        $kpar_n = gmp_init(bin2hex($kpar), 16);
        $kchild_n = gmp_mod(gmp_add($ILn, $kpar_n), $n);

        if (gmp_cmp($kchild_n, 0) === 0) {
            throw new Exception("Derived zero key");
        }

        // zero-pad to 32 bytes
        $kchild_hex = str_pad(gmp_strval($kchild_n, 16), 64, '0', STR_PAD_LEFT);
        return [hex2bin($kchild_hex), $IR];
    }
}

<?php
declare(strict_types=1);

namespace Blockchain\Core\Crypto;

use kornrunner\Keccak;
use kornrunner\Secp256k1;
use Mdanter\Ecc\Curves\CurveFactory;
use Mdanter\Ecc\Curves\SecgCurve;
use Mdanter\Ecc\Primitives\PointInterface;
use Mdanter\Ecc\Primitives\GeneratorPoint;
use Mdanter\Ecc\Math\GmpMathInterface;
use Mdanter\Ecc\EccFactory;

/**
 * Minimal Ethereum transaction utilities: RLP encode, hash, address recovery (stub), EIP-1559 fee helpers.
 * Not a full spec implementation – focused on extracting fields and computing effective gas price.
 */
class EthereumTx
{
    public static function keccak256(string $bin): string { return Keccak::hash($bin, 256); }

    public static function intToBin($value): string {
        if (is_string($value)) {
            $v = strtolower($value);
            if (str_starts_with($v,'0x')) $v = substr($v,2);
            if ($v === '' || $v === '0') return '';
            if (strlen($v)%2===1) $v='0'.$v;
            return hex2bin($v) ?: '';
        }
        if (!is_int($value)) $value = (int)$value;
        if ($value === 0) return '';
        $hex = dechex($value);
        if (strlen($hex)%2===1) $hex='0'.$hex;
        return hex2bin($hex) ?: '';
    }

    private static function rlpEncodeStr(string $bin): string {
        $len = strlen($bin);
        if ($len===1 && ord($bin)<=0x7f) return $bin;
        if ($len<=55) return chr(0x80+$len).$bin;
        $lenBytes = self::intToBin($len);
        return chr(0xb7+strlen($lenBytes)).$lenBytes.$bin;
    }
    private static function rlpEncodeList(array $items): string {
        $enc='';
        foreach ($items as $it) {
            if (is_array($it)) $enc .= self::rlpEncodeList($it); else $enc .= self::rlpEncodeStr($it);
        }
        $len = strlen($enc);
        if ($len<=55) return chr(0xc0+$len).$enc;
        $lenBytes = self::intToBin($len);
        return chr(0xf7+strlen($lenBytes)).$lenBytes.$enc;
    }
    public static function rlpEncode(array $list): string {
        $norm=[]; foreach ($list as $v){
            if (is_int($v) || (is_string($v)&&str_starts_with($v,'0x'))) $norm[] = self::intToBin($v);
            elseif (is_string($v)) $norm[]=$v; elseif (is_array($v)) $norm[]=$v; else $norm[]='';
        }
        return self::rlpEncodeList($norm);
    }

    public static function effectiveGasPrice($maxPriority, $maxFee, $gasPriceLegacy): int {
        $toInt = function($h){ if(!$h) return 0; $h=strtolower($h); if(str_starts_with($h,'0x')) $h=substr($h,2); if($h==='') return 0; return intval($h,16); };
        if ($gasPriceLegacy) return $toInt($gasPriceLegacy);
        $mp=$toInt($maxPriority); $mf=$toInt($maxFee);
        if ($mp===0 && $mf===0) return 0;
        if ($mf===0) return $mp; if ($mp===0) return $mf; return min($mf,$mp); // baseFee assumed 0
    }

    public static function recoverAddress(string $rawHex) {
        // Implements basic legacy + EIP-155 typed 0x02 recovery.
        $raw = strtolower($rawHex);
        if (str_starts_with($raw,'0x')) $raw = substr($raw,2);
        if ($raw==='') return null;
        $type = null;
        $firstByte = substr($raw,0,2);
        $body = $raw;
        if (in_array($firstByte,['01','02'])) { $type = $firstByte; $body = substr($raw,2); }
        $bin = @hex2bin($body); if ($bin===false) return null;
        $items = self::simpleRlpTopLevel($bin);
        if (!$items) return null;
        if ($type === '02') {
            if (count($items) < 12) return null; // need sig
            $v = self::gmpFromBin($items[9] ?? '');
            $r = self::gmpFromBin($items[10] ?? '');
            $s = self::gmpFromBin($items[11] ?? '');
            if ($r == 0 || $s == 0) return null;
            // Signing payload: type byte + RLP([chainId..accessList])
            $core = array_slice($items,0,9);
            $enc = self::rlpEncode($core);
            $preimage = hex2bin($type) . $enc;
            $hashHex = self::keccak256($preimage);
            $vInt = (int)gmp_strval($v,10); // For 1559 v is yParity (0 or 1)
            $recIds = [$vInt & 1];
        } else { // legacy
            if (count($items) < 9) return null;
            $v = self::gmpFromBin($items[6]);
            $r = self::gmpFromBin($items[7]);
            $s = self::gmpFromBin($items[8]);
            if ($r == 0 || $s == 0) return null;
            $vInt = (int)gmp_strval($v,10);
            $recId = 0; $chainId = 0;
            if ($vInt >= 35) { $chainId = intdiv($vInt - 35, 2); $recId = ($vInt - 35) - 2*$chainId; }
            else { $recId = $vInt - 27; }
            if ($recId < 0 || $recId > 1) return null;
            $core = array_slice($items,0,6);
            if ($chainId > 0) { $core[] = self::intToBin($chainId); $core[]=''; $core[]=''; }
            $enc = self::rlpEncode($core);
            $hashHex = self::keccak256($enc);
            $recIds = [$recId];
        }
        try {
            $generator = EccFactory::getSecgCurves()->generator256k1();
            $curve = $generator->getCurve();
            $n = $generator->getOrder();
            $p = gmp_init('0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F');
            $hash = gmp_init($hashHex,16);
            foreach ($recIds as $recId) {
                // x = r (no overflow cases for Ethereum recId 0/1)
                $x = $r;
                if (gmp_cmp($x, $p) >= 0) continue;
                // y^2 = x^3 + 7 mod p
                $alpha = gmp_mod(gmp_add(gmp_pow($x,3), gmp_init(7)), $p);
                $beta = self::modSqrt($alpha, $p);
                if ($beta === null) continue;
                $isOdd = gmp_intval(gmp_mod($beta, gmp_init(2)));
                // recId parity must match y parity (beta % 2)
                if ($isOdd !== ($recId & 1)) {
                    // use p - beta
                    $beta = gmp_sub($p, $beta);
                }
                // Point R
                $R = $curve->getPoint($x, $beta, $n);
                // rInv
                $rInv = gmp_invert($r, $n);
                if ($rInv === false) continue;
                // sR
                $sR = $R->mul($s);
                // zG (hash * G)
                $zG = $generator->mul(gmp_mod($hash, $n));
                // sR - zG => sR + (n - (hash mod n))G
                $negZ = gmp_sub($n, gmp_mod($hash, $n));
                $negZG = $generator->mul($negZ);
                $point = $sR->add($negZG);
                // rInv * point
                $Q = $point->mul($rInv);
                if ($Q === null || $Q->isInfinity()) continue;
                $pub = self::pointToUncompressedHex($Q);
                $addr = self::publicKeyToAddress($pub);
                if ($addr) return $addr;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    private static function gmpFromBin(string $bin): \GMP { if ($bin==='') return gmp_init(0); return gmp_init(bin2hex($bin),16); }

    private static function modSqrt(\GMP $a, \GMP $p) {
        // secp256k1 p % 4 == 3, so sqrt = a^{(p+1)/4} mod p when solution exists.
        if (gmp_cmp($a, gmp_init(0)) == 0) return gmp_init(0);
        // Legendre symbol test: a^{(p-1)/2} mod p should be 1
        $ls = gmp_powm($a, gmp_div_q(gmp_sub($p, gmp_init(1)), gmp_init(2)), $p);
        if (gmp_cmp($ls, gmp_init(1)) !== 0) return null; // no sqrt exists
        $exp = gmp_div_q(gmp_add($p, gmp_init(1)), gmp_init(4));
        return gmp_powm($a, $exp, $p);
    }

    private static function pointToUncompressedHex(PointInterface $P): string {
        $x = str_pad(gmp_strval($P->getX(),16),64,'0',STR_PAD_LEFT);
        $y = str_pad(gmp_strval($P->getY(),16),64,'0',STR_PAD_LEFT);
        return '04'.$x.$y;
    }

    public static function publicKeyToAddress(string $uncompressedHex) {
        if (str_starts_with($uncompressedHex,'0x')) $uncompressedHex = substr($uncompressedHex,2);
        if (strlen($uncompressedHex)!==130) return null;
        $bin = hex2bin(substr($uncompressedHex,2)); // skip 04
        if (!$bin) return null;
        $hash = self::keccak256($bin);
        $addr = '0x'.substr($hash,-40);
        return strtolower($addr);
    }

    /**
     * Minimal RLP top-level list decoder returning raw element byte strings.
     * It does NOT recursively decode nested lists (they are returned as their full encoded bytes).
     * This is sufficient for extracting standard Ethereum transaction fields where we only need
     * primitive elements; access lists (EIP-2930/1559) remain opaque but length-parsed.
     * Returns empty array on failure.
     */
    private static function simpleRlpTopLevel(string $bin): array {
        $len = strlen($bin);
        if ($len === 0) return [];
        $pos = 0;
        $prefix = ord($bin[$pos]);
        $listContent = '';
        $posList = 0;
        if ($prefix >= 0xc0 && $prefix <= 0xf7) {
            $l = $prefix - 0xc0; $pos++;
            if ($pos + $l > $len) return [];
            $listContent = substr($bin, $pos, $l);
        } elseif ($prefix >= 0xf8) {
            $ll = $prefix - 0xf7; $pos++;
            if ($pos + $ll > $len) return [];
            $lBytes = substr($bin,$pos,$ll); $pos += $ll;
            $l = hexdec(bin2hex($lBytes));
            if ($pos + $l > $len) return [];
            $listContent = substr($bin,$pos,$l);
        } else {
            // Not a list – invalid top-level for tx
            return [];
        }
        $out = [];
        $lcLen = strlen($listContent);
        while ($posList < $lcLen) {
            $fb = ord($listContent[$posList]);
            if ($fb <= 0x7f) { // single byte
                $out[] = $listContent[$posList];
                $posList += 1;
                continue;
            }
            if ($fb <= 0xb7) { // short string
                $l = $fb - 0x80; $posList++;
                if ($l === 0) { $out[] = ''; continue; }
                if ($posList + $l > $lcLen) return [];
                $out[] = substr($listContent,$posList,$l);
                $posList += $l;
                continue;
            }
            if ($fb <= 0xbf) { // long string
                $ll = $fb - 0xb7; $posList++;
                if ($posList + $ll > $lcLen) return [];
                $lBytes = substr($listContent,$posList,$ll); $posList += $ll;
                $l = hexdec(bin2hex($lBytes));
                if ($posList + $l > $lcLen) return [];
                $out[] = substr($listContent,$posList,$l);
                $posList += $l;
                continue;
            }
            if ($fb <= 0xf7) { // short list – return raw encoded
                $l = $fb - 0xc0; $start = $posList; $posList++;
                if ($posList + $l > $lcLen) return [];
                $out[] = substr($listContent,$start, 1 + $l);
                $posList += $l;
                continue;
            }
            // long list – return raw encoded
            $ll = $fb - 0xf7; $start = $posList; $posList++;
            if ($posList + $ll > $lcLen) return [];
            $lBytes = substr($listContent,$posList,$ll); $posList += $ll;
            $l = hexdec(bin2hex($lBytes));
            if ($posList + $l > $lcLen) return [];
            $out[] = substr($listContent,$start, 1 + $ll + $l);
            $posList += $l;
        }
        return $out;
    }
}

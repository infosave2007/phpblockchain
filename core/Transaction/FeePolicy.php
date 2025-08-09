<?php
declare(strict_types=1);

namespace Blockchain\Core\Transaction;

use PDO;
use PDOException;

/**
 * Centralized transaction fee policy.
 * Reads proportional fee rate from config key 'consensus.reward_rate'.
 * If rate <= 0 all transactions are free (fee = 0).
 */
class FeePolicy
{
    private static ?float $rate = null;
    private static bool $loaded = false;

    public static function load(PDO $pdo): void
    {
        if (self::$loaded) return;
        self::$loaded = true;
        self::$rate = 0.001; // legacy default
        try {
            $stmt = $pdo->prepare("SELECT value FROM config WHERE key_name = 'consensus.reward_rate' LIMIT 1");
            if ($stmt && $stmt->execute()) {
                $val = $stmt->fetchColumn();
                if ($val !== false) {
                    self::$rate = (float)$val; // accept zero or negative
                }
            }
        } catch (PDOException $e) {
            // keep default
        }
    }

    public static function getRate(PDO $pdo): float
    {
        if (!self::$loaded) self::load($pdo);
        return (float)self::$rate;
    }

    public static function computeFee(PDO $pdo, float $amount): float
    {
        $rate = self::getRate($pdo);
        if ($rate <= 0) return 0.0;
        return round($amount * $rate, 8);
    }
}

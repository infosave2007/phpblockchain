<?php
declare(strict_types=1);

namespace Blockchain\Wallet;

class StakingRateHelper
{
    /**
     * Returns reward rate as a fraction (0.12 = 12%) for the given period.
     */
    public static function getRewardRateForPeriod(int $periodDays): float
    {
        // Unified APY scale: 4/6/8/10/12% by period thresholds
        if ($periodDays >= 365) return 0.12; // 12% for 1 year and above
        if ($periodDays >= 180) return 0.10; // 10% for 6 months and above
        if ($periodDays >= 90)  return 0.08; // 8% for 3 months and above
        if ($periodDays >= 30)  return 0.06; // 6% for 1 month and above
        if ($periodDays == 7)   return 0.04; // 4% for 7 days
        return 0.04;                        // 4% by default (< 30 days)
    }

    /**
     * Returns APY as percentage based on the given period.
     */
    public static function getApyPercent(int $periodDays): float
    {
        return self::getRewardRateForPeriod($periodDays) * 100.0;
    }
}
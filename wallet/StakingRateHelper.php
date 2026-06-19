<?php
declare(strict_types=1);

namespace Blockchain\Wallet;

class StakingRateHelper
{
    /** Flat APY for all fixed-term staking (12% annual). */
    public const APY = 0.12;

    /**
     * Allowed fixed staking terms: period in days => human label.
     * 1 month, 6 months, 1 year, 2 years, 3 years.
     */
    public const ALLOWED_PERIODS = [
        30   => '1 month',
        180  => '6 months',
        365  => '1 year',
        730  => '2 years',
        1095 => '3 years',
    ];

    /**
     * List of allowed period lengths (days).
     *
     * @return int[]
     */
    public static function allowedPeriods(): array
    {
        return array_keys(self::ALLOWED_PERIODS);
    }

    /**
     * Whether the given period (days) is one of the supported fixed terms.
     */
    public static function isAllowedPeriod(int $periodDays): bool
    {
        return array_key_exists($periodDays, self::ALLOWED_PERIODS);
    }

    /**
     * Human label for a period, e.g. "2 years".
     */
    public static function labelForPeriod(int $periodDays): string
    {
        return self::ALLOWED_PERIODS[$periodDays] ?? ($periodDays . ' days');
    }

    /**
     * Returns reward rate as a fraction (0.12 = 12%) for the given period.
     * Flat 12% for every supported fixed term.
     */
    public static function getRewardRateForPeriod(int $periodDays): float
    {
        return self::APY;
    }

    /**
     * Returns APY as percentage (always 12.0).
     */
    public static function getApyPercent(int $periodDays): float
    {
        return self::APY * 100.0;
    }
}

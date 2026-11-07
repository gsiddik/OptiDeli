<?php

namespace App\Domain\Tariff;

/**
 * The base tariff (Tarif Dasar) of a mapping row: the initial rate plus the margin. PERCENT is in basis points of the rate,
 * AMOUNT is a fixed amount per unit of the line's basis. Rounded once, to whole minor units, and never typed by a user.
 */
final class BaseTariff
{
    public const MODES = ['PERCENT', 'AMOUNT'];

    public static function price(int $initialMinor, string $mode, int $value): int
    {
        return match ($mode) {
            'PERCENT' => $initialMinor + (int) round($initialMinor * $value / 10000),
            'AMOUNT' => $initialMinor + $value,
            default => throw new \InvalidArgumentException('Unknown margin mode.'),
        };
    }

    /** The minimum charge of the line carries the same margin (a percentage of it, or the fixed amount when there is a minimum). */
    public static function minimum(int $minMinor, string $mode, int $value): int
    {
        return $minMinor > 0 ? self::price($minMinor, $mode, $value) : 0;
    }
}

<?php

namespace App\Domain\Ledger;

/** Minor units (bigint) to the decimal string the feed carries. Never a float. */
final class Money
{
    private const EXPONENTS = ['JPY' => 0, 'KRW' => 0, 'VND' => 0, 'CLP' => 0, 'BHD' => 3, 'KWD' => 3, 'OMR' => 3, 'JOD' => 3];

    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[strtoupper($currency)] ?? 2;
    }

    public static function decimal(int $minor, string $currency): string
    {
        $exponent = self::exponent($currency);
        $sign = $minor < 0 ? '-' : '';
        $digits = str_pad((string) abs($minor), $exponent + 1, '0', STR_PAD_LEFT);

        return $exponent === 0 ? $sign.$digits : $sign.substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }
}

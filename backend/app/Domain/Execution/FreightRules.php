<?php

namespace App\Domain\Execution;

final class FreightRules
{
    public static function tier(int $sell, int $total, int $extra, int $rounding): string
    {
        $exposure = abs($extra) + abs($rounding);
        if (($sell - $total) * 100 < $sell * 5 || $exposure > 200000000) {
            return 'CFO';
        }
        if ($extra === 0 && $rounding === 0) {
            return 'EXACT';
        }
        if ($extra === 0 && abs($rounding) <= 1000000) {
            return 'FINANCE';
        }

        return 'DUAL';
    }
}

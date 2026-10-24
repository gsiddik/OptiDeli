<?php

namespace App\Domain\Commercial;

final class Margin
{
    public static function requiredLevel(int $sell, int $cost, int $thresholdBps): int
    {
        if ($sell <= 0 || $cost < 0 || $sell > 3000000000000 || $cost > 2000000000000 || $thresholdBps < 0 || $thresholdBps > 10000) {
            throw new \InvalidArgumentException('Invalid commercial amount or threshold.');
        }
        $contribution = ($sell - $cost) * 10000;
        if ($contribution >= $sell * $thresholdBps) {
            return 0;
        }
        if ($contribution >= $sell * 1000) {
            return 1;
        }
        if ($contribution >= $sell * 500) {
            return 2;
        }

        return 3;
    }
}

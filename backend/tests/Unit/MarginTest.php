<?php

namespace Tests\Unit;

use App\Domain\Commercial\Margin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarginTest extends TestCase
{
    public static function cases(): array
    {
        return [[10000, 8500, 0], [10000, 8501, 1], [10000, 9000, 1], [10000, 9001, 2], [10000, 9500, 2], [10000, 9501, 3], [10000, 10000, 3], [10000, 12000, 3], [3, 2, 0], [1000000000000, 850000000001, 1]];
    }

    #[DataProvider('cases')]
    public function test_tier_boundaries_without_rounding(int $sell, int $cost, int $level): void
    {
        self::assertSame($level, Margin::requiredLevel($sell, $cost, 1500));
    }

    public function test_zero_sell_is_not_a_valid_margin(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Margin::requiredLevel(0, 0, 1500);
    }
}

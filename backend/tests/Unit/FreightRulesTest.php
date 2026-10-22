<?php

namespace Tests\Unit;

use App\Domain\Execution\FreightRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FreightRulesTest extends TestCase
{
    public static function boundaries(): array
    {
        return [[0, 0, 'EXACT'], [0, 999999, 'FINANCE'], [0, 1000000, 'FINANCE'], [0, 1000001, 'DUAL'], [200000000, 0, 'DUAL'], [200000001, 0, 'CFO'], [10000, 0, 'DUAL'], [200000000, -100, 'CFO']];
    }

    #[DataProvider('boundaries')]
    public function test_boundaries_and_component_offsets(int $extra, int $round, string $tier): void
    {
        self::assertSame($tier, FreightRules::tier(2000000000, 1600000000 + $extra + $round, $extra, $round));
    }

    public function test_low_margin_dominates_exact_matching(): void
    {
        self::assertSame('CFO', FreightRules::tier(100000, 95001, 0, 0));
        self::assertSame('EXACT', FreightRules::tier(100000, 95000, 0, 0));
    }
}

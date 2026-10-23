<?php

namespace App\Services\Adapters\Accounting;

class AccountingSettlementManager
{
    public static function create(): AccountingSettlementPortInterface
    {
        $driver = config('optideli.accounting.driver', 'internal');

        return match ($driver) {
            'optientry' => new OptiEntryAdapter,
            'none' => new NullAccountingAdapter,
            default => new InternalLedgerAdapter,
        };
    }
}

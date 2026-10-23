<?php

namespace App\Services\Adapters\Accounting;

class NullAccountingAdapter implements AccountingSettlementPortInterface
{
    public function syncSettlement(
        string $tenantId,
        string $orderId,
        int $customerArMinor,
        int $vendorApMinor,
        int $accessorialsMinor
    ): array {
        return [
            'order_id' => $orderId,
            'synced' => false,
            'driver' => 'none',
            'message' => 'Sinkronisasi akuntansi dilewati (Driver: none).',
        ];
    }
}

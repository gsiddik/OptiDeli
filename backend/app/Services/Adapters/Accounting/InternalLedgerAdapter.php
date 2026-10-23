<?php

namespace App\Services\Adapters\Accounting;

class InternalLedgerAdapter implements AccountingSettlementPortInterface
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
            'synced' => true,
            'driver' => 'internal',
            'message' => 'Pencatatan keuangan disimpan pada ledger internal OptiDeli (Standalone).',
        ];
    }
}

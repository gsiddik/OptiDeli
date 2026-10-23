<?php

namespace App\Services\Adapters\Accounting;

class OptiEntryAdapter implements AccountingSettlementPortInterface
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
            'driver' => 'optientry',
            'message' => 'Jurnal tagihan berhasil disinkronisasikan ke OptiAccounting/OptiEntry (OA3: AR & OA2: AP).',
        ];
    }
}

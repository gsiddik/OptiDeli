<?php

namespace App\Services\Adapters\Accounting;

interface AccountingSettlementPortInterface
{
    /**
     * Sinkronisasi jurnal utang/piutang setelah 3-Way Freight Audit lolos verifikasi.
     *
     * @return array{
     *   order_id: string,
     *   synced: bool,
     *   driver: string,
     *   message: string
     * }
     */
    public function syncSettlement(
        string $tenantId,
        string $orderId,
        int $customerArMinor,
        int $vendorApMinor,
        int $accessorialsMinor
    ): array;
}

<?php

namespace App\Services\Adapters\Accounting;

use App\Services\Adapters\NexusBridgeResolverService;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OptiEntryAdapter implements AccountingSettlementPortInterface
{
    public function __construct(
        protected ?NexusBridgeResolverService $nexusResolver = null
    ) {
        $this->nexusResolver = $nexusResolver ?? new NexusBridgeResolverService;
    }

    public function syncSettlement(
        string $tenantId,
        string $orderId,
        int $customerArMinor,
        int $vendorApMinor,
        int $accessorialsMinor
    ): array {
        $config = $this->nexusResolver->getBridgeConfig($tenantId, 'accounting');

        if (! $config['enabled'] || empty($config['base_url'])) {
            return [
                'order_id' => $orderId,
                'synced' => false,
                'driver' => 'internal_ledger',
                'message' => 'Integrasi OptiEntry dinonaktifkan di OptiNexus. Tagihan dicatat pada ledger internal.',
            ];
        }

        $endpoint = sprintf('%s/api/v1/app/invoices/freight-sync', rtrim((string) $config['base_url'], '/'));

        try {
            $response = Http::timeout((int) $config['timeout_seconds'])
                ->withHeaders(['X-Tenant-Id' => $tenantId, 'Accept' => 'application/json'])
                ->post($endpoint, [
                    'order_id' => $orderId,
                    'customer_ar_minor_units' => $customerArMinor,
                    'vendor_ap_minor_units' => $vendorApMinor,
                    'accessorials_minor_units' => $accessorialsMinor,
                ]);

            if ($response->successful()) {
                return [
                    'order_id' => $orderId,
                    'synced' => true,
                    'driver' => 'optientry_live',
                    'message' => 'Faktur berhasil dibukukan ke buku besar OptiAccounting/OptiEntry.',
                ];
            }
        } catch (Exception $e) {
            Log::warning(sprintf('Circuit Breaker OptiEntry: Gagal kirim jurnal (%s).', $e->getMessage()));
        }

        return [
            'order_id' => $orderId,
            'synced' => false,
            'driver' => 'optientry_circuit_breaker',
            'message' => 'Koneksi OptiEntry bermasalah. Transaksi disimpan dalam antrean internal.',
        ];
    }
}

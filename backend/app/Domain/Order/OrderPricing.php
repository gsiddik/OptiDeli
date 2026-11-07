<?php

namespace App\Domain\Order;

use App\Domain\Shared\TenantStore;
use App\Domain\Tariff\TariffResolver;
use Illuminate\Validation\ValidationException;

/**
 * The price of an order from the transporter price mapping. Every cargo line is priced by its own unit (the mapping row of the
 * transporter, the fleet type, the lane and that unit, with the margin that applies to the customer) and the amounts are added up.
 * A fixed selling tariff of the customer or project for the same lane and unit wins over the computed price. The result carries
 * no buying price: it is what an order may show and keep.
 */
final class OrderPricing
{
    public function __construct(private readonly TenantStore $store) {}

    /**
     * The fleet type to price with: the one of the chosen vehicle, or the one given. The vehicle must belong to the transporter.
     *
     * @return array{carrier_type_id: string, vehicle_id: ?string}
     */
    public function fleet(?string $transporterVendorId, ?string $vehicleId, ?string $carrierTypeId): array
    {
        if ($vehicleId !== null) {
            $vehicle = $this->store->query('vehicles')->where('id', $vehicleId)->first() ?? throw ValidationException::withMessages(['vehicle_id' => 'Kendaraan tidak ditemukan.']);
            if (($vehicle->vendor_id ?? null) !== $transporterVendorId) {
                throw ValidationException::withMessages(['vehicle_id' => 'Kendaraan bukan milik transporter yang dipilih.']);
            }
            if (! $vehicle->active || $vehicle->verification_status !== 'VERIFIED') {
                throw ValidationException::withMessages(['vehicle_id' => 'Kendaraan ini tidak aktif atau belum diverifikasi.']);
            }
            if ($vehicle->carrier_type_id === null) {
                throw ValidationException::withMessages(['vehicle_id' => 'Kendaraan ini belum memiliki tipe armada; lengkapi dulu di data armada.']);
            }
            if ($carrierTypeId !== null && $carrierTypeId !== $vehicle->carrier_type_id) {
                throw ValidationException::withMessages(['carrier_type_id' => 'Tipe armada tidak sama dengan tipe kendaraan yang dipilih.']);
            }

            return ['carrier_type_id' => $vehicle->carrier_type_id, 'vehicle_id' => $vehicle->id];
        }
        if ($carrierTypeId === null) {
            throw ValidationException::withMessages(['vehicle_id' => 'Pilih kendaraan atau tipe armada.']);
        }

        return ['carrier_type_id' => $carrierTypeId, 'vehicle_id' => null];
    }

    /**
     * @param  array<string, mixed>  $order  customer_id, project_id, origin_location_id, destination_location_id, transporter_vendor_id
     * @param  list<array<string, mixed>>  $items  each with unit_id and quantity
     * @return array{items: list<array<string, mixed>>, total_minor: int, unpriced: list<int>, warnings: list<string>}
     */
    public function price(array $order, string $carrierTypeId, array $items, ?string $date = null): array
    {
        $resolver = new TariffResolver($this->store);
        $priced = [];
        $unpriced = [];
        $warnings = [];
        $total = 0;
        foreach (array_values($items) as $i => $item) {
            $result = $resolver->resolve(array_filter([
                'date' => $date, 'customer_id' => $order['customer_id'] ?? null, 'project_id' => $order['project_id'] ?? null, 'vendor_id' => $order['transporter_vendor_id'] ?? null,
                'origin_location_id' => $order['origin_location_id'], 'destination_location_id' => $order['destination_location_id'],
                'carrier_type_id' => $carrierTypeId, 'unit_id' => $item['unit_id'], 'quantities' => ['unit' => (int) $item['quantity']],
            ], fn ($v) => $v !== null));
            $lines = array_values(array_filter($result['lines'], fn ($l) => ($l['sell']['basis'] ?? null) === 'UNIT' && ($l['sell']['amount_minor'] ?? 0) > 0));
            $row = ['index' => $i, 'description' => $item['description'] ?? null, 'unit_id' => $item['unit_id'], 'quantity' => (int) $item['quantity']];
            if ($lines === []) {
                $unpriced[] = $i;
                $priced[] = $row + ['priced' => false, 'amount_minor' => null, 'lines' => []];
                $warnings = array_merge($warnings, $result['warnings']);

                continue;
            }
            $amount = array_sum(array_map(fn ($l) => $l['sell']['amount_minor'], $lines));
            $total += $amount;
            $first = $lines[0]['sell'];
            $priced[] = $row + [
                'priced' => true, 'amount_minor' => $amount, 'unit_price_minor' => $first['unit_price_minor'], 'source' => $first['source'], 'scope' => $first['mapping']['scope'] ?? null,
                'rate_table_id' => $first['rate_table_id'] ?? null, 'table_code' => $first['table_code'] ?? null, 'version' => $first['version'] ?? null, 'rate_line_id' => $first['rate_line_id'] ?? null,
                'lines' => array_map(fn ($l) => ['charge_code' => $l['charge_code']['code'], 'unit_price_minor' => $l['sell']['unit_price_minor'], 'amount_minor' => $l['sell']['amount_minor']], $lines),
            ];
        }

        return ['items' => $priced, 'total_minor' => $total, 'unpriced' => $unpriced, 'warnings' => array_values(array_unique($warnings))];
    }
}

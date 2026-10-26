<?php

namespace App\Domain\Planning;

use App\Domain\Shared\TenantStore;
use App\Domain\Tariff\TariffResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Overnight and detention from the arrival and departure times of a stop. The free time comes from the tariff line of the charge
 * code (free_time_hours). Detention is the waiting beyond the free hours, charged per started day; overnight is the number of
 * midnights (Asia/Jakarta) spent at the stop beyond the free nights. Both sides are priced from the tariffs of the SPM; the
 * charges are PLAN lines tied to the stop, so recording the times again replaces them.
 */
final class StopCharges
{
    private const ZONE = 'Asia/Jakarta';

    public function __construct(private readonly TenantStore $store) {}

    /** @return list<string> warnings */
    public function apply(object $trip, object $stop): array
    {
        $this->store->query('spm_charges')->where('trip_stop_id', $stop->id)->delete();
        if (! $stop->arrived_at || ! $stop->departed_at) {
            return [];
        }
        $spm = $this->store->find('transport_orders', $trip->transport_order_id);
        if (! $spm->origin_location_id || ! $spm->destination_location_id) {
            return [];
        }
        $arrived = CarbonImmutable::parse($stop->arrived_at);
        $departed = CarbonImmutable::parse($stop->departed_at);
        $hours = max(0, $arrived->diffInMinutes($departed, true) / 60);
        $nights = $arrived->setTimezone(self::ZONE)->startOfDay()->diffInDays($departed->setTimezone(self::ZONE)->startOfDay());
        $warnings = [];
        $resolver = new TariffResolver($this->store);
        foreach (['DETENTION' => $hours, 'OVERNIGHT' => $nights] as $code => $measure) {
            $charge = $this->store->query('charge_codes')->where('code', $code)->where('active', true)->first();
            if ($charge === null || $measure <= 0) {
                continue;
            }
            $context = array_filter([
                'date' => $departed->setTimezone(self::ZONE)->toDateString(), 'customer_id' => $spm->customer_id, 'project_id' => $spm->project_id, 'vendor_id' => $trip->vendor_id,
                'origin_location_id' => $spm->origin_location_id, 'destination_location_id' => $spm->destination_location_id, 'carrier_type_id' => $spm->carrier_type_id,
                'service_id' => $spm->service_id, 'charge_code_ids' => [$charge->id],
            ], fn ($v) => $v !== null);
            $probe = $resolver->resolve($context + ['quantities' => ['day' => 1, 'trips' => 1]]);
            foreach ($probe['lines'] as $line) {
                foreach (['SELL' => $line['sell'], 'BUY' => $line['buy'] ?? null] as $side => $priced) {
                    if ($priced === null || ! in_array($priced['basis'], ['DAY', 'TRIP'], true)) {
                        continue;
                    }
                    $free = (int) ($priced['free_time_hours'] ?? 0);
                    $quantity = $code === 'DETENTION' ? (int) ceil(max(0, $measure - $free) / 24) : max(0, (int) $measure - intdiv($free, 24));
                    if ($quantity <= 0) {
                        continue;
                    }
                    $quantity = $priced['basis'] === 'TRIP' ? 1 : $quantity;
                    $amount = max((int) $priced['min_charge_minor'], (int) $priced['unit_price_minor'] * $quantity);
                    DB::table('spm_charges')->insert([
                        'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'transport_order_id' => $spm->id, 'side' => $side, 'charge_code_id' => $charge->id,
                        'kind' => 'PLAN', 'source' => 'RATE', 'basis' => $priced['basis'], 'quantity' => $quantity, 'unit_price_minor' => $priced['unit_price_minor'], 'amount_minor' => $amount,
                        'rate_table_id' => $priced['rate_table_id'] ?? null, 'rate_label' => isset($priced['table_code']) ? $priced['table_code'].' v'.$priced['version'] : null,
                        'status' => 'PROPOSED', 'reason' => sprintf('%s di stop %d: %s s/d %s', $code === 'DETENTION' ? 'Tunggu' : 'Menginap', $stop->sequence, $arrived->setTimezone(self::ZONE)->format('d/m H:i'), $departed->setTimezone(self::ZONE)->format('d/m H:i')),
                        'trip_stop_id' => $stop->id, 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->store->event('spm.stop_charge', $spm->id, ['spm' => $spm->number, 'code' => $code, 'side' => $side, 'quantity' => $quantity, 'amount_minor' => $amount]);
                }
            }
            if ($probe['lines'] === [] && ($code === 'OVERNIGHT' || $hours >= 12)) {
                $warnings[] = "Menunggu/menginap di stop {$stop->sequence} melewati batas, tetapi tidak ada tarif {$code} yang berlaku. Catat biaya manual bila perlu.";
            }
        }

        return $warnings;
    }
}

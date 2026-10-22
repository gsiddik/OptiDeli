<?php

namespace App\Domain\Execution;

use App\Domain\Dispatch\FleetAvailability;
use App\Domain\Shared\TenantStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TripService
{
    public function transition(TenantStore $s, string $id, string $action, ?string $reason = null): object
    {
        return DB::transaction(function () use ($s, $id, $action, $reason) {
            DB::table('tenants')->where('id', $s->tenantId)->lockForUpdate()->firstOrFail();
            $trip = $s->find('trips', $id, true);
            if ($action === 'cancel') {
                abort_unless($trip->status === 'ASSIGNED', 409, 'Hanya trip yang belum berangkat dapat dibatalkan.');
                abort_if($s->query('dock_appointments')->where('trip_id', $id)->whereIn('status', ['GATE_IN', 'AT_DOCK', 'COMPLETED'])->exists(), 409, 'Armada masih berada di yard.');
                $s->query('dock_appointments')->where('trip_id', $id)->where('status', 'BOOKED')->update(['status' => 'CANCELLED', 'updated_at' => now()]);
                $s->query('trips')->where('id', $id)->update(['status' => 'CANCELLED', 'active' => false, 'cancellation_reason' => $reason, 'updated_at' => now()]);
                $s->query('orders')->where('id', $trip->order_id)->update(['status' => 'CONFIRMED', 'updated_at' => now()]);
            } elseif ($action === 'start') {
                abort_unless($trip->status === 'ASSIGNED', 409, 'Trip tidak dalam status siap berangkat.');
                $vehicle = $s->find('vehicles', $trip->vehicle_id, true);
                $driver = $s->find('drivers', $trip->driver_id, true);
                $plan = $s->find('dispatch_plans', $trip->plan_id);
                app(FleetAvailability::class)->assertReady($vehicle, $driver, CarbonImmutable::parse($plan->ends_at)->max(now()));
                abort_unless($s->query('trip_legs')->where('trip_id', $id)->exists(), 422, 'Tambahkan leg perjalanan terlebih dahulu.');
                $s->query('trips')->where('id', $id)->update(['status' => 'IN_TRANSIT', 'started_at' => now(), 'updated_at' => now()]);
                $s->query('orders')->where('id', $trip->order_id)->update(['status' => 'IN_TRANSIT', 'updated_at' => now()]);
            } elseif ($action === 'complete') {
                abort_unless($trip->status === 'ARRIVED', 409, 'Semua leg harus tiba sebelum penyelesaian.');
                abort_if($s->query('dock_appointments')->where('trip_id', $id)->whereIn('status', ['GATE_IN', 'AT_DOCK', 'COMPLETED'])->exists(), 409, 'Catat gate-out yard sebelum penyelesaian.');
                $pod = $s->query('proofs_of_delivery')->where('trip_id', $id)->where('status', 'VERIFIED')->first();
                $expected = (int) $s->query('order_items')->where('order_id', $trip->order_id)->sum('quantity');
                abort_unless($pod && PrivateEvidence::valid($pod) && $pod->outcome === 'DELIVERED' && $pod->delivered_units === $expected, 422, 'ePOD terverifikasi dan jumlah kirim lengkap diperlukan. Pengecualian muatan harus diselesaikan dahulu.');
                $s->query('trips')->where('id', $id)->update(['status' => 'COMPLETED', 'active' => false, 'completed_at' => now(), 'updated_at' => now()]);
                $s->query('orders')->where('id', $trip->order_id)->update(['status' => 'DELIVERED', 'updated_at' => now()]);
            } else {
                abort(422, 'Aksi trip tidak dikenal.');
            }
            $s->event('trip.'.$action, $id, ['reason' => $reason]);
            app(FreightAuditService::class)->refreshTrip($s, $id);

            return $s->find('trips', $id);
        }, 3);
    }

    public function addLeg(TenantStore $s, string $id, array $data): object
    {
        return DB::transaction(function () use ($s, $id, $data) {
            DB::table('tenants')->where('id', $s->tenantId)->lockForUpdate()->firstOrFail();
            $trip = $s->find('trips', $id, true);
            abort_unless($trip->status === 'ASSIGNED', 409, 'Rute dikunci setelah trip berangkat.');
            $previous = $s->query('trip_legs')->where('trip_id', $id)->orderByDesc('sequence')->first();
            if ($previous) {
                abort_unless($previous->destination === $data['origin'], 422, 'Asal leg harus menyambung tujuan leg sebelumnya.');
                abort_unless(CarbonImmutable::parse($data['planned_eta'])->greaterThan(CarbonImmutable::parse($previous->planned_eta)), 422, 'ETA harus berurutan.');
            }
            $leg = (string) Str::uuid();
            DB::table('trip_legs')->insert([...$data, 'id' => $leg, 'tenant_id' => $s->tenantId, 'trip_id' => $id, 'sequence' => ($previous?->sequence ?? 0) + 1, 'status' => 'PLANNED']);
            $s->event('trip.leg_added', $leg, ['trip_id' => $id]);

            return $s->find('trip_legs', $leg);
        }, 3);
    }

    public function legTransition(TenantStore $s, string $id, string $action): object
    {
        return DB::transaction(function () use ($s, $id, $action) {
            DB::table('tenants')->where('id', $s->tenantId)->lockForUpdate()->firstOrFail();
            $leg = $s->find('trip_legs', $id, true);
            $trip = $s->find('trips', $leg->trip_id, true);
            abort_unless($trip->status === 'IN_TRANSIT', 409, 'Trip belum dalam perjalanan.');
            if ($action === 'depart') {
                abort_unless($leg->status === 'PLANNED', 409, 'Leg tidak menunggu keberangkatan.');
                abort_if($s->query('trip_legs')->where('trip_id', $trip->id)->where('sequence', '<', $leg->sequence)->where('status', '<>', 'ARRIVED')->exists(), 409, 'Leg sebelumnya belum tiba.');
                $s->query('trip_legs')->where('id', $id)->update(['status' => 'IN_TRANSIT', 'started_at' => now()]);
            } else {
                abort_unless($leg->status === 'IN_TRANSIT', 409, 'Leg belum berangkat.');
                $s->query('trip_legs')->where('id', $id)->update(['status' => 'ARRIVED', 'arrived_at' => now()]);
                if (! $s->query('trip_legs')->where('trip_id', $trip->id)->where('status', '<>', 'ARRIVED')->exists()) {
                    $s->query('trips')->where('id', $trip->id)->update(['status' => 'ARRIVED', 'updated_at' => now()]);
                }
            }
            $s->event('trip.leg_'.$action, $id, ['trip_id' => $trip->id]);

            return $s->find('trip_legs', $id);
        }, 3);
    }
}

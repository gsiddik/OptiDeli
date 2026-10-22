<?php

namespace App\Domain\Execution;

use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class YardService
{
    public function book(TenantStore $s, array $data, string $key): object
    {
        return $s->idempotent('appointment.create', $key, $data, function () use ($s, $data) {
            $s->find('docks', $data['dock_id'], true);
            $trip = $s->find('trips', $data['trip_id'], true);
            abort_unless($trip->active, 422, 'Trip telah ditutup.');
            abort_if($s->query('dock_appointments')->where('dock_id', $data['dock_id'])->whereNotIn('status', ['CANCELLED', 'GATE_OUT'])->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->exists(), 409, 'Slot dock bertabrakan dengan reservasi lain.');
            abort_if($s->query('dock_appointments')->where('trip_id', $trip->id)->whereNotIn('status', ['CANCELLED', 'GATE_OUT'])->exists(), 409, 'Trip masih mempunyai kunjungan yard aktif.');
            $id = (string) Str::uuid();
            DB::table('dock_appointments')->insert([...$data, 'id' => $id, 'tenant_id' => $s->tenantId, 'status' => 'BOOKED', 'created_at' => now(), 'updated_at' => now()]);
            $s->event('yard.booked', $id);

            return $s->find('dock_appointments', $id);
        });
    }

    public function transition(TenantStore $s, string $id, string $action): object
    {
        return DB::transaction(function () use ($s, $id, $action) {
            DB::table('tenants')->where('id', $s->tenantId)->lockForUpdate()->firstOrFail();
            $a = $s->find('dock_appointments', $id, true);
            $s->find('docks', $a->dock_id, true);
            [$expected,$status,$column] = match ($action) {
                'gate_in' => ['BOOKED', 'GATE_IN', 'gate_in_at'],'dock_in' => ['GATE_IN', 'AT_DOCK', 'dock_in_at'],'dock_out' => ['AT_DOCK', 'COMPLETED', 'dock_out_at'],'gate_out' => ['COMPLETED', 'GATE_OUT', 'gate_out_at'],'cancel' => ['BOOKED', 'CANCELLED', null],default => abort(422)
            };
            abort_unless($a->status === $expected, 409, 'Urutan pergerakan yard tidak valid.');
            if ($action === 'dock_in') {
                abort_if($s->query('dock_appointments')->where('dock_id', $a->dock_id)->where('status', 'AT_DOCK')->where('id', '<>', $id)->exists(), 409, 'Dock masih ditempati armada lain.');
            }
            $update = ['status' => $status, 'updated_at' => now()];
            if ($column) {
                $update[$column] = now();
            }
            $s->query('dock_appointments')->where('id', $id)->update($update);
            $s->event('yard.'.$action, $id, ['trip_id' => $a->trip_id]);

            return $s->find('dock_appointments', $id);
        }, 3);
    }
}

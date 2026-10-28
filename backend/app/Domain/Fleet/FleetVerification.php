<?php

namespace App\Domain\Fleet;

use App\Domain\Integration\Master\VendorMaster;
use App\Domain\Shared\TenantStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The tenant's side of a vendor's fleet: look at the documents of a vehicle or driver a vendor registered and verify or reject it.
 * Verifying a vehicle sets the date its documents hold until (the earlier of STNK and KIR) and a driver's licence and eligibility
 * dates (the SIM's), which is what the dispatch checks already use; a rejected or pending one cannot be assigned.
 */
final class FleetVerification
{
    public function __construct(private readonly TenantStore $store) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private function table(string $type): string
    {
        return $type === 'VEHICLE' ? 'vehicles' : 'drivers';
    }

    /** @return array{vehicles: list<object>, drivers: list<object>} the vendors' vehicles and drivers in a verification state */
    public function queue(string $status): array
    {
        abort_unless(in_array($status, ['PENDING', 'VERIFIED', 'REJECTED'], true), 422, 'Status tidak dikenal.');
        $vendors = $this->store->query('vendors')->pluck('name', 'id');
        $types = $this->store->query('carrier_types')->pluck('name', 'id');
        $out = [];
        foreach (['VEHICLE' => 'vehicles', 'DRIVER' => 'drivers'] as $type => $table) {
            $rows = $this->store->query($table)->whereNotNull('vendor_id')->where('verification_status', $status)->orderBy('created_at')->limit(200)->get();
            $docs = $this->store->query('fleet_documents')->where('owner_type', $type)->whereIn('owner_id', $rows->pluck('id'))->get(['id', 'owner_id', 'kind', 'number', 'valid_until', 'mime'])->groupBy('owner_id');
            foreach ($rows as $row) {
                $row->vendor_label = $vendors[$row->vendor_id] ?? null;
                $row->carrier_type_label = $type === 'VEHICLE' && $row->carrier_type_id ? ($types[$row->carrier_type_id] ?? null) : null;
                (new VendorFleet($this->store, $row->vendor_id))->annotate($row, $type, $docs[$row->id] ?? collect());
            }
            $out[$table] = $rows->all();
        }

        return $out;
    }

    public function decide(string $type, string $id, array $input): object
    {
        $d = Validator::make($input, ['decision' => ['required', Rule::in(['VERIFY', 'REJECT'])], 'note' => 'nullable|string|max:500'])->validate();
        abort_unless(in_array($type, ['VEHICLE', 'DRIVER'], true), 404);

        return DB::transaction(function () use ($type, $id, $d) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $row = $this->store->query($this->table($type))->where('id', $id)->whereNotNull('vendor_id')->lockForUpdate()->first() ?? abort(404);
            $update = ['verified_by' => $this->store->actorId, 'verified_at' => now(), 'verification_note' => $d['note'] ?? null, 'updated_at' => now()];
            if ($d['decision'] === 'REJECT') {
                if (mb_strlen(trim((string) ($d['note'] ?? ''))) < 5) {
                    $this->fail('note', 'Alasan penolakan wajib diisi (minimal 5 karakter).');
                }
                $update['verification_status'] = 'REJECTED';
            } else {
                $update += $this->verified($type, $row);
            }
            DB::table($this->table($type))->where('tenant_id', $this->store->tenantId)->where('id', $id)->update($update);
            $this->store->event($d['decision'] === 'VERIFY' ? 'vendor_fleet.verified' : 'vendor_fleet.rejected', $id, ['owner_type' => $type, 'vendor_id' => $row->vendor_id, 'note' => $d['note'] ?? null]);
            if ($d['decision'] === 'VERIFY') {
                VendorMaster::publish($this->store->tenantId, $type, $id); // for OptiRadar only; OptiFleet does not maintain a vendor's vehicles
            }

            return $this->store->find($this->table($type), $id);
        }, 3);
    }

    /** @return array<string, mixed> what a verified vehicle or driver carries */
    private function verified(string $type, object $row): array
    {
        $docs = $this->store->query('fleet_documents')->where('owner_type', $type)->where('owner_id', $row->id)->get()->keyBy('kind');
        $missing = array_values(array_diff(VendorFleet::REQUIRED[$type], $docs->keys()->all()));
        if ($missing !== []) {
            $this->fail('decision', 'Dokumen belum lengkap: '.implode(', ', $missing).'.');
        }
        $today = CarbonImmutable::today();
        foreach (VendorFleet::EXPIRING as $kind) {
            if (isset($docs[$kind]) && CarbonImmutable::parse($docs[$kind]->valid_until)->lt($today)) {
                $this->fail('decision', "Dokumen {$kind} sudah kedaluwarsa.");
            }
        }
        if ($type === 'VEHICLE') {
            if (! $row->carrier_type_id) {
                $this->fail('decision', 'Tipe kendaraan belum diisi.');
            }
            $until = min($docs['STNK']->valid_until, $docs['KIR']->valid_until);

            return ['verification_status' => 'VERIFIED', 'documents_valid_until' => $until, 'readiness_checked_at' => now()];
        }
        $until = (string) $docs['SIM']->valid_until;

        return ['verification_status' => 'VERIFIED', 'license_valid_until' => $until, 'eligibility_verified_until' => CarbonImmutable::parse($until)->endOfDay()];
    }
}

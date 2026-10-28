<?php

namespace App\Domain\Fleet;

use App\Domain\Integration\Master\VendorMaster;
use App\Domain\Shared\TenantStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What a vendor does with its own vehicles and drivers through its portal: register them, keep their data, upload their documents and
 * switch them off. Nothing here is verified: a new or changed vehicle or driver waits (PENDING) until the tenant has looked at the
 * documents, and only a verified one can be assigned to an SPM.
 */
final class VendorFleet
{
    public const REQUIRED = ['VEHICLE' => ['STNK', 'KIR'], 'DRIVER' => ['SIM', 'KTP']];

    /** Documents that expire, and so need a valid-until date. */
    public const EXPIRING = ['STNK', 'KIR', 'SIM'];

    public function __construct(private readonly TenantStore $store, private readonly string $vendorId) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private function table(string $type): string
    {
        return $type === 'VEHICLE' ? 'vehicles' : 'drivers';
    }

    private function own(string $type, string $id, bool $lock = false): object
    {
        $query = $this->store->query($this->table($type))->where('id', $id)->where('vendor_id', $this->vendorId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? abort(404);
    }

    /** @return array{vehicles: list<object>, drivers: list<object>} */
    public function overview(): array
    {
        $vehicles = $this->store->query('vehicles')->where('vendor_id', $this->vendorId)->orderBy('plate')->get();
        $drivers = $this->store->query('drivers')->where('vendor_id', $this->vendorId)->orderBy('name')->get();
        $types = $this->store->query('carrier_types')->pluck('name', 'id');
        $documents = $this->store->query('fleet_documents')->whereIn('owner_id', $vehicles->pluck('id')->merge($drivers->pluck('id')))->get(['id', 'owner_type', 'owner_id', 'kind', 'number', 'valid_until', 'mime', 'size_bytes', 'updated_at'])->groupBy('owner_id');
        foreach ($vehicles as $v) {
            $v->carrier_type_label = $v->carrier_type_id ? ($types[$v->carrier_type_id] ?? null) : null;
            $this->annotate($v, 'VEHICLE', $documents[$v->id] ?? collect());
        }
        foreach ($drivers as $d) {
            $this->annotate($d, 'DRIVER', $documents[$d->id] ?? collect());
        }

        return ['vehicles' => $vehicles->all(), 'drivers' => $drivers->all()];
    }

    public function annotate(object $row, string $type, $documents): void
    {
        $row->documents = $documents->values()->all();
        $row->missing_documents = array_values(array_diff(self::REQUIRED[$type], $documents->pluck('kind')->all()));
    }

    // ---------------------------------------------------------------- vehicles

    private function vehicleData(array $input): array
    {
        $d = Validator::make($input, [
            'plate' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9 -]*$/'],
            'carrier_type_id' => ['required', 'uuid', Rule::exists('carrier_types', 'id')->where('tenant_id', $this->store->tenantId)->where('active', true)],
            'capacity_kg' => 'required|numeric|min:1|max:100000',
            'length_mm' => 'required|integer|min:1|max:20000', 'width_mm' => 'required|integer|min:1|max:20000', 'height_mm' => 'required|integer|min:1|max:20000',
            'tracker_id' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
        ], [], ['tracker_id' => 'ID perangkat pelacak', 'plate' => 'Nomor kendaraan', 'carrier_type_id' => 'Tipe kendaraan', 'capacity_kg' => 'Kapasitas', 'length_mm' => 'Panjang', 'width_mm' => 'Lebar', 'height_mm' => 'Tinggi'])->validate();
        $d['plate'] = Str::upper(preg_replace('/\s+/', ' ', trim($d['plate'])));
        $d['capacity_g'] = (int) round($d['capacity_kg'] * 1000);
        unset($d['capacity_kg']);

        return $d;
    }

    private function assertTrackerFree(?string $tracker, ?string $exceptId): void
    {
        if ($tracker !== null && $tracker !== '' && $this->store->query('vehicles')->whereRaw('lower(tracker_id) = ?', [mb_strtolower($tracker)])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            $this->fail('tracker_id', 'ID perangkat pelacak sudah dipakai kendaraan lain.');
        }
    }

    public function registerVehicle(array $input): object
    {
        $d = $this->vehicleData($input);

        return DB::transaction(function () use ($d) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            if ($this->store->query('vehicles')->where('plate', $d['plate'])->exists()) {
                $this->fail('plate', 'Nomor kendaraan sudah terdaftar.');
            }
            $this->assertTrackerFree($d['tracker_id'] ?? null, null);
            $id = (string) Str::uuid();
            $type = $this->store->query('carrier_types')->where('id', $d['carrier_type_id'])->value('name');
            DB::table('vehicles')->insert($d + [
                'id' => $id, 'tenant_id' => $this->store->tenantId, 'type' => $type, 'status' => 'AVAILABLE', 'vendor_id' => $this->vendorId, 'active' => true,
                'verification_status' => 'PENDING', 'registered_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->store->event('vendor_vehicle.registered', $id, ['plate' => $d['plate'], 'vendor_id' => $this->vendorId]);

            return $this->store->find('vehicles', $id);
        }, 3);
    }

    public function updateVehicle(string $id, array $input): object
    {
        $d = $this->vehicleData($input);

        return DB::transaction(function () use ($id, $d) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $vehicle = $this->own('VEHICLE', $id, true);
            $this->assertNotOnTrip('vehicle_id', $vehicle->id, 'Kendaraan sedang dalam perjalanan aktif; datanya tidak dapat diubah.');
            if ($this->store->query('vehicles')->where('plate', $d['plate'])->where('id', '!=', $id)->exists()) {
                $this->fail('plate', 'Nomor kendaraan sudah terdaftar.');
            }
            $type = $this->store->query('carrier_types')->where('id', $d['carrier_type_id'])->value('name');
            // The tracker identifier is not what the tenant verified (documents, type, size): changing it does not ask for a new verification.
            $changed = collect($d)->except('tracker_id')->contains(fn ($v, $k) => (string) $vehicle->{$k} !== (string) $v);
            $this->assertTrackerFree($d['tracker_id'] ?? null, $id);
            $update = $d + ['type' => $type, 'updated_at' => now()];
            if ($changed && $vehicle->verification_status !== 'PENDING') {
                // What the tenant verified is no longer what is registered.
                $update += ['verification_status' => 'PENDING', 'verified_by' => null, 'verified_at' => null, 'verification_note' => null];
            }
            DB::table('vehicles')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update($update);
            $this->store->event('vendor_vehicle.updated', $id, ['plate' => $d['plate'], 'reverification' => $changed && $vehicle->verification_status !== 'PENDING']);
            if (! $changed) {
                VendorMaster::publish($this->store->tenantId, 'VEHICLE', $id); // a changed tracker identifier reaches OptiRadar (only a verified vehicle is published)
            }

            return $this->store->find('vehicles', $id);
        }, 3);
    }

    // ---------------------------------------------------------------- drivers

    private function driverData(array $input): array
    {
        return Validator::make($input, [
            'name' => 'required|string|max:150', 'license_number' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9 .-]*$/'], 'phone' => 'nullable|string|max:40',
        ], [], ['name' => 'Nama', 'license_number' => 'Nomor SIM', 'phone' => 'Telepon'])->validate() + ['phone' => null];
    }

    public function registerDriver(array $input): object
    {
        $d = $this->driverData($input);

        return DB::transaction(function () use ($d) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            if ($this->store->query('drivers')->where('license_number', $d['license_number'])->exists()) {
                $this->fail('license_number', 'Nomor SIM sudah terdaftar.');
            }
            $id = (string) Str::uuid();
            DB::table('drivers')->insert($d + [
                'id' => $id, 'tenant_id' => $this->store->tenantId, 'vendor_id' => $this->vendorId, 'active' => true, 'verification_status' => 'PENDING',
                'registered_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->store->event('vendor_driver.registered', $id, ['vendor_id' => $this->vendorId]);

            return $this->store->find('drivers', $id);
        }, 3);
    }

    public function updateDriver(string $id, array $input): object
    {
        $d = $this->driverData($input);

        return DB::transaction(function () use ($id, $d) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $driver = $this->own('DRIVER', $id, true);
            $this->assertNotOnTrip('driver_id', $driver->id, 'Pengemudi sedang dalam perjalanan aktif; datanya tidak dapat diubah.');
            if ($this->store->query('drivers')->where('license_number', $d['license_number'])->where('id', '!=', $id)->exists()) {
                $this->fail('license_number', 'Nomor SIM sudah terdaftar.');
            }
            $update = $d + ['updated_at' => now()];
            if ($driver->license_number !== $d['license_number'] && $driver->verification_status !== 'PENDING') {
                $update += ['verification_status' => 'PENDING', 'verified_by' => null, 'verified_at' => null, 'verification_note' => null];
            }
            DB::table('drivers')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update($update);
            $this->store->event('vendor_driver.updated', $id);

            return $this->store->find('drivers', $id);
        }, 3);
    }

    // ---------------------------------------------------------------- documents and switching off

    public function uploadDocument(string $type, string $id, array $input, ?UploadedFile $file): object
    {
        $entity = $this->own($type, $id);
        $d = Validator::make($input + ['file' => $file], [
            'kind' => ['required', Rule::in(self::REQUIRED[$type])],
            'number' => 'nullable|string|max:80',
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ], [], ['kind' => 'Jenis dokumen', 'number' => 'Nomor', 'valid_until' => 'Berlaku sampai', 'file' => 'Berkas'])->validate();
        if (in_array($d['kind'], self::EXPIRING, true) && empty($d['valid_until'])) {
            $this->fail('valid_until', 'Masa berlaku dokumen wajib diisi.');
        }
        if (! in_array($d['kind'], self::EXPIRING, true)) {
            $d['valid_until'] = null;
        }

        return DB::transaction(function () use ($type, $entity, $d, $file) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $path = $file->storeAs('fleet-documents/'.$this->store->tenantId, Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()), 'local');
            $existing = $this->store->query('fleet_documents')->where('owner_type', $type)->where('owner_id', $entity->id)->where('kind', $d['kind'])->first();
            $row = ['number' => $d['number'] ?? null, 'valid_until' => $d['valid_until'], 'path' => $path, 'mime' => (string) $file->getMimeType(), 'size_bytes' => (int) $file->getSize(), 'uploaded_by' => $this->store->actorId, 'updated_at' => now()];
            if ($existing) {
                DB::table('fleet_documents')->where('id', $existing->id)->update($row);
                Storage::disk('local')->delete($existing->path);
                $docId = $existing->id;
            } else {
                $docId = (string) Str::uuid();
                DB::table('fleet_documents')->insert($row + ['id' => $docId, 'tenant_id' => $this->store->tenantId, 'owner_type' => $type, 'owner_id' => $entity->id, 'kind' => $d['kind'], 'created_at' => now()]);
            }
            $update = ['updated_at' => now()];
            if ($d['kind'] === 'SIM') {
                $update['license_valid_until'] = $d['valid_until'];
            }
            if ($entity->verification_status !== 'PENDING') {
                $update += ['verification_status' => 'PENDING', 'verified_by' => null, 'verified_at' => null, 'verification_note' => null];
            }
            DB::table($this->table($type))->where('tenant_id', $this->store->tenantId)->where('id', $entity->id)->update($update);
            $this->store->event('vendor_fleet.document_uploaded', $entity->id, ['owner_type' => $type, 'kind' => $d['kind'], 'replaced' => (bool) $existing]);

            return $this->store->find('fleet_documents', $docId);
        }, 3);
    }

    public function setActive(string $type, string $id, bool $active): object
    {
        return DB::transaction(function () use ($type, $id, $active) {
            $entity = $this->own($type, $id, true);
            if (! $active) {
                $this->assertNotOnTrip($type === 'VEHICLE' ? 'vehicle_id' : 'driver_id', $id, 'Masih ada perjalanan aktif; nonaktifkan setelah selesai.');
            }
            DB::table($this->table($type))->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['active' => $active, 'updated_at' => now()]);
            $this->store->event($active ? 'vendor_fleet.activated' : 'vendor_fleet.deactivated', $id, ['owner_type' => $type]);
            VendorMaster::publish($this->store->tenantId, $type, $id); // tells OptiRadar the vehicle or driver is (in)active

            return $this->store->find($this->table($type), $id);
        }, 3);
    }

    private function assertNotOnTrip(string $column, string $id, string $message): void
    {
        abort_if($this->store->query('spm_trips')->where('active', true)->where($column, $id)->exists(), 409, $message);
    }
}

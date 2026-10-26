<?php

namespace App\Domain\Planning;

use App\Domain\Shared\TenantStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Vehicle reservations: a booking of a vehicle (and optionally a driver) for a period, made ahead of the trip. Two bookings of
 * the same vehicle or driver never overlap; a trip planned in a booked period needs that booking (it is converted into the trip), so
 * a booking really holds the vehicle. A booking that was not used simply ends: its status is shown as expired once the period
 * has passed.
 */
final class ReservationService
{
    public function __construct(private readonly TenantStore $store) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** @return list<object> */
    public function list(?string $from, ?string $to, ?string $status): array
    {
        $query = $this->store->query('vehicle_reservations');
        if ($from) {
            $query->where('ends_at', '>=', $from);
        }
        if ($to) {
            $query->where('starts_at', '<=', $to);
        }
        if (in_array($status, ['BOOKED', 'CONVERTED', 'CANCELLED'], true)) {
            $query->where('status', $status);
        }
        $rows = $query->orderBy('starts_at')->limit(500)->get();
        $plates = $this->store->query('vehicles')->whereIn('id', $rows->pluck('vehicle_id'))->pluck('plate', 'id');
        $drivers = $this->store->query('drivers')->whereIn('id', $rows->pluck('driver_id')->filter())->pluck('name', 'id');
        $spms = $this->store->query('transport_orders')->whereIn('id', $rows->pluck('transport_order_id')->filter())->pluck('number', 'id');
        foreach ($rows as $row) {
            $row->vehicle_label = $plates[$row->vehicle_id] ?? null;
            $row->driver_label = $row->driver_id ? ($drivers[$row->driver_id] ?? null) : null;
            $row->spm_number = $row->transport_order_id ? ($spms[$row->transport_order_id] ?? null) : null;
            $row->effective_status = $row->status === 'BOOKED' && CarbonImmutable::parse($row->ends_at)->isPast() ? 'EXPIRED' : $row->status;
        }

        return $rows->all();
    }

    /** The booking of another party that overlaps the period for this vehicle or driver, if any. */
    public function clash(string $vehicleId, ?string $driverId, CarbonImmutable $start, CarbonImmutable $end, ?string $except = null): ?object
    {
        $query = $this->store->query('vehicle_reservations')->where('status', 'BOOKED')->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->where(fn ($q) => $q->where('vehicle_id', $vehicleId)->when($driverId, fn ($q) => $q->orWhere('driver_id', $driverId)));
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }

        return $query->first();
    }

    public function create(array $input): object
    {
        $d = Validator::make($input, [
            'vehicle_id' => ['required', 'uuid', Rule::exists('vehicles', 'id')->where('tenant_id', $this->store->tenantId)],
            'driver_id' => ['nullable', 'uuid', Rule::exists('drivers', 'id')->where('tenant_id', $this->store->tenantId)],
            'transport_order_id' => ['nullable', 'uuid', Rule::exists('transport_orders', 'id')->where('tenant_id', $this->store->tenantId)],
            'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'purpose' => 'nullable|string|max:300',
        ], [], ['starts_at' => 'Mulai', 'ends_at' => 'Selesai', 'vehicle_id' => 'Kendaraan'])->validate();

        return DB::transaction(function () use ($d) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $start = CarbonImmutable::parse($d['starts_at']);
            $end = CarbonImmutable::parse($d['ends_at']);
            if ($end->lessThan(now())) {
                $this->fail('ends_at', 'Periode booking sudah lewat.');
            }
            if ($other = $this->clash($d['vehicle_id'], $d['driver_id'] ?? null, $start, $end)) {
                $this->fail('vehicle_id', 'Kendaraan atau pengemudi sudah dibooking pada periode ini ('.CarbonImmutable::parse($other->starts_at)->format('d/m H:i').' – '.CarbonImmutable::parse($other->ends_at)->format('d/m H:i').').');
            }
            $vehicle = $this->store->find('vehicles', $d['vehicle_id']);
            if ($vehicle->status !== 'AVAILABLE') {
                $this->fail('vehicle_id', 'Kendaraan tidak tersedia untuk dibooking (status servis/operasional).');
            }
            if ($this->store->query('spm_trips')->where('active', true)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->where(fn ($q) => $q->where('vehicle_id', $d['vehicle_id'])->when($d['driver_id'] ?? null, fn ($q) => $q->orWhere('driver_id', $d['driver_id'])))->exists()) {
                $this->fail('vehicle_id', 'Kendaraan atau pengemudi sudah dialokasikan ke perjalanan pada periode ini.');
            }
            $id = (string) Str::uuid();
            DB::table('vehicle_reservations')->insert($d + ['id' => $id, 'tenant_id' => $this->store->tenantId, 'driver_id' => $d['driver_id'] ?? null, 'transport_order_id' => $d['transport_order_id'] ?? null, 'purpose' => $d['purpose'] ?? null, 'status' => 'BOOKED', 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event('reservation.created', $id, ['vehicle' => $vehicle->plate, 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String()]);

            return $this->store->find('vehicle_reservations', $id);
        }, 3);
    }

    public function cancel(string $id, string $reason): object
    {
        if (mb_strlen(trim($reason)) < 5) {
            $this->fail('reason', 'Alasan wajib diisi (minimal 5 karakter).');
        }

        return DB::transaction(function () use ($id, $reason) {
            $row = $this->store->find('vehicle_reservations', $id, true);
            abort_unless($row->status === 'BOOKED', 409, 'Hanya booking yang masih aktif yang dapat dibatalkan.');
            DB::table('vehicle_reservations')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'CANCELLED', 'cancel_reason' => $reason, 'updated_at' => now()]);
            $this->store->event('reservation.cancelled', $id, ['reason' => $reason]);

            return $this->store->find('vehicle_reservations', $id);
        }, 3);
    }

    /** Called inside the planning transaction: the booking is used up by the trip. */
    public function convert(string $id, string $tripId, string $vehicleId, ?string $driverId, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $row = $this->store->query('vehicle_reservations')->where('id', $id)->lockForUpdate()->first() ?? $this->fail('reservation_id', 'Booking tidak ditemukan.');
        if ($row->status !== 'BOOKED') {
            $this->fail('reservation_id', 'Booking sudah dipakai atau dibatalkan.');
        }
        if ($row->vehicle_id !== $vehicleId || ($row->driver_id !== null && $row->driver_id !== $driverId)) {
            $this->fail('reservation_id', 'Booking ini untuk kendaraan/pengemudi lain.');
        }
        DB::table('vehicle_reservations')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'CONVERTED', 'trip_id' => $tripId, 'updated_at' => now()]);
        $this->store->event('reservation.converted', $id, ['trip_id' => $tripId]);
    }
}

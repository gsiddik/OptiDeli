<?php

namespace App\Domain\Planning;

use App\Domain\Commercial\Margin;
use App\Domain\Dispatch\FleetAvailability;
use App\Domain\Shared\TenantStore;
use App\Domain\Spm\DocumentNumbers;
use App\Domain\Spm\SpmService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Plans the AWBs of one SPM onto a vehicle and driver: a trip belongs to one SPM and carries some of its AWBs, so an SPM with
 * several AWBs can have several trips. The cargo must fit the vehicle, the vehicle and driver must be free (also against the
 * order-based trips), and the margin of the SPM's tariff-priced charges is held against the tenant's policy: below the threshold a
 * trip needs a reason code and the approval of someone else. Recording stop times moves the AWBs and the SPM along (arrive at the
 * pickup: loading; leave it: in transit; leave a drop: its AWBs delivered, the SPM discharged when the last AWB is) and prices
 * overnight and detention.
 */
final class TripPlanner
{
    private const CARRYING = ['PLANNED', 'LOADING', 'IN_TRANSIT'];

    public function __construct(private readonly TenantStore $store, private readonly FleetAvailability $fleet) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    // ---------------------------------------------------------------- reading

    /** @return array{data: list<object>, current_page: int, last_page: int, total: int} */
    public function list(?string $status, ?string $search, ?string $spmId, int $page, int $perPage = 50): array
    {
        $query = $this->store->query('spm_trips');
        if ($status !== null && in_array($status, ['PENDING_APPROVAL', 'PLANNED', 'IN_TRANSIT', 'COMPLETED', 'CANCELLED'], true)) {
            $query->where('status', $status);
        }
        if ($spmId !== null && Str::isUuid($spmId)) {
            $query->where('transport_order_id', $spmId);
        }
        if ($search !== null && $search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($search)).'%';
            $query->whereRaw('lower(number) like ?', [$like]);
        }
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('starts_at')->forPage($page, $perPage)->get();
        $plates = $this->store->query('vehicles')->whereIn('id', $rows->pluck('vehicle_id'))->pluck('plate', 'id');
        $drivers = $this->store->query('drivers')->whereIn('id', $rows->pluck('driver_id'))->pluck('name', 'id');
        $spms = $this->store->query('transport_orders')->whereIn('id', $rows->pluck('transport_order_id'))->pluck('number', 'id');
        $counts = $this->store->query('awbs')->whereIn('trip_id', $rows->pluck('id'))->selectRaw('trip_id, count(*) as n')->groupBy('trip_id')->pluck('n', 'trip_id');
        foreach ($rows as $row) {
            $row->vehicle_label = $plates[$row->vehicle_id] ?? null;
            $row->driver_label = $drivers[$row->driver_id] ?? null;
            $row->spm_number = $spms[$row->transport_order_id] ?? null;
            $row->awb_count = (int) ($counts[$row->id] ?? 0);
        }

        return ['data' => $rows->all(), 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total];
    }

    public function show(string $id): array
    {
        $trip = $this->store->find('spm_trips', $id);
        $trip->vehicle_label = optional($this->store->query('vehicles')->where('id', $trip->vehicle_id)->first(), fn ($v) => $v->plate.' · '.$v->type);
        $trip->driver_label = optional($this->store->query('drivers')->where('id', $trip->driver_id)->first(), fn ($d) => $d->name);
        $trip->vendor_label = $trip->vendor_id ? optional($this->store->query('vendors')->where('id', $trip->vendor_id)->first(), fn ($v) => $v->code.' · '.$v->name) : null;
        $spm = $this->store->query('transport_orders')->where('id', $trip->transport_order_id)->first(['id', 'number', 'status', 'order_id', 'origin_text', 'destination_text']);
        $awbs = $this->store->query('awbs')->where('trip_id', $id)->orderBy('sequence')->get();
        $weights = $this->store->query('shipment_lines')->whereIn('awb_id', $awbs->pluck('id'))->selectRaw('awb_id, coalesce(sum(weight_kg),0) as kg')->groupBy('awb_id')->pluck('kg', 'awb_id');
        foreach ($awbs as $awb) {
            $awb->weight_kg = (float) ($weights[$awb->id] ?? 0);
        }
        $stops = $this->store->query('spm_trip_stops')->where('trip_id', $id)->orderBy('sequence')->get();
        $places = $this->store->query('locations')->whereIn('id', $stops->pluck('location_id'))->pluck('name', 'id');
        foreach ($stops as $stop) {
            $stop->location_label = $places[$stop->location_id] ?? null;
            $stop->awb_numbers = $stop->type === 'DROP' ? $awbs->where('destination_location_id', $stop->location_id)->pluck('number')->values()->all() : [];
        }

        return ['trip' => $trip, 'spm' => $spm, 'awbs' => $awbs->all(), 'stops' => $stops->all()];
    }

    // ---------------------------------------------------------------- planning

    /** @return array{trip: array<string, mixed>, warnings: list<string>} */
    public function create(array $input): array
    {
        $d = Validator::make($input, [
            'spm_id' => ['required', 'uuid', Rule::exists('transport_orders', 'id')->where('tenant_id', $this->store->tenantId)],
            'awb_ids' => 'required|array|min:1|max:100', 'awb_ids.*' => 'required|uuid|distinct',
            'vehicle_id' => ['required', 'uuid', Rule::exists('vehicles', 'id')->where('tenant_id', $this->store->tenantId)],
            'driver_id' => ['required', 'uuid', Rule::exists('drivers', 'id')->where('tenant_id', $this->store->tenantId)],
            'vendor_id' => ['nullable', 'uuid', Rule::exists('vendors', 'id')->where('tenant_id', $this->store->tenantId)],
            'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'reason_code' => 'nullable|string|max:60', 'notes' => 'nullable|string|max:2000',
        ], [], ['starts_at' => 'Mulai', 'ends_at' => 'Selesai', 'awb_ids' => 'AWB'])->validate();

        return DB::transaction(function () use ($d) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $spm = $this->store->find('transport_orders', $d['spm_id'], true);
            abort_unless(in_array($spm->status, self::CARRYING, true), 409, 'Perjalanan hanya dapat direncanakan untuk SPM yang sudah direncanakan dan belum selesai bongkar.');
            if (! $spm->origin_location_id) {
                $this->fail('spm_id', 'SPM belum memiliki lokasi asal dari master data.');
            }
            $awbs = $this->store->query('awbs')->where('transport_order_id', $spm->id)->whereIn('id', $d['awb_ids'])->lockForUpdate()->get();
            if ($awbs->count() !== count($d['awb_ids'])) {
                $this->fail('awb_ids', 'AWB harus milik SPM ini.');
            }
            foreach ($awbs as $awb) {
                if ($awb->status !== 'PENDING') {
                    $this->fail('awb_ids', "AWB {$awb->number} sudah berada di perjalanan atau dibatalkan.");
                }
            }
            $vehicle = $this->store->find('vehicles', $d['vehicle_id'], true);
            $driver = $this->store->find('drivers', $d['driver_id'], true);
            $start = CarbonImmutable::parse($d['starts_at']);
            $end = CarbonImmutable::parse($d['ends_at']);
            abort_unless($start->greaterThanOrEqualTo(now()->subMinute()), 422, 'Jadwal mulai sudah lewat.');
            $this->fleet->assertReady($vehicle, $driver, $end, $start);
            if ($this->store->query('trips')->where('active', true)->where(fn ($q) => $q->where('vehicle_id', $vehicle->id)->orWhere('driver_id', $driver->id))->exists()
                || $this->store->query('spm_trips')->where('active', true)->where(fn ($q) => $q->where('vehicle_id', $vehicle->id)->orWhere('driver_id', $driver->id))->exists()) {
                abort(409, 'Kendaraan atau pengemudi telah dialokasikan.');
            }
            $lines = $this->store->query('shipment_lines')->whereIn('awb_id', $d['awb_ids'])->get();
            abort_if((float) $lines->sum('weight_kg') * 1000 > $vehicle->capacity_g, 422, 'Berat muatan melebihi kapasitas kendaraan.');
            abort_if((float) $lines->sum('volume_cbm') * 1e9 > $vehicle->length_mm * $vehicle->width_mm * $vehicle->height_mm, 422, 'Volume muatan melebihi box kendaraan.');

            [$sell, $cost] = $this->share($spm, $d['awb_ids']);
            [$level, $threshold, $warnings] = $this->gate($spm);
            if ($level > 0 && empty($d['reason_code'])) {
                $this->fail('reason_code', 'Reason code wajib untuk margin di bawah threshold.');
            }

            $id = (string) Str::uuid();
            $period = now()->format('Ym');
            $number = sprintf('PJ-%s-%05d', $period, DocumentNumbers::next($this->store->tenantId, 'TRIP', $period));
            DB::table('spm_trips')->insert([
                'id' => $id, 'tenant_id' => $this->store->tenantId, 'number' => $number, 'transport_order_id' => $spm->id, 'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id,
                'vendor_id' => $d['vendor_id'] ?? $spm->vendor_id, 'status' => $level > 0 ? 'PENDING_APPROVAL' : 'PLANNED', 'active' => true, 'starts_at' => $start, 'ends_at' => $end,
                'sell_minor' => $sell, 'cost_minor' => $cost, 'threshold_bps' => $threshold, 'required_level' => $level, 'reason_code' => $d['reason_code'] ?? null, 'notes' => $d['notes'] ?? null,
                'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('awbs')->where('tenant_id', $this->store->tenantId)->whereIn('id', $d['awb_ids'])->update(['trip_id' => $id, 'status' => 'ON_TRIP', 'updated_at' => now()]);
            $this->store->query('orders')->where('id', $spm->order_id)->where('status', 'CONFIRMED')->update(['status' => 'DISPATCHED', 'updated_at' => now()]);
            $this->buildStops($id, $spm, $awbs, $start, $end);
            $this->store->event('spm_trip.planned', $id, ['number' => $number, 'spm' => $spm->number, 'awbs' => $awbs->pluck('number')->all(), 'required_level' => $level, 'sell_minor' => $sell, 'cost_minor' => $cost]);

            return ['trip' => $this->show($id), 'warnings' => $warnings];
        }, 3);
    }

    /** The part of the SPM's sell and cost that falls on these AWBs, by weight (by count when nothing is weighed). @return array{0: int, 1: int} */
    private function share(object $spm, array $awbIds): array
    {
        $charges = $this->store->query('spm_charges')->where('transport_order_id', $spm->id)->where('status', '!=', 'REJECTED')->get();
        $all = $this->store->query('awbs')->where('transport_order_id', $spm->id)->where('status', '!=', 'CANCELLED')->pluck('id');
        $weight = fn ($ids) => (float) $this->store->query('shipment_lines')->whereIn('awb_id', $ids)->sum('weight_kg');
        $fraction = $weight($all) > 0 ? $weight($awbIds) / $weight($all) : count($awbIds) / max(1, $all->count());

        return [(int) round($charges->where('side', 'SELL')->sum('amount_minor') * $fraction), (int) round($charges->where('side', 'BUY')->sum('amount_minor') * $fraction)];
    }

    /** @return array{0: int, 1: ?int, 2: list<string>} approval level needed, threshold, warnings */
    private function gate(object $spm): array
    {
        $charges = $this->store->query('spm_charges')->where('transport_order_id', $spm->id)->where('status', '!=', 'REJECTED')->get();
        $sell = (int) $charges->where('side', 'SELL')->sum('amount_minor');
        $cost = (int) $charges->where('side', 'BUY')->sum('amount_minor');
        if ($sell <= 0) {
            return [0, null, ['Belum ada harga jual pada SPM ini, margin tidak dapat dinilai. Pastikan tarif jual sudah disetujui.']];
        }
        $warnings = $cost <= 0 ? ['Belum ada harga beli pada SPM ini, margin dihitung tanpa biaya.'] : [];
        $tenant = DB::table('tenants')->where('id', $this->store->tenantId)->firstOrFail();
        $stage = $this->store->query('orders')->where('id', $spm->order_id)->value('stage');
        $stages = json_decode($tenant->stage_margin_bps ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        $threshold = (int) ($stages[$stage] ?? $tenant->minimum_margin_bps);

        return [Margin::requiredLevel($sell, max(0, $cost), $threshold), $threshold, $warnings];
    }

    /** The SPM's origin first, then one drop per destination of the AWBs. */
    private function buildStops(string $tripId, object $spm, $awbs, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $sequence = 0;
        DB::table('spm_trip_stops')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'trip_id' => $tripId, 'sequence' => ++$sequence, 'location_id' => $spm->origin_location_id, 'type' => 'PICKUP', 'planned_from' => $start, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($awbs->pluck('destination_location_id')->unique()->values() as $location) {
            DB::table('spm_trip_stops')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'trip_id' => $tripId, 'sequence' => ++$sequence, 'location_id' => $location, 'type' => 'DROP', 'planned_to' => $end, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Puts the drops in a new order; the pickup stays first. */
    public function reorder(string $id, array $stopIds): array
    {
        Validator::make(['stop_ids' => $stopIds], ['stop_ids' => 'required|array|min:2', 'stop_ids.*' => 'required|uuid|distinct'])->validate();

        return DB::transaction(function () use ($id, $stopIds) {
            $trip = $this->store->find('spm_trips', $id, true);
            abort_unless(in_array($trip->status, ['PENDING_APPROVAL', 'PLANNED'], true), 409, 'Urutan stop dikunci setelah perjalanan berangkat.');
            $stops = $this->store->query('spm_trip_stops')->where('trip_id', $id)->get()->keyBy('id');
            if (count($stopIds) !== $stops->count() || array_diff($stopIds, $stops->keys()->all()) !== []) {
                $this->fail('stop_ids', 'Daftar stop harus memuat semua stop perjalanan ini.');
            }
            if ($stops[$stopIds[0]]->type !== 'PICKUP') {
                $this->fail('stop_ids', 'Stop muat harus tetap pertama.');
            }
            foreach ($stopIds as $i => $stopId) {
                DB::table('spm_trip_stops')->where('tenant_id', $this->store->tenantId)->where('id', $stopId)->update(['sequence' => 1000 + $i]);
            }
            foreach ($stopIds as $i => $stopId) {
                DB::table('spm_trip_stops')->where('tenant_id', $this->store->tenantId)->where('id', $stopId)->update(['sequence' => $i + 1, 'updated_at' => now()]);
            }
            $this->store->event('spm_trip.reordered', $id, ['number' => $trip->number]);

            return $this->show($id);
        }, 3);
    }

    // ---------------------------------------------------------------- lifecycle

    public function approve(string $id, int $approvalLevel): array
    {
        return DB::transaction(function () use ($id, $approvalLevel) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $trip = $this->store->find('spm_trips', $id, true);
            abort_unless($trip->status === 'PENDING_APPROVAL', 409, 'Perjalanan tidak menunggu persetujuan.');
            abort_if($trip->created_by === $this->store->actorId, 403, 'Perencana tidak boleh menyetujui perjalanannya sendiri.');
            abort_unless($approvalLevel >= $trip->required_level, 403, 'Tingkat otorisasi tidak cukup.');
            DB::table('spm_trips')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'PLANNED', 'approved_by' => $this->store->actorId, 'approved_at' => now(), 'updated_at' => now()]);
            $this->store->event('spm_trip.margin_approved', $id, ['number' => $trip->number, 'required_level' => $trip->required_level, 'reason_code' => $trip->reason_code]);

            return $this->show($id);
        }, 3);
    }

    public function start(string $id): array
    {
        return DB::transaction(function () use ($id) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $trip = $this->store->find('spm_trips', $id, true);
            abort_unless($trip->status === 'PLANNED', 409, $trip->status === 'PENDING_APPROVAL' ? 'Perjalanan menunggu persetujuan margin.' : 'Perjalanan tidak dalam status siap berangkat.');
            $spm = $this->store->find('transport_orders', $trip->transport_order_id);
            abort_unless(in_array($spm->status, self::CARRYING, true), 409, "SPM {$spm->number} tidak lagi siap berangkat.");
            $this->fleet->assertReady($this->store->find('vehicles', $trip->vehicle_id), $this->store->find('drivers', $trip->driver_id), CarbonImmutable::parse($trip->ends_at)->max(now()), CarbonImmutable::now());
            DB::table('spm_trips')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'IN_TRANSIT', 'started_at' => now(), 'updated_at' => now()]);
            $this->store->event('spm_trip.started', $id, ['number' => $trip->number]);

            return $this->show($id);
        }, 3);
    }

    public function complete(string $id): array
    {
        return DB::transaction(function () use ($id) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $trip = $this->store->find('spm_trips', $id, true);
            abort_unless($trip->status === 'IN_TRANSIT', 409, 'Perjalanan belum berangkat.');
            abort_if($this->store->query('spm_trip_stops')->where('trip_id', $id)->whereNull('departed_at')->exists(), 422, 'Semua stop harus sudah dicatat tiba dan berangkat.');
            DB::table('spm_trips')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'COMPLETED', 'active' => false, 'completed_at' => now(), 'updated_at' => now()]);
            $this->store->event('spm_trip.completed', $id, ['number' => $trip->number]);

            return $this->show($id);
        }, 3);
    }

    public function cancel(string $id, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 5) {
            $this->fail('reason', 'Alasan wajib diisi (minimal 5 karakter).');
        }

        return DB::transaction(function () use ($id, $reason) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $trip = $this->store->find('spm_trips', $id, true);
            abort_unless(in_array($trip->status, ['PENDING_APPROVAL', 'PLANNED'], true), 409, 'Hanya perjalanan yang belum berangkat yang dapat dibatalkan.');
            DB::table('spm_trips')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'CANCELLED', 'active' => false, 'cancellation_reason' => $reason, 'updated_at' => now()]);
            DB::table('awbs')->where('tenant_id', $this->store->tenantId)->where('trip_id', $id)->update(['trip_id' => null, 'status' => 'PENDING', 'updated_at' => now()]);
            $spm = $this->store->find('transport_orders', $trip->transport_order_id);
            if (! $this->store->query('spm_trips')->where('active', true)->whereIn('transport_order_id', $this->store->query('transport_orders')->where('order_id', $spm->order_id)->pluck('id'))->exists()
                && ! $this->store->query('trips')->where('order_id', $spm->order_id)->where('active', true)->exists()) {
                $this->store->query('orders')->where('id', $spm->order_id)->where('status', 'DISPATCHED')->update(['status' => 'CONFIRMED', 'updated_at' => now()]);
            }
            $this->store->event('spm_trip.cancelled', $id, ['number' => $trip->number, 'reason' => $reason]);

            return $this->show($id);
        }, 3);
    }

    // ---------------------------------------------------------------- stops

    /**
     * Records when the truck arrived at or left a stop (by hand for now). Moves the AWBs and the SPM along and prices the waiting.
     *
     * @return array{trip: array<string, mixed>, warnings: list<string>}
     */
    public function recordStop(string $tripId, string $stopId, string $event, ?string $at): array
    {
        $when = Validator::make(['at' => $at], ['at' => 'nullable|date'], [], ['at' => 'Waktu'])->validate()['at'] ?? null;

        return DB::transaction(function () use ($tripId, $stopId, $event, $when) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $trip = $this->store->find('spm_trips', $tripId, true);
            abort_unless($trip->status === 'IN_TRANSIT', 409, 'Jam stop hanya dapat dicatat setelah perjalanan berangkat.');
            $stop = $this->store->query('spm_trip_stops')->where('trip_id', $tripId)->where('id', $stopId)->lockForUpdate()->firstOrFail();
            $time = $when ? CarbonImmutable::parse($when) : CarbonImmutable::now();
            if ($time->greaterThan(now()->addMinutes(5))) {
                $this->fail('at', 'Waktu tidak boleh di masa depan.');
            }
            $previous = $this->store->query('spm_trip_stops')->where('trip_id', $tripId)->where('sequence', '<', $stop->sequence)->orderByDesc('sequence')->first();
            if ($event === 'arrive') {
                abort_if($stop->arrived_at !== null, 409, 'Kedatangan stop ini sudah dicatat.');
                abort_if($previous && $previous->departed_at === null, 409, 'Stop sebelumnya belum dicatat berangkat.');
                if ($previous && $time->lessThan(CarbonImmutable::parse($previous->departed_at))) {
                    $this->fail('at', 'Waktu tiba tidak boleh sebelum keberangkatan dari stop sebelumnya.');
                }
                DB::table('spm_trip_stops')->where('tenant_id', $this->store->tenantId)->where('id', $stopId)->update(['arrived_at' => $time, 'time_source' => 'MANUAL', 'updated_at' => now()]);
            } else {
                abort_if($stop->arrived_at === null, 409, 'Catat kedatangan sebelum keberangkatan.');
                abort_if($stop->departed_at !== null, 409, 'Keberangkatan stop ini sudah dicatat.');
                if ($time->lessThan(CarbonImmutable::parse($stop->arrived_at))) {
                    $this->fail('at', 'Waktu berangkat tidak boleh sebelum waktu tiba.');
                }
                DB::table('spm_trip_stops')->where('tenant_id', $this->store->tenantId)->where('id', $stopId)->update(['departed_at' => $time, 'time_source' => 'MANUAL', 'updated_at' => now()]);
            }
            $stop = $this->store->find('spm_trip_stops', $stopId);
            $this->advanceSpm($trip, $stop, $event, $time);
            $warnings = $event === 'depart' ? (new StopCharges($this->store))->apply($trip, $stop) : [];
            $this->store->event('spm_trip.stop_'.$event, $stopId, ['trip' => $trip->number, 'sequence' => $stop->sequence, 'at' => $time->toIso8601String()]);

            return ['trip' => $this->show($tripId), 'warnings' => $warnings];
        }, 3);
    }

    private function advanceSpm(object $trip, object $stop, string $event, CarbonImmutable $time): void
    {
        $service = new SpmService($this->store);
        $spm = $this->store->find('transport_orders', $trip->transport_order_id);
        if ($stop->type === 'PICKUP') {
            if ($event === 'arrive' && $spm->status === 'PLANNED') {
                $service->transition($spm->id, 'LOADING', null);
            }
            if ($event === 'depart' && $spm->status === 'LOADING') {
                $service->transition($spm->id, 'IN_TRANSIT', null);
            }

            return;
        }
        if ($event !== 'depart') {
            return;
        }
        $this->store->query('awbs')->where('trip_id', $trip->id)->where('destination_location_id', $stop->location_id)->where('status', 'ON_TRIP')->update(['status' => 'DELIVERED', 'delivered_at' => $time, 'updated_at' => now()]);
        $spm = $this->store->find('transport_orders', $spm->id);
        if ($spm->status === 'IN_TRANSIT' && ! $this->store->query('awbs')->where('transport_order_id', $spm->id)->whereIn('status', ['PENDING', 'ON_TRIP'])->exists()) {
            $service->transition($spm->id, 'DISCHARGED', null);
            $orderId = $spm->order_id;
            if (! $this->store->query('transport_orders')->where('order_id', $orderId)->whereNotIn('status', ['DISCHARGED', 'DOC_VERIFIED', 'CLOSED', 'CANCELLED'])->exists()) {
                $this->store->query('orders')->where('id', $orderId)->whereIn('status', ['DISPATCHED', 'IN_TRANSIT'])->update(['status' => 'DELIVERED', 'updated_at' => now()]);
            }
        }
    }
}

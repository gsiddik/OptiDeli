<?php

namespace App\Domain\Planning;

use App\Domain\Shared\TenantStore;
use Carbon\CarbonImmutable;

/**
 * Read models for the planner: the schedule (what each vehicle is doing in a period: trips, order-based dispatches and bookings)
 * and the monitoring board (every trip that is not finished, how far it is, and what is wrong with it). Nothing here writes.
 */
final class ScheduleService
{
    /** A vehicle that has waited at a stop longer than this is flagged. */
    public const WAITING_HOURS = 4;

    public function __construct(private readonly TenantStore $store) {}

    /** @return array{from: string, to: string, vehicles: list<object>, items: list<array<string, mixed>>} */
    public function schedule(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $vehicles = $this->store->query('vehicles')->orderBy('plate')->get(['id', 'plate', 'type', 'status']);
        $items = [];
        $drivers = $this->store->query('drivers')->pluck('name', 'id');

        $trips = $this->store->query('spm_trips')->where('status', '!=', 'CANCELLED')->where('starts_at', '<', $to)->where('ends_at', '>', $from)->get();
        $spms = $this->store->query('transport_orders')->whereIn('id', $trips->pluck('transport_order_id'))->pluck('number', 'id');
        foreach ($trips as $t) {
            $items[] = ['kind' => 'TRIP', 'id' => $t->id, 'vehicle_id' => $t->vehicle_id, 'label' => $t->number, 'detail' => ($spms[$t->transport_order_id] ?? '').' · '.($drivers[$t->driver_id] ?? ''), 'status' => $t->status, 'starts_at' => $t->starts_at, 'ends_at' => $t->ends_at];
        }

        $legacy = $this->store->query('trips')->where('status', '!=', 'CANCELLED')->get();
        $plans = $this->store->query('dispatch_plans')->whereIn('id', $legacy->pluck('plan_id'))->get()->keyBy('id');
        $orders = $this->store->query('orders')->whereIn('id', $legacy->pluck('order_id'))->pluck('number', 'id');
        foreach ($legacy as $t) {
            $plan = $plans[$t->plan_id] ?? null;
            if ($plan === null || CarbonImmutable::parse($plan->starts_at)->gte($to) || CarbonImmutable::parse($plan->ends_at)->lte($from)) {
                continue;
            }
            $items[] = ['kind' => 'DISPATCH', 'id' => $t->id, 'vehicle_id' => $t->vehicle_id, 'label' => $orders[$t->order_id] ?? 'Trip order', 'detail' => $drivers[$t->driver_id] ?? '', 'status' => $t->status, 'starts_at' => $plan->starts_at, 'ends_at' => $plan->ends_at];
        }

        $bookings = $this->store->query('vehicle_reservations')->where('status', 'BOOKED')->where('starts_at', '<', $to)->where('ends_at', '>', $from)->get();
        foreach ($bookings as $b) {
            $items[] = ['kind' => 'BOOKING', 'id' => $b->id, 'vehicle_id' => $b->vehicle_id, 'label' => 'Booking', 'detail' => (string) $b->purpose, 'status' => 'BOOKED', 'starts_at' => $b->starts_at, 'ends_at' => $b->ends_at];
        }

        return ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'vehicles' => $vehicles->all(), 'items' => $items];
    }

    /** @return array{counts: array<string, int>, trips: list<array<string, mixed>>, unplanned_awbs: int} */
    public function monitoring(): array
    {
        $now = CarbonImmutable::now();
        $trips = $this->store->query('spm_trips')->whereIn('status', ['PENDING_APPROVAL', 'PLANNED', 'IN_TRANSIT'])->orderBy('starts_at')->get();
        $ids = $trips->pluck('id');
        $stops = $this->store->query('spm_trip_stops')->whereIn('trip_id', $ids)->orderBy('sequence')->get()->groupBy('trip_id');
        $places = $this->store->query('locations')->pluck('name', 'id');
        $awbs = $this->store->query('awbs')->whereIn('trip_id', $ids)->get()->groupBy('trip_id');
        $plates = $this->store->query('vehicles')->pluck('plate', 'id');
        $drivers = $this->store->query('drivers')->pluck('name', 'id');
        $spms = $this->store->query('transport_orders')->whereIn('id', $trips->pluck('transport_order_id'))->pluck('number', 'id');
        $lastEvent = $this->store->query('stop_events')->whereIn('trip_id', $ids)->selectRaw('trip_id, max(occurred_at) as at')->groupBy('trip_id')->pluck('at', 'trip_id');

        $rows = [];
        $counts = ['PENDING_APPROVAL' => 0, 'PLANNED' => 0, 'IN_TRANSIT' => 0, 'LATE' => 0];
        foreach ($trips as $t) {
            $own = $stops[$t->id] ?? collect();
            $current = $own->first(fn ($s) => $s->departed_at === null);
            $flags = [];
            if ($t->status === 'PENDING_APPROVAL') {
                $flags[] = 'HELD';
            }
            if ($t->status === 'PLANNED' && CarbonImmutable::parse($t->starts_at)->addMinutes(30)->lt($now)) {
                $flags[] = 'NOT_STARTED';
            }
            if ($t->status === 'IN_TRANSIT' && CarbonImmutable::parse($t->ends_at)->lt($now)) {
                $flags[] = 'LATE';
            }
            if ($current && $current->arrived_at && CarbonImmutable::parse($current->arrived_at)->addHours(self::WAITING_HOURS)->lt($now)) {
                $flags[] = 'WAITING';
            }
            $own_awbs = $awbs[$t->id] ?? collect();
            $counts[$t->status]++;
            if (array_intersect($flags, ['LATE', 'NOT_STARTED'])) {
                $counts['LATE']++;
            }
            $rows[] = [
                'id' => $t->id, 'number' => $t->number, 'spm_id' => $t->transport_order_id, 'spm_number' => $spms[$t->transport_order_id] ?? null, 'status' => $t->status,
                'vehicle' => $plates[$t->vehicle_id] ?? null, 'driver' => $drivers[$t->driver_id] ?? null, 'starts_at' => $t->starts_at, 'ends_at' => $t->ends_at,
                'stops_done' => $own->whereNotNull('departed_at')->count(), 'stops_total' => $own->count(),
                'current_stop' => $current ? ['type' => $current->type, 'location' => $places[$current->location_id] ?? null, 'arrived_at' => $current->arrived_at, 'planned' => $current->planned_to ?? $current->planned_from, 'source' => $current->time_source] : null,
                'awb_delivered' => $own_awbs->where('status', 'DELIVERED')->count(), 'awb_total' => $own_awbs->count(),
                'last_signal_at' => $lastEvent[$t->id] ?? null, 'flags' => $flags,
            ];
        }
        $unplanned = $this->store->query('awbs')->where('status', 'PENDING')->whereIn('transport_order_id', $this->store->query('transport_orders')->whereIn('status', ['PLANNED', 'LOADING', 'IN_TRANSIT'])->select('id'))->count();

        return ['counts' => $counts, 'trips' => $rows, 'unplanned_awbs' => $unplanned];
    }
}

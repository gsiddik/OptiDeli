<?php

namespace App\Domain\Tracking;

use App\Domain\Dispatch\FleetAvailability;
use App\Domain\Planning\TripPlanner;
use App\Domain\Shared\TenantStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Geofence capture: positions (or ENTER/EXIT events of an external geofence engine) of a vehicle on a running trip fill in the
 * arrival and departure of its stops, with the source GEOFENCE (or RADAR) instead of MANUAL. The stops are taken strictly in order:
 * only the first stop that has not been left can be entered, and it is left once the vehicle is clearly outside its radius. A
 * location without coordinates has no geofence and is never captured. Whatever is not applied is logged with the reason.
 */
final class TrackingService
{
    public const DEFAULT_RADIUS_M = 150;

    /** A vehicle must be this much farther out than the radius before it counts as having left (GPS jitter at the edge). */
    public const EXIT_FACTOR = 1.2;

    public function __construct(private readonly TenantStore $store, private readonly FleetAvailability $fleet) {}

    public static function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $r = 6371000.0;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;

        return (int) round(2 * $r * asin(min(1, sqrt($a))));
    }

    private function vehicleOf(array $in): object
    {
        $query = $this->store->query('vehicles');
        isset($in['vehicle_id']) ? $query->where('id', $in['vehicle_id']) : $query->where('plate', $in['plate']);

        return $query->first() ?? abort(404, 'Kendaraan tidak ditemukan.');
    }

    private function when(?string $at): CarbonImmutable
    {
        $time = $at ? CarbonImmutable::parse($at) : CarbonImmutable::now();
        abort_if($time->greaterThan(now()->addMinutes(5)), 422, 'Waktu tidak boleh di masa depan.');

        return $time;
    }

    private function runningTrip(object $vehicle): ?object
    {
        return $this->store->query('spm_trips')->where('vehicle_id', $vehicle->id)->where('status', 'IN_TRANSIT')->where('active', true)->first();
    }

    private function nextStop(object $trip): ?object
    {
        return $this->store->query('spm_trip_stops')->where('trip_id', $trip->id)->whereNull('departed_at')->orderBy('sequence')->first();
    }

    private function log(object $trip, ?object $stop, string $event, string $source, CarbonImmutable $time, array $signal, bool $applied, ?string $reason): void
    {
        DB::table('stop_events')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'trip_id' => $trip->id, 'stop_id' => $stop?->id, 'vehicle_id' => $trip->vehicle_id, 'event' => $event, 'source' => $source,
            'occurred_at' => $time, 'latitude' => $signal['latitude'] ?? null, 'longitude' => $signal['longitude'] ?? null, 'distance_m' => $signal['distance_m'] ?? null,
            'applied' => $applied, 'ignored_reason' => $reason, 'created_at' => now(),
        ]);
    }

    /** Applies an arrival or a departure; a refusal of the planner (out of order, time before the previous stop) is logged as ignored. */
    private function apply(object $trip, object $stop, string $event, string $source, CarbonImmutable $time, array $signal): array
    {
        $planner = new TripPlanner($this->store, $this->fleet);
        try {
            $result = $planner->recordStop($trip->id, $stop->id, $event === 'ENTER' ? 'arrive' : 'depart', $time->toIso8601String(), $source, $signal);
        } catch (HttpExceptionInterface|HttpResponseException $e) {
            $message = $e instanceof HttpExceptionInterface ? $e->getMessage() : 'ditolak';
            $this->log($trip, $stop, $event, $source, $time, $signal, false, mb_substr($message, 0, 60));

            return ['applied' => false, 'action' => null, 'reason' => $message];
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first();
            $this->log($trip, $stop, $event, $source, $time, $signal, false, mb_substr((string) $message, 0, 60));

            return ['applied' => false, 'action' => null, 'reason' => $message];
        }

        return ['applied' => true, 'action' => $event, 'reason' => null, 'warnings' => $result['warnings']];
    }

    /**
     * One position of a vehicle.
     *
     * @return array<string, mixed>
     */
    public function position(array $input, string $source = 'GEOFENCE'): array
    {
        $in = Validator::make($input, [
            'vehicle_id' => 'required_without:plate|nullable|uuid', 'plate' => 'required_without:vehicle_id|nullable|string|max:40',
            'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'at' => 'nullable|date',
        ])->validate();
        $vehicle = $this->vehicleOf($in);
        $time = $this->when($in['at'] ?? null);
        $trip = $this->runningTrip($vehicle);
        if ($trip === null) {
            return ['applied' => false, 'action' => null, 'reason' => 'Kendaraan tidak sedang dalam perjalanan SPM.'];
        }
        $stop = $this->nextStop($trip);
        if ($stop === null) {
            return ['applied' => false, 'action' => null, 'reason' => 'Semua stop sudah selesai.', 'trip' => $trip->number];
        }

        return DB::transaction(function () use ($in, $trip, $stop, $time, $source) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $trip = $this->store->find('spm_trips', $trip->id, true);
            $stop = $this->nextStop($trip);
            if ($stop === null || $trip->status !== 'IN_TRANSIT') {
                return ['applied' => false, 'action' => null, 'reason' => 'Perjalanan sudah berubah.'];
            }
            $location = $this->store->find('locations', $stop->location_id);
            if ($location->latitude === null || $location->longitude === null) {
                return ['applied' => false, 'action' => null, 'reason' => 'Lokasi stop belum memiliki koordinat geofence.', 'trip' => $trip->number];
            }
            $distance = self::distanceMeters((float) $in['latitude'], (float) $in['longitude'], (float) $location->latitude, (float) $location->longitude);
            $radius = (int) ($location->geofence_radius_m ?: self::DEFAULT_RADIUS_M);
            $signal = ['latitude' => $in['latitude'], 'longitude' => $in['longitude'], 'distance_m' => $distance];
            $base = ['distance_m' => $distance, 'radius_m' => $radius, 'trip' => $trip->number, 'stop' => $stop->sequence];

            if ($stop->arrived_at === null) {
                if ($distance > $radius) {
                    return $base + ['applied' => false, 'action' => null, 'reason' => 'Di luar radius stop berikutnya.'];
                }

                return $base + $this->apply($trip, $stop, 'ENTER', $source, $time, $signal);
            }
            if ($distance <= $radius * self::EXIT_FACTOR) {
                return $base + ['applied' => false, 'action' => null, 'reason' => 'Masih di dalam radius stop.'];
            }

            return $base + $this->apply($trip, $stop, 'EXIT', $source, $time, $signal);
        });
    }

    /**
     * An ENTER or EXIT that an external geofence engine decided (OptiRadar). The geofence is told by the location id, or by the
     * Radar geofence id kept on the location.
     *
     * @return array<string, mixed>
     */
    public function event(array $input): array
    {
        $in = Validator::make($input, [
            'vehicle_id' => 'required_without:plate|nullable|uuid', 'plate' => 'required_without:vehicle_id|nullable|string|max:40',
            'event' => 'required|in:ENTER,EXIT', 'at' => 'nullable|date', 'location_id' => 'nullable|uuid', 'geofence_id' => 'nullable|string|max:80',
        ])->validate();
        abort_if(empty($in['location_id']) && empty($in['geofence_id']), 422, 'Sebutkan location_id atau geofence_id.');
        $vehicle = $this->vehicleOf($in);
        $time = $this->when($in['at'] ?? null);
        $trip = $this->runningTrip($vehicle);
        if ($trip === null) {
            return ['applied' => false, 'action' => null, 'reason' => 'Kendaraan tidak sedang dalam perjalanan SPM.'];
        }
        $location = isset($in['location_id'])
            ? $this->store->query('locations')->where('id', $in['location_id'])->first()
            : $this->store->query('locations')->where('radar_geofence_id', $in['geofence_id'])->first();
        if ($location === null) {
            return ['applied' => false, 'action' => null, 'reason' => 'Geofence tidak dikenali di master lokasi.', 'trip' => $trip->number];
        }

        return DB::transaction(function () use ($in, $trip, $time, $location) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $trip = $this->store->find('spm_trips', $trip->id, true);
            $stop = $this->nextStop($trip);
            if ($stop === null || $trip->status !== 'IN_TRANSIT') {
                return ['applied' => false, 'action' => null, 'reason' => 'Perjalanan sudah berubah.'];
            }
            $base = ['trip' => $trip->number, 'stop' => $stop->sequence];
            if ($stop->location_id !== $location->id) {
                $this->log($trip, null, $in['event'], 'RADAR', $time, [], false, 'NOT_NEXT_STOP');

                return $base + ['applied' => false, 'action' => null, 'reason' => 'Bukan stop berikutnya dari perjalanan ini.'];
            }
            if (($in['event'] === 'ENTER') !== ($stop->arrived_at === null)) {
                $this->log($trip, $stop, $in['event'], 'RADAR', $time, [], false, 'DUPLICATE');

                return $base + ['applied' => false, 'action' => null, 'reason' => 'Kejadian ini sudah tercatat.'];
            }

            return $base + $this->apply($trip, $stop, $in['event'], 'RADAR', $time, []);
        });
    }

    /** @return list<object> the decisions taken for a trip, newest first */
    public function signals(string $tripId): array
    {
        $this->store->find('spm_trips', $tripId);

        return $this->store->query('stop_events')->where('trip_id', $tripId)->orderByDesc('created_at')->limit(100)->get()->all();
    }
}

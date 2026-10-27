<?php

namespace Tests\Feature;

use App\Domain\Dispatch\LocalFleetAvailability;
use App\Domain\Integration\Master\DriverAssignments;
use App\Domain\Integration\Master\MasterRelay;
use App\Domain\Integration\Master\VehicleMaster;
use App\Domain\Master\MasterDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** Vehicles are keyed once: OptiFleet's asset data comes in, OptiDeli's transport data goes out, nothing echoes. */
class MasterSyncTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private const SECRET = 'whsec_test_secret';

    private const NEXUS_TENANT = '6f1d2c3b-1111-4a5b-8c9d-0123456789ab';

    private string $tenant;

    private array $answer = [200, []];

    protected function setUp(): void
    {
        parent::setUp();
        config(['optideli.deployment_mode' => 'saas', 'optideli.identity_mode' => 'local', 'optideli.optinexus.events.webhook_secret' => self::SECRET,
            'optideli.optinexus.base_url' => 'http://nexus.test', 'optideli.optinexus.service.client_id' => 'svc', 'optideli.optinexus.service.client_secret' => 'secret']);
        Cache::flush();
        $this->tenant = $this->signInTenantMember('cfo');
        DB::table('tenants')->where('id', $this->tenant)->update(['optinexus_tenant_id' => self::NEXUS_TENANT]);
        MasterDefaults::seed($this->tenant);
    }

    private function event(array $asset, int $version, string $plate = 'b-1234-xy'): array
    {
        return [
            'event_id' => (string) Str::uuid(), 'event_type' => 'optifleet.vehicle.upserted', 'schema_version' => '1', 'source_system' => 'optifleet',
            'tenant_id' => self::NEXUS_TENANT, 'occurred_at' => now()->toIso8601String(), 'correlation_id' => (string) Str::uuid(),
            'data' => ['aggregate_type' => 'vehicle', 'aggregate_id' => (string) Str::uuid(), 'correlation' => [], 'payload' => [
                'key' => 'B1234XY', 'registration_number' => $plate, 'version' => $version, 'changed_at' => now()->toIso8601String(), 'asset' => $asset,
            ]],
        ];
    }

    private function deliver(array $event): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $id = (string) Str::uuid();
        $ts = (string) time();

        return $this->call('POST', '/api/v1/integration/optinexus/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_OPTI_WEBHOOK_ID' => $id, 'HTTP_OPTI_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_OPTI_WEBHOOK_SIGNATURE' => 'v1='.hash_hmac('sha256', $id.'.'.$ts.'.'.$body, self::SECRET),
        ], $body);
    }

    private function vehicles(): Collection
    {
        return DB::table('vehicles')->where('tenant_id', $this->tenant)->get();
    }

    private function register(): void
    {
        $this->postJson('/api/v1/fleet/vehicles', ['plate' => 'B9ZZ', 'type' => 'CDD', 'capacity_g' => 2500000, 'length_mm' => 4200, 'width_mm' => 2000, 'height_mm' => 2000, 'documents_valid_until' => now()->addYear()->toDateString()], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    }

    private function nexus(int $status, array $body): void
    {
        $this->answer = [$status, $body];
        Http::fake(['nexus.test/api/v1/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]), 'nexus.test/api/v1/events' => fn () => Http::response($this->answer[1], $this->answer[0])]);
    }

    public function test_an_unknown_vehicle_from_optifleet_becomes_a_transport_draft_that_cannot_be_dispatched(): void
    {
        $this->deliver($this->event(['brand' => 'Hino', 'model' => 'Dutro', 'year' => 2021, 'vin' => 'MHF123'], 1))->assertStatus(202)->assertJson(['result' => 'created']);

        $v = $this->vehicles()->sole();
        $this->assertSame(['b-1234-xy', 'Hino', 'Dutro', 2021, 'MHF123', 'OPTIFLEET', false], [$v->plate, $v->brand, $v->model, $v->vehicle_year, $v->vin, $v->source, $v->transport_complete]);
        $this->assertSame(0, DB::table('master_events')->count(), 'what came from another platform is not sent back');

        $this->expectExceptionMessage('belum dilengkapi');
        (new LocalFleetAvailability)->assertReady($v, (object) ['active' => true], now()->addHours(2)->toImmutable());
    }

    public function test_a_known_vehicle_takes_only_the_asset_data_and_older_versions_are_ignored(): void
    {
        $id = (string) Str::uuid();
        DB::table('vehicles')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'plate' => 'B 1234 XY', 'type' => 'CDD', 'capacity_g' => 2500000, 'length_mm' => 4200, 'width_mm' => 2000, 'height_mm' => 2000, 'documents_valid_until' => now()->addYear()->toDateString(), 'readiness_checked_at' => now()]);

        $this->deliver($this->event(['brand' => 'Hino', 'model' => 'Dutro'], 10))->assertJson(['result' => 'applied']);
        $this->deliver($this->event(['brand' => 'Old', 'model' => 'Old'], 9))->assertJson(['result' => 'stale']);

        $v = DB::table('vehicles')->find($id);
        $this->assertSame(['Hino', 'Dutro', 'CDD', 2500000, 'LOCAL', true], [$v->brand, $v->model, $v->type, $v->capacity_g, $v->source, $v->transport_complete]);
        $this->assertSame(1, $this->vehicles()->count());
    }

    public function test_a_vendors_vehicle_is_not_touched(): void
    {
        $vendor = (string) Str::uuid();
        DB::table('vendors')->insert(['id' => $vendor, 'tenant_id' => $this->tenant, 'name' => 'CV', 'code' => 'CV', 'active' => true]);
        DB::table('vehicles')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'plate' => 'B 1234 XY', 'type' => 'CDD', 'capacity_g' => 1, 'length_mm' => 1, 'width_mm' => 1, 'height_mm' => 1, 'vendor_id' => $vendor, 'verification_status' => 'PENDING']);

        $this->deliver($this->event(['brand' => 'Hino'], 1))->assertJson(['result' => 'ignored']);

        $this->assertNull($this->vehicles()->sole()->brand);
    }

    public function test_a_foreign_producer_or_unknown_tenant_is_ignored(): void
    {
        $event = $this->event(['brand' => 'Hino'], 1);
        $this->deliver(['source_system' => 'optiradar'] + $event)->assertJson(['result' => 'ignored']);
        $this->deliver(['tenant_id' => (string) Str::uuid()] + $event)->assertJson(['result' => 'ignored']);
        $this->assertSame(0, $this->vehicles()->count());
    }

    public function test_the_tenants_own_vehicle_is_published_with_its_transport_data_and_sent_once(): void
    {
        $this->register();

        $row = DB::table('master_events')->sole();
        $payload = json_decode($row->payload, true);
        $this->assertSame(['optideli.vehicle.upserted', 'B9ZZ', 2500000], [$row->event_key, $payload['key'], $payload['transport']['capacity_g']]);
        $this->assertArrayNotHasKey('asset', $payload);

        $this->nexus(201, ['data' => []]);
        $this->assertSame(['delivered' => 1, 'retrying' => 0, 'failed' => 0], app(MasterRelay::class)->relay());
        $this->assertSame(['delivered' => 0, 'retrying' => 0, 'failed' => 0], app(MasterRelay::class)->relay());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/events') && $r['event_id'] === $row->id && $r['event_key'] === 'optideli.vehicle.upserted' && $r['tenant_id'] === self::NEXUS_TENANT);
    }

    public function test_an_event_nexus_does_not_know_yet_is_retried_and_other_refusals_are_parked(): void
    {
        $this->register();
        $this->nexus(422, ['error' => ['code' => 'EVENT_INVALID']]);
        $this->assertSame(1, app(MasterRelay::class)->relay()['retrying']);
        $this->assertSame('PENDING', DB::table('master_events')->sole()->status);

        DB::table('master_events')->update(['last_attempted_at' => null]);
        $this->nexus(403, ['error' => ['code' => 'EVENT_SOURCE_DENIED']]);
        $this->assertSame(1, app(MasterRelay::class)->relay()['failed']);
        $this->assertSame(1, app(MasterRelay::class)->retryFailed());
    }

    public function test_the_transport_form_completes_a_draft_and_publishes_it(): void
    {
        $this->deliver($this->event(['brand' => 'Hino'], 1));
        $draft = $this->vehicles()->sole()->id;
        $type = (object) ['id' => (string) Str::uuid()];
        DB::table('carrier_types')->insert(['id' => $type->id, 'tenant_id' => $this->tenant, 'code' => 'CDD', 'name' => 'CDD Box']);

        $this->putJson("/api/v1/fleet/vehicles/{$draft}/transport", ['carrier_type_id' => $type->id, 'capacity_g' => 3000000, 'length_mm' => 4500, 'width_mm' => 2000, 'height_mm' => 2100], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        $v = DB::table('vehicles')->find($draft);
        $this->assertTrue($v->transport_complete);
        $this->assertSame('OPTIFLEET', $v->source);
        $this->assertSame(3000000, json_decode(DB::table('master_events')->sole()->payload, true)['transport']['capacity_g']);
    }

    public function test_the_backfill_and_an_unlinked_tenant(): void
    {
        $id = (string) Str::uuid();
        DB::table('vehicles')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'plate' => 'B 1 AA', 'type' => 'CDD', 'capacity_g' => 1, 'length_mm' => 1, 'width_mm' => 1, 'height_mm' => 1]);
        $this->assertSame(1, VehicleMaster::publishAll());

        DB::table('tenants')->where('id', $this->tenant)->update(['optinexus_tenant_id' => null]);
        $this->assertFalse(VehicleMaster::publish($this->tenant, $id));
    }

    public function test_a_draft_from_another_platform_is_not_published_back_and_a_partial_event_keeps_stored_data(): void
    {
        $this->deliver($this->event(['brand' => 'Hino', 'model' => 'Dutro', 'year' => 2021], 1))->assertJson(['result' => 'created']);
        $this->assertSame(0, VehicleMaster::publishAll($this->tenant));

        $this->deliver($this->event(['brand' => 'Hino'], 2))->assertJson(['result' => 'applied']);
        $v = $this->vehicles()->sole();
        $this->assertSame(['Hino', 'Dutro', 2021], [$v->brand, $v->model, (int) $v->vehicle_year]);
    }

    public function test_the_same_plate_with_other_spacing_is_a_duplicate_and_versions_only_increase(): void
    {
        $this->deliver($this->event(['brand' => 'Hino'], 1, 'B 7 AB'));
        $this->postJson('/api/v1/fleet/vehicles', ['plate' => 'b-7-ab', 'type' => 'CDD', 'capacity_g' => 1, 'length_mm' => 1, 'width_mm' => 1, 'height_mm' => 1, 'documents_valid_until' => now()->addYear()->toDateString()], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);

        $this->register();
        $id = $this->vehicles()->firstWhere('plate', 'B9ZZ')->id;
        VehicleMaster::publish($this->tenant, $id);
        VehicleMaster::publish($this->tenant, $id);
        $versions = DB::table('master_events')->where('aggregate_id', $id)->orderBy('created_at')->get()->map(fn ($e) => json_decode($e->payload, true)['version'])->all();
        $this->assertCount(3, $versions);
        $this->assertSame($versions, collect($versions)->sort()->values()->all());
        $this->assertCount(3, array_unique($versions));
    }

    public function test_rows_of_an_unlinked_tenant_do_not_take_batch_places_from_linked_ones(): void
    {
        $this->register();
        $other = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $other, 'slug' => 'unlinked', 'name' => 'Unlinked', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('master_events')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $other, 'event_key' => 'optideli.vehicle.upserted', 'aggregate_id' => (string) Str::uuid(), 'payload' => '{}', 'created_at' => now()->subDay()]);
        $this->nexus(202, []);

        $this->assertSame(1, app(MasterRelay::class)->relay(1)['delivered']);
    }

    private function radarDriver(int $version, array $over = []): array
    {
        return [
            'event_id' => (string) Str::uuid(), 'event_type' => 'optiradar.driver.upserted', 'schema_version' => '1', 'source_system' => 'optiradar',
            'tenant_id' => self::NEXUS_TENANT, 'occurred_at' => now()->toIso8601String(), 'correlation_id' => (string) Str::uuid(),
            'data' => ['aggregate_type' => 'driver', 'aggregate_id' => '5', 'correlation' => [], 'payload' => $over + [
                'key' => 'SIM123456', 'license_number' => 'sim-1234-56', 'name' => 'Budi', 'version' => $version, 'changed_at' => now()->toIso8601String(),
                'telematics' => ['tag_id' => 'TAG-9'],
            ]],
        ];
    }

    public function test_a_driver_from_optiradar_is_a_draft_that_cannot_be_allocated_until_completed_and_is_not_sent_back(): void
    {
        $this->deliver($this->radarDriver(1))->assertStatus(202)->assertJson(['result' => 'created']);

        $driver = DB::table('drivers')->where('tenant_id', $this->tenant)->sole();
        $this->assertSame(['sim-1234-56', 'OPTIRADAR', false, 'TAG-9'], [$driver->license_number, $driver->source, $driver->profile_complete, $driver->radar_tag]);
        $this->assertSame(0, DB::table('master_events')->count());
        $vehicle = (object) ['active' => true, 'status' => 'AVAILABLE', 'transport_complete' => true, 'readiness_checked_at' => now(), 'documents_valid_until' => now()->addYear()->toDateString()];
        try {
            (new LocalFleetAvailability)->assertReady($vehicle, $driver, now()->addHours(2)->toImmutable());
            $this->fail('a draft driver was accepted');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Data pengemudi belum dilengkapi', $e->getMessage());
        }

        $this->putJson("/api/v1/fleet/drivers/{$driver->id}/profile", ['license_valid_until' => now()->addYear()->toDateString(), 'eligibility_verified_until' => now()->addDays(30)->toIso8601String(), 'phone' => '0812'])->assertOk();
        $this->assertTrue((bool) DB::table('drivers')->where('id', $driver->id)->value('profile_complete'));
    }

    public function test_a_known_driver_only_receives_the_tag_and_an_old_version_or_a_forged_producer_changes_nothing(): void
    {
        $id = (string) Str::uuid();
        DB::table('drivers')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'name' => 'Budi S', 'license_number' => 'SIM 1234 56', 'license_valid_until' => now()->addYear()->toDateString(), 'eligibility_verified_until' => now()->addYear(), 'active' => true]);

        $this->deliver($this->radarDriver(10))->assertJson(['result' => 'applied']);
        $this->deliver($this->radarDriver(9, ['telematics' => ['tag_id' => 'OLD']]))->assertJson(['result' => 'stale']);
        $forged = $this->radarDriver(20);
        $forged['source_system'] = 'optifleet';
        $this->deliver($forged)->assertJson(['result' => 'ignored']);

        $driver = DB::table('drivers')->where('id', $id)->first();
        $this->assertSame(['Budi S', 'TAG-9', 1], [$driver->name, $driver->radar_tag, DB::table('drivers')->where('tenant_id', $this->tenant)->count()]);
    }

    public function test_a_verified_vendor_vehicle_and_driver_go_to_optiradar_only_under_their_own_keys(): void
    {
        $vendor = (string) Str::uuid();
        $vehicle = (string) Str::uuid();
        $driver = (string) Str::uuid();
        DB::table('vendors')->insert(['id' => $vendor, 'tenant_id' => $this->tenant, 'name' => 'Vendor Jaya', 'code' => 'VJ', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $verified = ['verification_status' => 'VERIFIED', 'verified_by' => auth()->id(), 'verified_at' => now()];
        DB::table('vehicles')->insert(['id' => $vehicle, 'tenant_id' => $this->tenant, 'plate' => 'B 5 VV', 'type' => 'CDD', 'capacity_g' => 1000, 'length_mm' => 1, 'width_mm' => 1, 'height_mm' => 1, 'vendor_id' => $vendor] + $verified);
        DB::table('drivers')->insert(['id' => $driver, 'tenant_id' => $this->tenant, 'name' => 'Vendor Driver', 'license_number' => 'sim 9-9', 'active' => true, 'vendor_id' => $vendor] + $verified);

        $this->assertTrue(\App\Domain\Integration\Master\VendorMaster::publish($this->tenant, 'VEHICLE', $vehicle));
        $this->assertTrue(\App\Domain\Integration\Master\VendorMaster::publish($this->tenant, 'DRIVER', $driver));
        $this->assertFalse(VehicleMaster::publish($this->tenant, $vehicle), 'the own-fleet event (OptiFleet) never carries a vendor vehicle');

        $keys = DB::table('master_events')->orderBy('created_at')->pluck('event_key')->all();
        $this->assertEqualsCanonicalizing(['optideli.vendor_vehicle.upserted', 'optideli.vendor_driver.upserted'], $keys);
        $v = json_decode(DB::table('master_events')->where('event_key', 'optideli.vendor_vehicle.upserted')->value('payload'), true);
        $this->assertSame(['B5VV', 'VJ', true], [$v['key'], $v['vendor']['code'], $v['active']]);
        $d = json_decode(DB::table('master_events')->where('event_key', 'optideli.vendor_driver.upserted')->value('payload'), true);
        $this->assertSame(['SIM99', 'sim 9-9', 'Vendor Jaya'], [$d['key'], $d['license_number'], $d['vendor']['name']]);

        DB::table('vehicles')->where('id', $vehicle)->update(['active' => false]);
        $this->assertTrue(\App\Domain\Integration\Master\VendorMaster::publish($this->tenant, 'VEHICLE', $vehicle));
        $events = DB::table('master_events')->where('event_key', 'optideli.vendor_vehicle.upserted')->get()->map(fn ($e) => json_decode($e->payload, true))->sortBy('version')->values()->all();
        $this->assertCount(2, $events);
        $this->assertTrue($events[0]['version'] < $events[1]['version']);
        $this->assertSame([true, false], [$events[0]['active'], $events[1]['active']]);

        DB::table('vehicles')->where('id', $vehicle)->update(['verification_status' => 'PENDING', 'verified_by' => null, 'verified_at' => null]);
        $this->assertFalse(\App\Domain\Integration\Master\VendorMaster::publish($this->tenant, 'VEHICLE', $vehicle), 'only what the tenant verified is published');
    }

    public function test_a_vendors_vehicle_or_driver_is_never_published_as_an_assignment(): void
    {
        $vehicle = (string) Str::uuid();
        $driver = (string) Str::uuid();
        $vendor = (string) Str::uuid();
        DB::table('vendors')->insert(['id' => $vendor, 'tenant_id' => $this->tenant, 'name' => 'V', 'code' => 'V1', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vehicles')->insert(['id' => $vehicle, 'tenant_id' => $this->tenant, 'plate' => 'B 5 VV', 'type' => 'CDD', 'capacity_g' => 1, 'length_mm' => 1, 'width_mm' => 1, 'height_mm' => 1, 'vendor_id' => $vendor, 'verification_status' => 'PENDING']);
        DB::table('drivers')->insert(['id' => $driver, 'tenant_id' => $this->tenant, 'name' => 'D', 'license_number' => 'X1', 'active' => true, 'vendor_id' => $vendor, 'verification_status' => 'PENDING']);

        $this->assertFalse(DriverAssignments::publish($this->tenant, $vehicle, $driver, now(), 'SPM_TRIP', (string) Str::uuid(), 'PJ-1'));
        $this->assertSame(0, DB::table('master_events')->count());
    }
}

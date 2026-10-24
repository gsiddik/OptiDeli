<?php

namespace Tests\Feature;

use App\Domain\Dispatch\FleetAvailability;
use App\Domain\Dispatch\LocalFleetAvailability;
use App\Domain\Dispatch\OptiFleetAvailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Wave 3: vehicle availability from OptiFleet, through the broker's signed webhook and the live availability query. */
class FleetAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    private const NEXUS_TENANT = '6f1d2c3b-1111-4a5b-8c9d-0123456789ab';

    private string $tenant;

    /** @var array{0: int, 1: mixed} what OptiFleet answers next */
    private array $fleetAnswer = [200, ['data' => ['available' => true, 'blockers' => []]]];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'optideli.deployment_mode' => 'saas', 'optideli.identity_mode' => 'local', 'optideli.fleet.driver' => 'optifleet',
            'optideli.fleet.optifleet_url' => 'https://fleet.test', 'optideli.optinexus.events.webhook_secret' => self::SECRET,
        ]);
        Http::fake(['fleet.test/*' => fn () => Http::response($this->fleetAnswer[1], $this->fleetAnswer[0])]);
        $this->tenant = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $this->tenant, 'slug' => 'test', 'name' => 'Test', 'minimum_margin_bps' => 1500, 'policy_version' => 1, 'optinexus_tenant_id' => self::NEXUS_TENANT]);
    }

    private function event(string $status, string $plate = 'B 1234 XY', ?string $changedAt = null, array $override = []): array
    {
        return array_replace_recursive([
            'event_id' => (string) Str::uuid(), 'event_type' => 'optifleet.vehicle.availability_changed', 'schema_version' => '1', 'source_system' => 'optifleet',
            'tenant_id' => self::NEXUS_TENANT, 'occurred_at' => now()->toIso8601String(), 'correlation_id' => (string) Str::uuid(),
            'data' => ['aggregate_type' => 'vehicle_availability_change', 'aggregate_id' => (string) Str::uuid(), 'correlation' => [], 'payload' => [
                'vehicle_id' => (string) Str::uuid(), 'registration_number' => $plate, 'operational_status' => $status, 'previous_operational_status' => 'AVAILABLE',
                'status' => $status === 'AVAILABLE' ? 'ACTIVE' : 'IN_MAINTENANCE', 'changed_at' => $changedAt ?? now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ]],
        ], $override);
    }

    private function deliver(array $event, ?string $secret = null, ?int $timestamp = null): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $id = (string) Str::uuid();
        $ts = (string) ($timestamp ?? time());

        return $this->call('POST', '/api/v1/integration/optinexus/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_OPTI_WEBHOOK_ID' => $id, 'HTTP_OPTI_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_OPTI_WEBHOOK_SIGNATURE' => 'v1='.hash_hmac('sha256', $id.'.'.$ts.'.'.$body, $secret ?? self::SECRET),
        ], $body);
    }

    private function state(string $plate = 'B 1234 XY'): ?object
    {
        return DB::table('fleet_vehicle_states')->where('tenant_id', $this->tenant)->where('registration_key', preg_replace('/[^A-Z0-9]/', '', Str::upper($plate)))->first();
    }

    private function vehicle(string $plate = 'B 1234 XY'): object
    {
        return (object) ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'plate' => $plate, 'status' => 'AVAILABLE', 'readiness_checked_at' => now(), 'documents_valid_until' => now()->addYear()->toDateString()];
    }

    private function driver(): object
    {
        return (object) ['active' => true, 'license_valid_until' => now()->addYear()->toDateString(), 'eligibility_verified_until' => now()->addDays(3)];
    }

    private function withFleetToken(): void
    {
        DB::table('tenants')->where('id', $this->tenant)->update(['fleet_availability_token' => Crypt::encryptString('ofaf_tenant_token')]);
    }

    private function assertReady(?object $vehicle = null): void
    {
        app(FleetAvailability::class)->assertReady($vehicle ?? $this->vehicle(), $this->driver(), now()->addHours(4)->toImmutable(), now()->addHour()->toImmutable());
    }

    public function test_the_driver_setting_selects_the_provider(): void
    {
        $this->assertInstanceOf(OptiFleetAvailability::class, app(FleetAvailability::class));
        config(['optideli.fleet.driver' => 'local']);
        $this->assertInstanceOf(LocalFleetAvailability::class, app(FleetAvailability::class));
    }

    public function test_a_signed_event_updates_the_projection_and_a_replay_or_older_event_changes_nothing(): void
    {
        $first = $this->event('ON_HOLD', 'B 1234 XY', now()->subMinutes(5)->utc()->format('Y-m-d\TH:i:s.v\Z'));
        $this->deliver($first)->assertStatus(202)->assertJsonPath('result', 'applied');
        $this->assertSame('ON_HOLD', $this->state('b-1234-xy')->operational_status, 'plates match without case, spaces or dashes');

        $this->deliver($first)->assertStatus(202); // retry of the same delivery
        $this->assertSame(1, DB::table('fleet_vehicle_states')->count());

        $this->deliver($this->event('AVAILABLE'))->assertStatus(202)->assertJsonPath('result', 'applied');
        $this->assertSame('AVAILABLE', $this->state()->operational_status);

        $this->deliver($this->event('ON_HOLD', 'B 1234 XY', now()->subHour()->utc()->format('Y-m-d\TH:i:s.v\Z')))->assertStatus(202)->assertJsonPath('result', 'stale');
        $this->assertSame('AVAILABLE', $this->state()->operational_status, 'a late old event never overwrites a newer state');
    }

    public function test_unsigned_forged_expired_or_secretless_deliveries_are_refused_and_change_nothing(): void
    {
        $event = $this->event('ON_HOLD');

        $this->deliver($event, 'wrong-secret')->assertStatus(401);
        $this->deliver($event, null, time() - 3600)->assertStatus(401);
        $this->postJson('/api/v1/integration/optinexus/events', $event)->assertStatus(401);
        config(['optideli.optinexus.events.webhook_secret' => null]);
        $this->deliver($event, '')->assertStatus(401);

        $this->assertSame(0, DB::table('fleet_vehicle_states')->count());
    }

    public function test_events_for_unlinked_tenants_other_sources_or_other_types_are_ignored(): void
    {
        $this->deliver($this->event('ON_HOLD', 'B 1234 XY', null, ['tenant_id' => '11111111-2222-4333-8444-555555555555']))->assertStatus(202)->assertJsonPath('result', 'ignored');
        $this->deliver($this->event('ON_HOLD', 'B 1234 XY', null, ['source_system' => 'optiradar']))->assertJsonPath('result', 'ignored');
        $this->deliver($this->event('ON_HOLD', 'B 1234 XY', null, ['event_type' => 'optifleet.workshop_invoice.recorded']))->assertJsonPath('result', 'ignored');
        $this->deliver($this->event('BROKEN'))->assertJsonPath('result', 'ignored');

        $this->assertSame(0, DB::table('fleet_vehicle_states')->count());
    }

    public function test_live_answer_decides_and_the_window_and_tenant_token_are_sent(): void
    {
        $this->withFleetToken();
        $this->fleetAnswer = [200, ['data' => ['available' => true, 'blockers' => []]]];

        $this->assertReady();

        Http::assertSent(function (HttpRequest $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://fleet.test/api/v1/integration/availability')
                && $request->header('Authorization')[0] === 'Bearer ofaf_tenant_token'
                && $query['registration_number'] === 'B 1234 XY' && isset($query['from'], $query['to']) && $query['to'] > $query['from'];
        });
        $this->fleetAnswer = [200, ['data' => ['available' => false, 'blockers' => [['code' => 'SERVICE_SCHEDULED', 'work_order' => 'WO-9', 'starts_at' => '2026-11-03T06:00:00+00:00', 'ends_at' => '2026-11-03T18:00:00+00:00']]]]];
        try {
            $this->assertReady();
            $this->fail('A vehicle in a planned service window must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('servis terjadwal WO-9', $e->getMessage());
        }

        $this->deliver($this->event('ON_HOLD'));
        $this->fleetAnswer = [200, ['data' => ['available' => true, 'blockers' => []]]];
        $this->assertReady(); // the live answer wins over an older announcement
    }

    public function test_when_fleet_cannot_answer_the_last_announcement_is_used_and_without_one_the_dispatch_is_refused(): void
    {
        $this->withFleetToken();
        $this->fleetAnswer = [503, 'down'];

        try {
            $this->assertReady();
            $this->fail('Unverifiable availability must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }

        config(['optideli.fleet.fail_open' => true]);
        $this->assertReady();
        config(['optideli.fleet.fail_open' => false]);

        $this->deliver($this->event('ON_HOLD'));
        $this->expectExceptionObject(new HttpException(422, 'Kendaraan tidak tersedia menurut OptiFleet: status ON_HOLD.'));
        $this->assertReady();
    }

    public function test_without_a_credential_only_announcements_count_and_an_unknown_vehicle_is_not_blocked(): void
    {
        $this->assertReady(); // nothing known, nothing asked
        Http::assertNothingSent();

        $this->deliver($this->event('IN_USE'));
        try {
            $this->assertReady();
            $this->fail('A vehicle OptiFleet announced as in use must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->withFleetToken();
        $this->fleetAnswer = [404, ['message' => 'Vehicle not found.', 'code' => 'VEHICLE_NOT_FOUND']];
        $this->assertReady(); // live says OptiFleet does not know it: it is OptiDeli's own vehicle
    }

    public function test_deli_checks_still_apply_first_and_the_dispatch_endpoint_refuses_a_blocked_vehicle(): void
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $user->id, 'role' => 'dispatcher', 'active' => true]);
        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant]);
        $order = $this->postJson('/api/v1/orders', ['customer_name' => 'PT Pelanggan', 'stage' => 'FIRST_MILE', 'origin' => 'Bekasi', 'destination' => 'Jakarta', 'currency' => 'IDR', 'base_price_minor' => 100000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'items' => [['description' => 'Paket', 'quantity' => 2, 'unit_weight_g' => 10000, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 200, 'stackable' => false]]], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/orders/'.$order.'/transition', ['action' => 'confirm'])->assertOk();

        $vehicle = (string) Str::uuid();
        $driver = (string) Str::uuid();
        DB::table('vehicles')->insert(['id' => $vehicle, 'tenant_id' => $this->tenant, 'plate' => 'B 7777 ZZ', 'type' => 'Box', 'capacity_g' => 1000000, 'length_mm' => 4000, 'width_mm' => 2000, 'height_mm' => 2000, 'status' => 'AVAILABLE', 'documents_valid_until' => now()->addYear()->toDateString(), 'readiness_checked_at' => now()]);
        DB::table('drivers')->insert(['id' => $driver, 'tenant_id' => $this->tenant, 'name' => 'Pengemudi', 'license_number' => Str::random(16), 'license_valid_until' => now()->addYear()->toDateString(), 'eligibility_verified_until' => now()->addDays(2), 'active' => true]);
        $plan = ['order_id' => $order, 'vehicle_id' => $vehicle, 'driver_id' => $driver, 'vendor_base_minor' => 80000000, 'vendor_accessorial_minor' => 0, 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(4)->toIso8601String(), 'load_safety_attestation' => 'Pemeriksaan muatan, pengikatan dan distribusi beban telah dilakukan.'];

        $this->deliver($this->event('ON_HOLD', 'B 7777 ZZ'));
        $this->postJson('/api/v1/dispatch/plans', $plan, ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);
        $this->assertDatabaseCount('dispatch_plans', 0);

        $this->deliver($this->event('AVAILABLE', 'B 7777 ZZ'));
        $this->postJson('/api/v1/dispatch/plans', $plan, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    }

    public function test_the_readiness_lookup_reports_what_optifleet_says(): void
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $user->id, 'role' => 'dispatcher', 'active' => true]);
        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant]);

        $this->getJson('/api/v1/fleet/assets/B-1234-XY/readiness')->assertOk()->assertJsonPath('data.status', 'UNKNOWN')->assertJsonPath('data.is_available', true);
        $this->deliver($this->event('ON_HOLD'));
        $this->getJson('/api/v1/fleet/assets/B-1234-XY/readiness')->assertOk()->assertJsonPath('data.is_available', false)->assertJsonPath('data.source', 'event');
    }
}

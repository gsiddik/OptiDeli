<?php

namespace Tests\Feature;

use App\Domain\Master\MasterDefaults;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** AWBs of an SPM and the trips that carry them: planning, margin gate, stops, SPM progress, overnight/detention (F2/M4). */
class PlanningTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private User $dispatcher;

    private User $supervisor;

    private array $m = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('dispatcher');
        MasterDefaults::seed($this->tenant);
        $this->dispatcher = auth()->user();
        $this->supervisor = $this->member('supervisor');
        $this->m['cust'] = $this->master('customers', ['code' => 'CUST', 'name' => 'PT Maju', 'payment_terms_days' => 30]);
        $this->m['pool'] = $this->master('locations', ['code' => 'POOL', 'name' => 'Pool Jakarta', 'type' => 'POOL']);
        $this->m['wh'] = $this->master('locations', ['code' => 'WH', 'name' => 'Gudang Bandung', 'type' => 'WAREHOUSE']);
        $this->m['wh2'] = $this->master('locations', ['code' => 'WH2', 'name' => 'Gudang Cirebon', 'type' => 'WAREHOUSE']);
        foreach (['FREIGHT', 'DETENTION'] as $code) {
            $this->m[$code] = DB::table('charge_codes')->where('tenant_id', $this->tenant)->where('code', $code)->value('id');
        }
        $this->m['v1'] = $this->vehicle('B 1111 AA');
        $this->m['v2'] = $this->vehicle('B 2222 BB');
        $this->m['d1'] = $this->driver('Budi');
        $this->m['d2'] = $this->driver('Andi');
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $user->id, 'role' => $role, 'active' => true]);

        return $user;
    }

    private function vehicle(string $plate, int $capacityG = 1_000_000): string
    {
        $id = (string) Str::uuid();
        DB::table('vehicles')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'plate' => $plate, 'type' => 'Box', 'capacity_g' => $capacityG, 'length_mm' => 4000, 'width_mm' => 2000, 'height_mm' => 2000, 'status' => 'AVAILABLE', 'documents_valid_until' => now()->addYear()->toDateString(), 'readiness_checked_at' => now()]);

        return $id;
    }

    private function driver(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('drivers')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'name' => $name, 'license_number' => 'SIM-'.$name, 'license_valid_until' => now()->addYear()->toDateString(), 'eligibility_verified_until' => now()->addDays(2), 'active' => true]);

        return $id;
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function master(string $resource, array $body): string
    {
        return $this->postJson("/api/v1/master/{$resource}", $body, $this->key())->assertCreated()->json('data.id');
    }

    private function as(User $user)
    {
        return $this->actingAs($user)->withSession(['tenant_id' => $this->tenant]);
    }

    private function tariff(string $side, array $lines): void
    {
        $t = $this->as($this->dispatcher)->postJson('/api/v1/tariffs', ['code' => 'T'.Str::random(5), 'name' => 'Tarif', 'side' => $side, 'valid_from' => '2026-01-01'])->assertCreated()->json('data.id');
        foreach ($lines as $line) {
            $this->as($this->dispatcher)->postJson("/api/v1/tariffs/{$t}/lines", $line)->assertCreated();
        }
        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$t}/approve")->assertOk();
    }

    /** An SPM with two lines of 100 kg that is planned: AWB 1 goes to Bandung, AWB 2 to Cirebon. */
    private function spm(int $sell = 2_000_000_00, int $buy = 1_000_000_00): array
    {
        if ($sell > 0) {
            $this->tariff('SELL', [['charge_code_id' => $this->m['FREIGHT'], 'basis' => 'TRIP', 'unit_price_minor' => $sell], ['charge_code_id' => $this->m['DETENTION'], 'basis' => 'DAY', 'unit_price_minor' => 500_000_00, 'free_time_hours' => 24]]);
        }
        if ($buy > 0) {
            $this->tariff('BUY', [['charge_code_id' => $this->m['FREIGHT'], 'basis' => 'TRIP', 'unit_price_minor' => $buy], ['charge_code_id' => $this->m['DETENTION'], 'basis' => 'DAY', 'unit_price_minor' => 300_000_00, 'free_time_hours' => 24]]);
        }
        $order = $this->as($this->dispatcher)->postJson('/api/v1/orders', [
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'customer_id' => $this->m['cust'],
            'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'],
            'items' => [['description' => 'Palet', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]],
        ], $this->key())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/orders/{$order}/transition", ['action' => 'confirm'])->assertOk();
        $spm = $this->postJson("/api/v1/orders/{$order}/spm", ['lines' => [
            ['material' => 'Paket A', 'quantity' => 1, 'weight_kg' => 100, 'volume_cbm' => 1], ['material' => 'Paket B', 'quantity' => 1, 'weight_kg' => 100, 'volume_cbm' => 1],
        ]])->assertCreated()->json('data');
        $a = $this->postJson("/api/v1/spm/{$spm['spm']['id']}/awbs", ['line_ids' => [$spm['lines'][0]['id']], 'destination_location_id' => $this->m['wh'], 'consignee_name' => 'Toko A'])->assertCreated()->json('data');
        $b = $this->postJson("/api/v1/spm/{$spm['spm']['id']}/awbs", ['line_ids' => [$spm['lines'][1]['id']], 'destination_location_id' => $this->m['wh2']])->assertCreated()->json('data');
        $this->postJson("/api/v1/spm/{$spm['spm']['id']}/status", ['to' => 'PLANNED'])->assertOk();

        return ['id' => $spm['spm']['id'], 'order' => $order, 'a' => $a, 'b' => $b];
    }

    private function trip(array $spm, array $awbs, ?string $vehicle = null, ?string $driver = null, array $o = [])
    {
        return $this->as($this->dispatcher)->postJson('/api/v1/spm-trips', $o + [
            'spm_id' => $spm['id'], 'awb_ids' => $awbs, 'vehicle_id' => $vehicle ?? $this->m['v1'], 'driver_id' => $driver ?? $this->m['d1'],
            'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(5)->toIso8601String(),
        ]);
    }

    private function stopId(array $trip, int $sequence): string
    {
        return collect($trip['stops'])->firstWhere('sequence', $sequence)['id'];
    }

    private function record(string $trip, string $stop, string $event, string $at)
    {
        return $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$trip}/stops/{$stop}/{$event}", ['at' => $at]);
    }

    public function test_an_awb_groups_lines_and_has_its_own_number_and_destination(): void
    {
        $spm = $this->spm();
        $this->assertMatchesRegularExpression('/^AWB-\d{6}-00001$/', $spm['a']['number']);
        $this->assertMatchesRegularExpression('/^AWB-\d{6}-00002$/', $spm['b']['number']);
        $this->assertSame('Gudang Cirebon', $spm['b']['destination_text']);
        $shown = $this->getJson("/api/v1/spm/{$spm['id']}")->json('data');
        $this->assertCount(2, $shown['awbs']);
        $this->assertSame([$shown['lines'][0]['id']], $shown['awbs'][0]['line_ids']);
        $this->assertSame('Toko A', $shown['awbs'][0]['consignee_name']);
    }

    public function test_planning_an_spm_puts_loose_lines_in_one_awb(): void
    {
        $order = $this->as($this->dispatcher)->postJson('/api/v1/orders', [
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'customer_id' => $this->m['cust'],
            'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'],
            'items' => [['description' => 'Palet', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]],
        ], $this->key())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/orders/{$order}/transition", ['action' => 'confirm'])->assertOk();
        $spm = $this->postJson("/api/v1/orders/{$order}/spm")->assertCreated()->json('data');
        $this->assertSame([], $spm['awbs']);
        $planned = $this->postJson("/api/v1/spm/{$spm['spm']['id']}/status", ['to' => 'PLANNED'])->assertOk()->json('data.spm');
        $this->assertCount(1, $planned['awbs']);
        $this->assertSame('Gudang Bandung', $planned['awbs'][0]['destination_text']);
    }

    public function test_awb_rules(): void
    {
        $spm = $this->spm();
        $shown = $this->getJson("/api/v1/spm/{$spm['id']}")->json('data');
        $this->postJson("/api/v1/spm/{$spm['id']}/awbs", ['line_ids' => [$shown['lines'][0]['id']]])->assertUnprocessable()->assertJsonValidationErrors('line_ids');
        $this->postJson("/api/v1/awbs/{$spm['a']['id']}/cancel", ['reason' => 'x'])->assertUnprocessable();
        $this->postJson("/api/v1/awbs/{$spm['a']['id']}/cancel", ['reason' => 'Dibatalkan pelanggan'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertNull(DB::table('shipment_lines')->where('id', $shown['lines'][0]['id'])->value('awb_id'));
        $this->putJson("/api/v1/awbs/{$spm['b']['id']}", ['consignee_name' => 'Toko B', 'destination_location_id' => $this->m['wh']])->assertOk()->assertJsonPath('data.destination_text', 'Gudang Bandung');

        $this->trip($spm, [$spm['b']['id']])->assertCreated();
        $this->putJson("/api/v1/awbs/{$spm['b']['id']}", ['consignee_name' => 'Lain'])->assertStatus(409);
    }

    public function test_one_spm_has_several_trips_each_carrying_its_own_awbs(): void
    {
        $spm = $this->spm();
        $t1 = $this->trip($spm, [$spm['a']['id']])->assertCreated()->json('data');
        $this->assertSame('PLANNED', $t1['trip']['status']);
        $this->assertMatchesRegularExpression('/^PJ-\d{6}-00001$/', $t1['trip']['number']);
        $this->assertSame(['PICKUP', 'DROP'], array_column($t1['stops'], 'type'));
        $this->assertSame('Pool Jakarta', $t1['stops'][0]['location_label']);
        $this->assertSame([$spm['a']['number']], $t1['stops'][1]['awb_numbers']);
        $this->assertEquals(500_000_00, $t1['trip']['cost_minor'], 'half of the SPM cost');
        $this->assertEquals(1_000_000_00, $t1['trip']['sell_minor'], 'half of the SPM price');

        $this->trip($spm, [$spm['a']['id']], $this->m['v2'], $this->m['d2'])->assertUnprocessable()->assertJsonValidationErrors('awb_ids');
        $this->trip($spm, [$spm['b']['id']])->assertStatus(409);
        $t2 = $this->trip($spm, [$spm['b']['id']], $this->m['v2'], $this->m['d2'])->assertCreated()->json('data');
        $this->assertNotSame($t1['trip']['id'], $t2['trip']['id']);

        $shown = $this->getJson("/api/v1/spm/{$spm['id']}")->json('data');
        $this->assertSame(['ON_TRIP', 'ON_TRIP'], array_column($shown['awbs'], 'status'));
        $this->assertSame($t1['trip']['number'], $shown['awbs'][0]['trip_number']);
        $this->assertSame('DISPATCHED', DB::table('orders')->where('id', $spm['order'])->value('status'));
        $this->assertCount(2, $this->getJson("/api/v1/spm-trips?spm_id={$spm['id']}")->json('data'));
    }

    public function test_cargo_must_fit_and_the_spm_must_be_planned(): void
    {
        $spm = $this->spm();
        $small = $this->vehicle('B 9 SM', 50_000);
        $this->trip($spm, [$spm['a']['id']], $small)->assertUnprocessable();
        $this->as($this->dispatcher)->postJson('/api/v1/spm-trips', ['spm_id' => $spm['id'], 'awb_ids' => [$spm['a']['id']], 'vehicle_id' => $this->m['v1'], 'driver_id' => $this->m['d1'], 'starts_at' => now()->subDay()->toIso8601String(), 'ends_at' => now()->addHours(5)->toIso8601String()])->assertUnprocessable();
        $cancelled = $this->spm();
        $this->postJson("/api/v1/spm/{$cancelled['id']}/status", ['to' => 'CANCELLED', 'reason' => 'Dibatalkan sebelum jalan'])->assertOk();
        $this->trip($cancelled, [$cancelled['a']['id']])->assertStatus(409);
    }

    public function test_a_vehicle_or_driver_is_never_double_booked(): void
    {
        $spm = $this->spm();
        $this->trip($spm, [$spm['a']['id']])->assertCreated();
        $this->trip($spm, [$spm['b']['id']], $this->m['v2'], $this->m['d1'])->assertStatus(409);
        $this->trip($spm, [$spm['b']['id']], $this->m['v1'], $this->m['d2'])->assertStatus(409);
    }

    public function test_a_trip_below_the_margin_threshold_needs_a_reason_and_someone_elses_approval(): void
    {
        $spm = $this->spm(1_000_000_00, 900_000_00);
        $this->trip($spm, [$spm['a']['id'], $spm['b']['id']])->assertUnprocessable()->assertJsonValidationErrors('reason_code');
        $trip = $this->trip($spm, [$spm['a']['id'], $spm['b']['id']], null, null, ['reason_code' => 'STRATEGIC_ACCOUNT'])->assertCreated()->json('data.trip');
        $this->assertSame('PENDING_APPROVAL', $trip['status']);
        $this->assertSame(1, $trip['required_level']);

        $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$trip['id']}/start")->assertStatus(409);
        $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$trip['id']}/approve")->assertForbidden();
        $this->as($this->supervisor)->postJson("/api/v1/spm-trips/{$trip['id']}/approve")->assertOk()->assertJsonPath('data.trip.status', 'PLANNED');
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenant, 'action' => 'spm_trip.margin_approved', 'subject_id' => $trip['id']]);
    }

    public function test_a_healthy_margin_needs_no_approval(): void
    {
        $spm = $this->spm(2_000_000_00, 1_000_000_00);
        $this->assertSame('PLANNED', $this->trip($spm, [$spm['a']['id']])->assertCreated()->json('data.trip.status'));
    }

    public function test_a_missing_price_is_a_warning_not_a_block(): void
    {
        $spm = $this->spm(0, 0);
        $r = $this->trip($spm, [$spm['a']['id']])->assertCreated()->json('data');
        $this->assertSame('PLANNED', $r['trip']['status']);
        $this->assertNotEmpty($r['warnings']);
    }

    public function test_stops_move_the_awbs_and_the_spm_and_complete_the_trips(): void
    {
        $spm = $this->spm();
        $t1 = $this->trip($spm, [$spm['a']['id']])->assertCreated()->json('data');
        $t2 = $this->trip($spm, [$spm['b']['id']], $this->m['v2'], $this->m['d2'])->assertCreated()->json('data');
        $id1 = $t1['trip']['id'];
        $id2 = $t2['trip']['id'];

        $this->record($id1, $this->stopId($t1, 1), 'arrive', now()->subHours(10)->toIso8601String())->assertStatus(409);
        $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$id1}/start")->assertOk()->assertJsonPath('data.trip.status', 'IN_TRANSIT');
        $this->record($id1, $this->stopId($t1, 2), 'arrive', now()->subHours(9)->toIso8601String())->assertStatus(409);
        $this->record($id1, $this->stopId($t1, 1), 'arrive', now()->addDay()->toIso8601String())->assertUnprocessable();

        $this->record($id1, $this->stopId($t1, 1), 'arrive', now()->subHours(12)->toIso8601String())->assertOk();
        $this->assertSame('LOADING', DB::table('transport_orders')->where('id', $spm['id'])->value('status'));
        $this->record($id1, $this->stopId($t1, 1), 'depart', now()->subHours(13)->toIso8601String())->assertUnprocessable();
        $this->record($id1, $this->stopId($t1, 1), 'depart', now()->subHours(11)->toIso8601String())->assertOk();
        $this->assertSame('IN_TRANSIT', DB::table('transport_orders')->where('id', $spm['id'])->value('status'));
        $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$id1}/complete")->assertUnprocessable();
        $this->record($id1, $this->stopId($t1, 2), 'arrive', now()->subHours(8)->toIso8601String())->assertOk();
        $this->record($id1, $this->stopId($t1, 2), 'depart', now()->subHours(7)->toIso8601String())->assertOk();
        $this->assertSame('DELIVERED', DB::table('awbs')->where('id', $spm['a']['id'])->value('status'));
        $this->assertSame('IN_TRANSIT', DB::table('transport_orders')->where('id', $spm['id'])->value('status'), 'the second AWB is still to go');
        $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$id1}/complete")->assertOk()->assertJsonPath('data.trip.status', 'COMPLETED');

        $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$id2}/start")->assertOk();
        $this->record($id2, $this->stopId($t2, 1), 'arrive', now()->subHours(6)->toIso8601String())->assertOk();
        $this->record($id2, $this->stopId($t2, 1), 'depart', now()->subHours(5)->toIso8601String())->assertOk();
        $this->record($id2, $this->stopId($t2, 2), 'arrive', now()->subHours(4)->toIso8601String())->assertOk();
        $this->record($id2, $this->stopId($t2, 2), 'depart', now()->subHours(3)->toIso8601String())->assertOk();
        $this->assertSame('DISCHARGED', DB::table('transport_orders')->where('id', $spm['id'])->value('status'));
        $this->assertSame('DELIVERED', DB::table('orders')->where('id', $spm['order'])->value('status'));
        $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$id2}/complete")->assertOk();
        $this->assertSame(0, DB::table('spm_trips')->where('tenant_id', $this->tenant)->where('active', true)->count(), 'vehicles and drivers are free again');
    }

    public function test_waiting_beyond_the_free_time_is_priced_as_detention_on_both_sides(): void
    {
        $spm = $this->spm();
        $t = $this->trip($spm, [$spm['a']['id']])->assertCreated()->json('data');
        $id = $t['trip']['id'];
        $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$id}/start")->assertOk();
        $this->record($id, $this->stopId($t, 1), 'arrive', now()->subHours(100)->toIso8601String())->assertOk();
        $this->record($id, $this->stopId($t, 1), 'depart', now()->subHours(99)->toIso8601String())->assertOk();
        $this->record($id, $this->stopId($t, 2), 'arrive', now()->subHours(98)->toIso8601String())->assertOk();
        $this->record($id, $this->stopId($t, 2), 'depart', now()->subHours(48)->toIso8601String())->assertOk();

        $charges = DB::table('spm_charges')->where('transport_order_id', $spm['id'])->where('charge_code_id', $this->m['DETENTION'])->orderBy('side')->get();
        $this->assertCount(2, $charges);
        $this->assertSame(['BUY', 'SELL'], $charges->pluck('side')->all());
        $this->assertEquals(2, $charges[0]->quantity, '50 hours minus 24 free is 26 hours, 2 started days');
        $this->assertSame(600_000_00, (int) $charges[0]->amount_minor);
        $this->assertSame(1_000_000_00, (int) $charges[1]->amount_minor);
        $this->assertNotNull($charges[0]->trip_stop_id);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenant, 'action' => 'spm.stop_charge']);
    }

    public function test_cancelling_a_trip_frees_its_awbs_and_resources(): void
    {
        $spm = $this->spm();
        $t = $this->trip($spm, [$spm['a']['id']])->assertCreated()->json('data');
        $id = $t['trip']['id'];
        $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$id}/cancel", ['reason' => 'x'])->assertUnprocessable();
        $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$id}/cancel", ['reason' => 'Kendaraan rusak'])->assertOk()->assertJsonPath('data.trip.status', 'CANCELLED');
        $this->assertSame('PENDING', DB::table('awbs')->where('id', $spm['a']['id'])->value('status'));
        $this->assertSame('CONFIRMED', DB::table('orders')->where('id', $spm['order'])->value('status'));
        $this->trip($spm, [$spm['a']['id']])->assertCreated();
    }

    public function test_the_drops_can_be_reordered_but_the_pickup_stays_first(): void
    {
        $spm = $this->spm();
        $t = $this->trip($spm, [$spm['a']['id'], $spm['b']['id']])->assertCreated()->json('data');
        $id = $t['trip']['id'];
        $this->assertSame(3, count($t['stops']));
        [$p, $d1, $d2] = array_column($t['stops'], 'id');
        $this->putJson("/api/v1/spm-trips/{$id}/stops", ['stop_ids' => [$d1, $p, $d2]])->assertUnprocessable();
        $r = $this->putJson("/api/v1/spm-trips/{$id}/stops", ['stop_ids' => [$p, $d2, $d1]])->assertOk()->json('data');
        $this->assertSame([$p, $d2, $d1], array_column($r['stops'], 'id'));
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $spm = $this->spm();
        $viewer = $this->member('viewer');
        $this->as($viewer)->getJson('/api/v1/spm-trips')->assertOk();
        $this->as($viewer)->postJson('/api/v1/spm-trips', ['spm_id' => $spm['id']])->assertForbidden();
        $this->as($viewer)->postJson("/api/v1/spm/{$spm['id']}/awbs", ['line_ids' => []])->assertForbidden();

        $t = $this->trip($spm, [$spm['a']['id']])->assertCreated()->json('data.trip.id');
        $other = User::factory()->create();
        $tenant2 = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $tenant2, 'slug' => 'other', 'name' => 'Other']);
        DB::table('memberships')->insert(['tenant_id' => $tenant2, 'user_id' => $other->id, 'role' => 'dispatcher', 'active' => true]);
        $this->actingAs($other)->withSession(['tenant_id' => $tenant2])->getJson("/api/v1/spm-trips/{$t}")->assertNotFound();
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Ledger\FeedCredentials;
use App\Domain\Master\MasterDefaults;
use App\Domain\Tracking\TrackingCredentials;
use App\Domain\Tracking\TrackingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** Route templates, vehicle reservations, schedule and monitoring, the re-open queue, geofence capture and the cash feed. */
class PlanningExtrasTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private User $dispatcher;

    private User $supervisor;

    private User $head;

    private array $m = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('dispatcher');
        MasterDefaults::seed($this->tenant);
        $this->dispatcher = auth()->user();
        $this->supervisor = $this->member('supervisor');
        $this->head = $this->member('head_of_logistics');
        $this->m['cust'] = $this->master('customers', ['code' => 'CUST', 'name' => 'PT Maju', 'payment_terms_days' => 30]);
        $this->m['pool'] = $this->master('locations', ['code' => 'POOL', 'name' => 'Pool Jakarta', 'type' => 'POOL', 'latitude' => -6.2, 'longitude' => 106.8, 'geofence_radius_m' => 200, 'radar_geofence_id' => 'GF-POOL']);
        $this->m['wh'] = $this->master('locations', ['code' => 'WH', 'name' => 'Gudang Bandung', 'type' => 'WAREHOUSE', 'latitude' => -6.9, 'longitude' => 107.6, 'geofence_radius_m' => 300]);
        $this->m['wh2'] = $this->master('locations', ['code' => 'WH2', 'name' => 'Gudang Cirebon', 'type' => 'WAREHOUSE']);
        $this->m['FREIGHT'] = DB::table('charge_codes')->where('tenant_id', $this->tenant)->where('code', 'FREIGHT')->value('id');
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

    private function vehicle(string $plate): string
    {
        $id = (string) Str::uuid();
        DB::table('vehicles')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'plate' => $plate, 'type' => 'Box', 'capacity_g' => 1_000_000, 'length_mm' => 4000, 'width_mm' => 2000, 'height_mm' => 2000, 'status' => 'AVAILABLE', 'documents_valid_until' => now()->addYear()->toDateString(), 'readiness_checked_at' => now()]);

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

    /** An SPM with two lines: AWB 1 to Bandung, AWB 2 to Cirebon, planned and priced from a BUY and a SELL tariff. */
    private function spm(): array
    {
        foreach (['SELL' => 2_000_000_00, 'BUY' => 1_000_000_00] as $side => $price) {
            $t = $this->as($this->dispatcher)->postJson('/api/v1/tariffs', ['code' => 'T'.$side.Str::random(4), 'name' => 'Tarif', 'side' => $side, 'valid_from' => '2026-01-01'])->assertCreated()->json('data.id');
            $this->as($this->dispatcher)->postJson("/api/v1/tariffs/{$t}/lines", ['charge_code_id' => $this->m['FREIGHT'], 'basis' => 'TRIP', 'unit_price_minor' => $price])->assertCreated();
            $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$t}/approve")->assertOk();
        }
        $order = $this->as($this->dispatcher)->postJson('/api/v1/orders', [
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'customer_id' => $this->m['cust'],
            'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'],
            'items' => [['description' => 'Palet', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]],
        ], $this->key())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/orders/{$order}/transition", ['action' => 'confirm'])->assertOk();
        $spm = $this->postJson("/api/v1/orders/{$order}/spm", ['lines' => [['material' => 'Paket A', 'quantity' => 1, 'weight_kg' => 100], ['material' => 'Paket B', 'quantity' => 1, 'weight_kg' => 100]]])->assertCreated()->json('data');
        $a = $this->postJson("/api/v1/spm/{$spm['spm']['id']}/awbs", ['line_ids' => [$spm['lines'][0]['id']], 'destination_location_id' => $this->m['wh']])->assertCreated()->json('data');
        $b = $this->postJson("/api/v1/spm/{$spm['spm']['id']}/awbs", ['line_ids' => [$spm['lines'][1]['id']], 'destination_location_id' => $this->m['wh2']])->assertCreated()->json('data');
        $this->postJson("/api/v1/spm/{$spm['spm']['id']}/status", ['to' => 'PLANNED'])->assertOk();

        return ['id' => $spm['spm']['id'], 'a' => $a, 'b' => $b];
    }

    private function trip(array $spm, array $awbs, array $o = [])
    {
        return $this->as($this->dispatcher)->postJson('/api/v1/spm-trips', $o + [
            'spm_id' => $spm['id'], 'awb_ids' => $awbs, 'vehicle_id' => $this->m['v1'], 'driver_id' => $this->m['d1'],
            'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(5)->toIso8601String(),
        ]);
    }

    // ---------------------------------------------------------------- route templates

    public function test_a_route_template_gives_the_order_and_times_of_the_stops(): void
    {
        $tpl = $this->as($this->dispatcher)->postJson('/api/v1/route-templates', ['code' => 'jkt-bdg-cir', 'name' => 'Jakarta-Bandung-Cirebon', 'stops' => [
            ['location_id' => $this->m['pool'], 'type' => 'PICKUP', 'offset_minutes' => 0],
            ['location_id' => $this->m['wh2'], 'type' => 'DROP', 'offset_minutes' => 120],
            ['location_id' => $this->m['wh'], 'type' => 'DROP', 'offset_minutes' => 240],
        ]])->assertCreated()->json('data');
        $this->assertSame('JKT-BDG-CIR', $tpl['template']['code']);
        $this->assertCount(3, $tpl['stops']);

        $spm = $this->spm();
        $start = now()->addHour();
        $r = $this->trip($spm, [$spm['a']['id'], $spm['b']['id']], ['route_template_id' => $tpl['template']['id'], 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHours(6)->toIso8601String()])->assertCreated()->json('data');
        $this->assertSame(['Pool Jakarta', 'Gudang Cirebon', 'Gudang Bandung'], array_column($r['stops'], 'location_label'), 'drops follow the template');
        $this->assertSame(120, (int) round((strtotime($r['stops'][1]['planned_to']) - $start->timestamp) / 60));
    }

    public function test_template_rules_and_saving_one_from_a_trip(): void
    {
        $bad = fn (array $stops) => $this->as($this->dispatcher)->postJson('/api/v1/route-templates', ['code' => 'X', 'name' => 'X', 'stops' => $stops]);
        $bad([['location_id' => $this->m['wh'], 'type' => 'DROP'], ['location_id' => $this->m['pool'], 'type' => 'PICKUP']])->assertUnprocessable()->assertJsonValidationErrors('stops');
        $bad([['location_id' => $this->m['pool'], 'type' => 'PICKUP', 'offset_minutes' => 60], ['location_id' => $this->m['wh'], 'type' => 'DROP', 'offset_minutes' => 30]])->assertUnprocessable();
        $bad([['location_id' => $this->m['pool'], 'type' => 'PICKUP']])->assertUnprocessable();

        $spm = $this->spm();
        $t = $this->trip($spm, [$spm['a']['id']])->assertCreated()->json('data.trip.id');
        $saved = $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$t}/save-template", ['code' => 'rute-a', 'name' => 'Rute A'])->assertCreated()->json('data');
        $this->assertSame(['PICKUP', 'DROP'], array_column($saved['stops'], 'type'));
        $this->as($this->dispatcher)->postJson("/api/v1/route-templates/{$saved['template']['id']}/status", ['active' => false])->assertOk();
        $this->assertCount(0, $this->getJson('/api/v1/route-templates?active=1')->json('data'));
        $second = $this->spm();
        $this->trip($second, [$second['a']['id']], ['route_template_id' => $saved['template']['id'], 'vehicle_id' => $this->m['v2'], 'driver_id' => $this->m['d2']])->assertUnprocessable();
        $viewer = $this->member('viewer');
        $this->as($viewer)->postJson('/api/v1/route-templates', [])->assertForbidden();
    }

    // ---------------------------------------------------------------- reservations

    public function test_a_booking_holds_the_vehicle_and_is_used_up_by_its_trip(): void
    {
        $spm = $this->spm();
        $start = now()->addHours(2);
        $body = ['vehicle_id' => $this->m['v1'], 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHours(6)->toIso8601String(), 'purpose' => 'Cadangan proyek'];
        $booking = $this->as($this->dispatcher)->postJson('/api/v1/reservations', $body)->assertCreated()->json('data');
        $this->assertSame('BOOKED', $booking['status']);

        $this->as($this->dispatcher)->postJson('/api/v1/reservations', array_replace($body, ['starts_at' => $start->copy()->addHours(3)->toIso8601String(), 'ends_at' => $start->copy()->addHours(9)->toIso8601String()]))->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
        $this->as($this->dispatcher)->postJson('/api/v1/reservations', array_replace($body, ['vehicle_id' => $this->m['v2'], 'driver_id' => $this->m['d1'], 'starts_at' => $start->copy()->addHour()->toIso8601String()]))->assertCreated();
        $this->as($this->dispatcher)->postJson('/api/v1/reservations', array_replace($body, ['starts_at' => $start->copy()->addHours(9)->toIso8601String(), 'ends_at' => $start->copy()->addHours(12)->toIso8601String()]))->assertCreated();

        $this->trip($spm, [$spm['a']['id']], ['driver_id' => $this->m['d2'], 'starts_at' => $start->copy()->addHour()->toIso8601String(), 'ends_at' => $start->copy()->addHours(4)->toIso8601String()])->assertStatus(409);
        $made = $this->trip($spm, [$spm['a']['id']], ['driver_id' => $this->m['d2'], 'reservation_id' => $booking['id'], 'starts_at' => $start->copy()->addHour()->toIso8601String(), 'ends_at' => $start->copy()->addHours(4)->toIso8601String()])->assertCreated()->json('data.trip');
        $row = DB::table('vehicle_reservations')->where('id', $booking['id'])->first();
        $this->assertSame('CONVERTED', $row->status);
        $this->assertSame($made['id'], $row->trip_id);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenant, 'action' => 'reservation.converted', 'subject_id' => $booking['id']]);
    }

    public function test_a_booking_made_for_the_spm_is_picked_up_automatically_and_can_be_cancelled(): void
    {
        $spm = $this->spm();
        $start = now()->addHours(2);
        $booking = $this->as($this->dispatcher)->postJson('/api/v1/reservations', ['vehicle_id' => $this->m['v1'], 'transport_order_id' => $spm['id'], 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHours(6)->toIso8601String()])->assertCreated()->json('data.id');
        $this->trip($spm, [$spm['a']['id']], ['starts_at' => $start->copy()->addHour()->toIso8601String(), 'ends_at' => $start->copy()->addHours(4)->toIso8601String()])->assertCreated();
        $this->assertSame('CONVERTED', DB::table('vehicle_reservations')->where('id', $booking)->value('status'));

        $other = $this->as($this->dispatcher)->postJson('/api/v1/reservations', ['vehicle_id' => $this->m['v2'], 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHours(2)->toIso8601String()])->assertCreated()->json('data.id');
        $this->as($this->dispatcher)->postJson("/api/v1/reservations/{$other}/cancel", ['reason' => 'x'])->assertUnprocessable();
        $this->as($this->dispatcher)->postJson("/api/v1/reservations/{$other}/cancel", ['reason' => 'Tidak jadi dipakai'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->as($this->dispatcher)->postJson("/api/v1/reservations/{$booking}/cancel", ['reason' => 'Sudah dipakai trip'])->assertStatus(409);
    }

    // ---------------------------------------------------------------- schedule and monitoring

    public function test_the_schedule_shows_trips_dispatches_and_bookings_per_vehicle(): void
    {
        $spm = $this->spm();
        $this->trip($spm, [$spm['a']['id']])->assertCreated();
        $start = now()->addDays(2);
        $this->as($this->dispatcher)->postJson('/api/v1/reservations', ['vehicle_id' => $this->m['v2'], 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHours(5)->toIso8601String()])->assertCreated();

        $r = $this->getJson('/api/v1/schedule?from='.urlencode(now()->startOfDay()->toIso8601String()).'&to='.urlencode(now()->addDays(7)->toIso8601String()))->assertOk()->json('data');
        $this->assertCount(2, $r['vehicles']);
        $this->assertEqualsCanonicalizing(['TRIP', 'BOOKING'], array_column($r['items'], 'kind'));
        $this->getJson('/api/v1/schedule?from=2026-01-01&to=2026-12-31')->assertUnprocessable();
    }

    public function test_monitoring_flags_what_needs_attention(): void
    {
        $spm = $this->spm();
        $a = $this->trip($spm, [$spm['a']['id']])->assertCreated()->json('data.trip.id');
        DB::table('spm_trips')->where('id', $a)->update(['starts_at' => now()->subHours(3), 'ends_at' => now()->subHour()]);
        $r = $this->getJson('/api/v1/monitoring')->assertOk()->json('data');
        $this->assertSame(1, $r['counts']['PLANNED']);
        $this->assertSame(1, $r['counts']['LATE']);
        $this->assertContains('NOT_STARTED', $r['trips'][0]['flags']);
        $this->assertSame(1, $r['unplanned_awbs']);

        $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$a}/start")->assertOk();
        $r = $this->getJson('/api/v1/monitoring')->json('data');
        $this->assertContains('LATE', $r['trips'][0]['flags']);
        $this->assertSame('PICKUP', $r['trips'][0]['current_stop']['type']);
    }

    // ---------------------------------------------------------------- re-open queue

    private function discharged(): string
    {
        $spm = $this->spm();
        foreach (['LOADING', 'IN_TRANSIT', 'DISCHARGED'] as $to) {
            $this->postJson("/api/v1/spm/{$spm['id']}/status", ['to' => $to])->assertOk();
        }

        return $spm['id'];
    }

    public function test_a_reopen_needs_a_request_decided_by_someone_else(): void
    {
        $id = $this->discharged();
        $this->as($this->supervisor)->postJson("/api/v1/spm/{$id}/status", ['to' => 'REOPENED', 'reason' => 'Langsung dibuka'])->assertUnprocessable();
        $this->as($this->dispatcher)->postJson("/api/v1/spm/{$id}/reopen-requests", ['reason' => 'x'])->assertUnprocessable();
        $req = $this->as($this->supervisor)->postJson("/api/v1/spm/{$id}/reopen-requests", ['reason' => 'Berat timbang salah dicatat'])->assertCreated()->json('data.id');
        $this->as($this->dispatcher)->postJson("/api/v1/spm/{$id}/reopen-requests", ['reason' => 'Permintaan kedua'])->assertStatus(409);

        $this->assertSame(1, $this->as($this->head)->getJson('/api/v1/workqueue')->json('data.reopen_requests'));
        $this->as($this->dispatcher)->postJson("/api/v1/spm-reopen-requests/{$req}/approve")->assertForbidden();
        $this->as($this->supervisor)->postJson("/api/v1/spm-reopen-requests/{$req}/approve")->assertForbidden();
        $queue = $this->as($this->head)->getJson('/api/v1/spm-reopen-requests?status=PENDING')->assertOk()->json('data');
        $this->assertSame('Berat timbang salah dicatat', $queue[0]['reason']);

        $this->as($this->head)->postJson("/api/v1/spm-reopen-requests/{$req}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->assertSame('REOPENED', DB::table('transport_orders')->where('id', $id)->value('status'));
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenant, 'action' => 'spm.reopen_approved', 'subject_id' => $req]);
        $this->as($this->head)->postJson("/api/v1/spm-reopen-requests/{$req}/approve")->assertStatus(409);
    }

    public function test_a_reopen_request_can_be_rejected_with_a_note_or_withdrawn(): void
    {
        $id = $this->discharged();
        $req = $this->as($this->dispatcher)->postJson("/api/v1/spm/{$id}/reopen-requests", ['reason' => 'Salah input segel'])->assertCreated()->json('data.id');
        $this->as($this->head)->postJson("/api/v1/spm-reopen-requests/{$req}/reject", ['note' => 'x'])->assertUnprocessable();
        $this->as($this->head)->postJson("/api/v1/spm-reopen-requests/{$req}/reject", ['note' => 'Cukup dengan catatan koreksi'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $this->assertSame('DISCHARGED', DB::table('transport_orders')->where('id', $id)->value('status'));

        $again = $this->as($this->dispatcher)->postJson("/api/v1/spm/{$id}/reopen-requests", ['reason' => 'Ajukan ulang dengan bukti'])->assertCreated()->json('data.id');
        $this->as($this->supervisor)->postJson("/api/v1/spm-reopen-requests/{$again}/cancel")->assertForbidden();
        $this->as($this->dispatcher)->postJson("/api/v1/spm-reopen-requests/{$again}/cancel")->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->postJson("/api/v1/spm/{$this->spm()['id']}/reopen-requests", ['reason' => 'Belum selesai bongkar'])->assertStatus(409);
    }

    public function test_an_approval_after_the_status_moved_on_is_refused(): void
    {
        $id = $this->discharged();
        $req = $this->as($this->dispatcher)->postJson("/api/v1/spm/{$id}/reopen-requests", ['reason' => 'Perlu dikoreksi'])->assertCreated()->json('data.id');
        $this->as($this->supervisor)->postJson("/api/v1/spm/{$id}/status", ['to' => 'DOC_VERIFIED'])->assertOk();
        $this->as($this->head)->postJson("/api/v1/spm-reopen-requests/{$req}/approve")->assertStatus(409);
    }

    // ---------------------------------------------------------------- geofence

    private function runningTrip(): array
    {
        $spm = $this->spm();
        $trip = $this->trip($spm, [$spm['a']['id']])->assertCreated()->json('data');
        $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$trip['trip']['id']}/start")->assertOk();

        return ['id' => $trip['trip']['id'], 'spm' => $spm['id'], 'stops' => $trip['stops']];
    }

    private function token(): string
    {
        return app(TrackingCredentials::class)->issue($this->tenant, 'GPS', $this->supervisor->id)['token'];
    }

    private function ping(string $token, float $lat, float $lon, ?string $at = null)
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/integration/tracking/positions', ['plate' => 'B 1111 AA', 'latitude' => $lat, 'longitude' => $lon, 'at' => $at]);
    }

    public function test_positions_fill_in_the_stop_times_by_geofence(): void
    {
        $trip = $this->runningTrip();
        $token = $this->token();

        $far = $this->ping($token, -6.5, 107.0)->assertOk()->json('data');
        $this->assertFalse($far['applied']);
        $enter = $this->ping($token, -6.2005, 106.8003, now()->subMinutes(30)->toIso8601String())->assertOk()->json('data');
        $this->assertTrue($enter['applied']);
        $this->assertSame('ENTER', $enter['action']);
        $this->assertSame('LOADING', DB::table('transport_orders')->where('id', $trip['spm'])->value('status'));
        $this->assertSame('GEOFENCE', DB::table('spm_trip_stops')->where('id', $trip['stops'][0]['id'])->value('time_source'));

        $this->assertFalse($this->ping($token, -6.2002, 106.8001)->json('data.applied'), 'still inside');
        $exit = $this->ping($token, -6.25, 106.9, now()->subMinutes(10)->toIso8601String())->json('data');
        $this->assertSame('EXIT', $exit['action']);
        $this->assertSame('IN_TRANSIT', DB::table('transport_orders')->where('id', $trip['spm'])->value('status'));

        $this->ping($token, -6.9005, 107.6003, now()->subMinutes(5)->toIso8601String())->assertOk();
        $this->ping($token, -6.95, 107.7, now()->subMinute()->toIso8601String())->assertOk();
        $this->assertSame('DELIVERED', DB::table('awbs')->where('id', DB::table('awbs')->where('trip_id', $trip['id'])->value('id'))->value('status'));
        $this->assertSame(4, DB::table('stop_events')->where('trip_id', $trip['id'])->where('applied', true)->count());
        $this->assertFalse($this->ping($token, -6.9, 107.6)->json('data.applied'), 'nothing left to capture');
        $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$trip['id']}/complete")->assertOk();
    }

    public function test_a_stop_without_coordinates_and_a_vehicle_not_on_a_trip_are_not_captured(): void
    {
        $token = $this->token();
        $this->assertStringContainsString('tidak sedang', $this->ping($token, -6.2, 106.8)->assertOk()->json('data.reason'));
        $spm = $this->spm();
        $t = $this->trip($spm, [$spm['b']['id']])->assertCreated()->json('data');
        $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$t['trip']['id']}/start")->assertOk();
        $this->assertTrue($this->ping($token, -6.2, 106.8)->json('data.applied'), 'the pickup has coordinates');
        $this->ping($token, -6.5, 107.0)->assertOk();
        $drop = $this->ping($token, -7.0, 108.0)->json('data');
        $this->assertFalse($drop['applied']);
    }

    public function test_an_external_geofence_engine_can_post_enter_and_exit(): void
    {
        $trip = $this->runningTrip();
        $token = $this->token();
        $post = fn (array $b) => $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/integration/tracking/events', ['plate' => 'B 1111 AA'] + $b);

        $this->assertFalse($post(['event' => 'ENTER', 'geofence_id' => 'GF-NOPE'])->assertOk()->json('data.applied'));
        $this->assertFalse($post(['event' => 'ENTER', 'location_id' => $this->m['wh']])->json('data.applied'), 'not the next stop');
        $enter = $post(['event' => 'ENTER', 'geofence_id' => 'GF-POOL', 'at' => now()->subMinutes(20)->toIso8601String()]);
        $this->assertTrue($enter->json('data.applied') ?? false, $enter->getContent());
        $this->assertSame('RADAR', DB::table('spm_trip_stops')->where('id', $trip['stops'][0]['id'])->value('time_source'));
        $this->assertFalse($post(['event' => 'ENTER', 'geofence_id' => 'GF-POOL'])->json('data.applied'), 'duplicate');
        $this->assertTrue($post(['event' => 'EXIT', 'geofence_id' => 'GF-POOL', 'at' => now()->subMinutes(10)->toIso8601String()])->json('data.applied'));
        $this->withoutHeader('Authorization')->postJson('/api/v1/integration/tracking/events', [])->assertUnauthorized();
    }

    public function test_the_tracking_credential_is_checked_and_scoped_to_its_tenant(): void
    {
        $this->withHeader('Authorization', 'Bearer odtk_nope')->postJson('/api/v1/integration/tracking/positions', [])->assertUnauthorized();
        $token = $this->token();
        $id = DB::table('tracking_credentials')->value('id');
        app(TrackingCredentials::class)->revoke($id);
        $this->ping($token, -6.2, 106.8)->assertUnauthorized();

        $other = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $other, 'slug' => 'other', 'name' => 'Other']);
        $this->assertNotNull(app(TrackingCredentials::class)->issue($other, 'GPS', $this->supervisor->id)['token']);
    }

    public function test_an_operator_can_try_a_position_and_it_is_recorded_as_manual(): void
    {
        $trip = $this->runningTrip();
        $r = $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$trip['id']}/position", ['latitude' => -6.2, 'longitude' => 106.8])->assertOk()->json('data');
        $this->assertTrue($r['applied']);
        $this->assertSame('MANUAL', DB::table('spm_trip_stops')->where('id', $trip['stops'][0]['id'])->value('time_source'));
        $signals = $this->getJson("/api/v1/spm-trips/{$trip['id']}/signals")->assertOk()->json('data');
        $this->assertSame('ENTER', $signals[0]['event']);
        $viewer = $this->member('viewer');
        $this->as($viewer)->postJson("/api/v1/spm-trips/{$trip['id']}/position", ['latitude' => -6.2, 'longitude' => 106.8])->assertForbidden();
    }

    public function test_distance_between_two_points(): void
    {
        $this->assertEqualsWithDelta(111_195, TrackingService::distanceMeters(0, 0, 1, 0), 200);
        $this->assertSame(0, TrackingService::distanceMeters(-6.2, 106.8, -6.2, 106.8));
    }

    // ---------------------------------------------------------------- cash feed

    public function test_driver_cash_is_published_to_the_feed_as_facts(): void
    {
        $finance = $this->member('finance');
        $spm = $this->spm();
        $trip = $this->trip($spm, [$spm['a']['id']])->assertCreated()->json('data.trip.id');
        $box = $this->as($finance)->postJson('/api/v1/cash/cashboxes', ['code' => 'kas', 'name' => 'Kas utama'])->assertCreated()->json('data.id');
        $this->as($finance)->postJson("/api/v1/cash/cashboxes/{$box}/topups", ['amount_minor' => 5_000_000_00])->assertCreated();
        $toll = DB::table('charge_codes')->where('tenant_id', $this->tenant)->where('code', 'TOLL')->value('id');
        $v = $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$trip}/cash-vouchers", ['lines' => [['charge_code_id' => $toll, 'requested_minor' => 300_000_00, 'reason' => 'Tol pergi-pulang']]])->assertCreated()->json('data');
        $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$v['voucher']['id']}/approve")->assertOk();
        $this->as($finance)->postJson("/api/v1/cash/vouchers/{$v['voucher']['id']}/disburse", ['cashbox_id' => $box])->assertOk();
        $line = $this->getJson("/api/v1/cash/vouchers/{$v['voucher']['id']}")->json('data.lines.0.id');
        $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$v['voucher']['id']}/settle", ['lines' => [['id' => $line, 'actual_minor' => 280_000_00, 'receipt_ref' => 'Struk tol']], 'returned_minor' => 20_000_00])->assertOk();

        $token = app(FeedCredentials::class)->issue($this->tenant, 'optientry', 'Entry', 'test')['token'];
        $feed = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/integration/feed')->assertOk()->json('data');
        $types = array_column($feed, 'event_type');
        $this->assertSame(['optideli.cash.disbursed', 'optideli.cash.settled'], array_values(array_filter($types, fn ($t) => str_starts_with($t, 'optideli.cash.'))));
        $this->assertSame(range(1, count($feed)), array_column($feed, 'sequence'));

        $disbursed = collect($feed)->firstWhere('event_type', 'optideli.cash.disbursed');
        $this->assertSame(300_000_00, $disbursed['payload']['amount_minor']);
        $this->assertSame('TOLL', $disbursed['payload']['lines'][0]['fact_key']);
        $this->assertSame($v['voucher']['id'], $disbursed['correlation_id']);
        $this->assertSame($trip, $disbursed['dimensions']['trip_id']);
        $this->assertSame('KAS', $disbursed['payload']['cashbox']['code']);
        $settled = collect($feed)->firstWhere('event_type', 'optideli.cash.settled');
        $this->assertSame(280_000_00, $settled['payload']['actual_minor']);
        $this->assertSame(20_000_00, $settled['payload']['returned_minor']);
        $this->assertSame('Struk tol', $settled['payload']['lines'][0]['receipt_ref']);
        $this->assertArrayNotHasKey('account', $settled['payload']);
    }
}

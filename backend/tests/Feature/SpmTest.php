<?php

namespace Tests\Feature;

use App\Domain\Master\MasterDefaults;
use App\Domain\Shared\TenantStore;
use App\Domain\Spm\DocumentNumbers;
use App\Domain\Spm\SpmService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** The SPM (transport order): creation from an order, lines, status flow, tariff snapshot, destination change, charges (F2/M3). */
class SpmTest extends TestCase
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
        $this->supervisor = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $this->supervisor->id, 'role' => 'supervisor', 'active' => true]);
        $this->m['cust'] = $this->master('customers', ['code' => 'CUST', 'name' => 'PT Maju', 'payment_terms_days' => 30]);
        $this->m['pool'] = $this->master('locations', ['code' => 'POOL', 'name' => 'Pool Jakarta', 'type' => 'POOL']);
        $this->m['wh'] = $this->master('locations', ['code' => 'WH', 'name' => 'Gudang Bandung', 'type' => 'WAREHOUSE']);
        $this->m['wh2'] = $this->master('locations', ['code' => 'WH2', 'name' => 'Gudang Cirebon', 'type' => 'WAREHOUSE']);
        $this->m['cde'] = $this->master('carrier_types', ['code' => 'CDE', 'name' => 'CDE']);
        $this->m['FREIGHT'] = DB::table('charge_codes')->where('tenant_id', $this->tenant)->where('code', 'FREIGHT')->value('id');
        $this->m['LOADING'] = DB::table('charge_codes')->where('tenant_id', $this->tenant)->where('code', 'LOADING')->value('id');
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

    private function order(bool $confirm = true): string
    {
        $id = $this->as($this->dispatcher)->postJson('/api/v1/orders', [
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'customer_id' => $this->m['cust'],
            'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'],
            'items' => [['description' => 'Palet', 'quantity' => 4, 'unit_weight_g' => 25000, 'length_mm' => 1000, 'width_mm' => 1000, 'height_mm' => 1000, 'stackable' => true]],
        ], $this->key())->assertCreated()->json('data.id');
        if ($confirm) {
            $this->postJson("/api/v1/orders/{$id}/transition", ['action' => 'confirm'])->assertOk();
        }

        return $id;
    }

    private function spm(?string $order = null, array $o = []): array
    {
        return $this->as($this->dispatcher)->postJson('/api/v1/orders/'.($order ?? $this->order()).'/spm', $o)->assertCreated()->json('data');
    }

    private function move(array $spm, string $to, ?User $as = null, ?string $reason = null)
    {
        return $this->as($as ?? $this->dispatcher)->postJson("/api/v1/spm/{$spm['spm']['id']}/status", ['to' => $to, 'reason' => $reason]);
    }

    private function approvedTariff(string $side, int $price, array $extra = []): void
    {
        $t = $this->as($this->dispatcher)->postJson('/api/v1/tariffs', $extra + ['code' => 'T'.Str::random(5), 'name' => 'Tarif', 'side' => $side, 'valid_from' => '2026-01-01'])->assertCreated()->json('data.id');
        $this->as($this->dispatcher)->postJson("/api/v1/tariffs/{$t}/lines", ['charge_code_id' => $this->m['FREIGHT'], 'basis' => 'TRIP', 'unit_price_minor' => $price])->assertCreated();
        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$t}/approve")->assertOk();
    }

    public function test_an_spm_is_made_from_a_confirmed_order_with_lines_copied_from_its_items(): void
    {
        $spm = $this->spm();
        $this->assertMatchesRegularExpression('/^SPM-\d{6}-00001$/', $spm['spm']['number']);
        $this->assertSame('DRAFT', $spm['spm']['status']);
        $this->assertSame(1, $spm['spm']['sequence']);
        $this->assertSame('Pool Jakarta', $spm['spm']['origin_text']);
        $this->assertSame($this->m['wh'], $spm['spm']['destination_location_id']);
        $this->assertCount(1, $spm['lines']);
        $this->assertSame('Palet', $spm['lines'][0]['material']);
        $this->assertEquals(100, $spm['lines'][0]['weight_kg']);
        $this->assertEquals(4, $spm['lines'][0]['volume_cbm']);
        $this->assertSame('DRAFT', $spm['history'][0]['to_status']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenant, 'action' => 'spm.created', 'subject_id' => $spm['spm']['id']]);

        $this->assertNotSame($spm['spm']['number'], $this->spm()['spm']['number']);
    }

    public function test_an_order_can_be_split_into_several_spms(): void
    {
        $order = $this->order();
        $a = $this->spm($order, ['lines' => [['material' => 'Bagian A', 'quantity' => 2]]]);
        $b = $this->spm($order, ['lines' => [['material' => 'Bagian B', 'quantity' => 2, 'po_number' => 'PO-9']], 'destination_location_id' => $this->m['wh2']]);
        $this->assertSame([1, 2], [$a['spm']['sequence'], $b['spm']['sequence']]);
        $this->assertSame('Gudang Cirebon', $b['spm']['destination_text']);
        $this->assertCount(2, $this->getJson("/api/v1/spm?order_id={$order}")->json('data'));
        $this->assertCount(2, $this->getJson("/api/v1/spm/{$a['spm']['id']}")->json('data.siblings'));
    }

    public function test_numbers_are_gap_free_and_a_failed_spm_returns_its_number(): void
    {
        $order = $this->order();
        $first = $this->spm($order);
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$order}/spm", ['lines' => []])->assertUnprocessable();
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$order}/spm", ['carrier_type_id' => (string) Str::uuid()])->assertUnprocessable();
        $next = $this->spm($order);
        $this->assertSame(substr($first['spm']['number'], 0, -5).'00002', $next['spm']['number']);

        DB::beginTransaction();
        $n = DocumentNumbers::next($this->tenant, 'SPM', '209901');
        DB::rollBack();
        $this->assertSame(1, $n);
        $this->assertSame(1, DocumentNumbers::next($this->tenant, 'SPM', '209901'));
    }

    public function test_only_a_confirmed_order_can_have_an_spm(): void
    {
        $this->as($this->dispatcher)->postJson('/api/v1/orders/'.$this->order(false).'/spm')->assertStatus(409);
    }

    public function test_the_status_flow_is_enforced_with_reasons_and_permissions(): void
    {
        $spm = $this->spm();
        $this->move($spm, 'LOADING')->assertStatus(409);
        $this->move($spm, 'PLANNED')->assertOk()->assertJsonPath('data.spm.spm.status', 'PLANNED');
        $this->move($spm, 'LOADING')->assertOk();
        $this->move($spm, 'IN_TRANSIT')->assertOk();
        $this->move($spm, 'DISCHARGED')->assertOk();
        $this->move($spm, 'DOC_VERIFIED')->assertForbidden();
        $this->move($spm, 'DOC_VERIFIED', $this->supervisor)->assertOk();
        $this->move($spm, 'CLOSED')->assertOk();

        // Nobody re-opens directly: it goes through the request queue, decided by someone else.
        $this->move($spm, 'REOPENED', $this->supervisor, 'Nota bongkar salah')->assertUnprocessable();
        $request = $this->as($this->dispatcher)->postJson("/api/v1/spm/{$spm['spm']['id']}/reopen-requests", ['reason' => 'Nota bongkar salah'])->assertCreated()->json('data.id');
        $this->as($this->supervisor)->postJson("/api/v1/spm-reopen-requests/{$request}/approve")->assertOk();
        $this->assertSame('REOPENED', DB::table('transport_orders')->where('id', $spm['spm']['id'])->value('status'));
        $this->move($spm, 'DOC_VERIFIED', $this->supervisor)->assertOk();

        $history = $this->getJson("/api/v1/spm/{$spm['spm']['id']}")->json('data.history');
        $this->assertSame(['DRAFT', 'PLANNED', 'LOADING', 'IN_TRANSIT', 'DISCHARGED', 'DOC_VERIFIED', 'CLOSED', 'REOPENED', 'DOC_VERIFIED'], array_column($history, 'to_status'));
        $this->assertSame('Nota bongkar salah', $history[7]['reason']);
    }

    public function test_cancelling_needs_a_reason_and_ends_the_flow(): void
    {
        $spm = $this->spm();
        $this->move($spm, 'CANCELLED')->assertUnprocessable();
        $this->move($spm, 'CANCELLED', null, 'Pelanggan membatalkan')->assertOk();
        $this->move($spm, 'PLANNED')->assertStatus(409);
        $started = $this->spm();
        $this->move($started, 'PLANNED')->assertOk();
        $this->move($started, 'LOADING')->assertOk();
        $this->move($started, 'CANCELLED', null, 'Sudah memuat')->assertStatus(409);
    }

    public function test_planning_needs_locations_and_prices_both_sides_from_the_tariffs(): void
    {
        $this->approvedTariff('SELL', 2_000_000_00);
        $this->approvedTariff('BUY', 1_500_000_00);
        $spm = $this->spm(null, ['carrier_type_id' => $this->m['cde'], 'planned_pickup_at' => '2026-06-01T08:00:00+07:00']);
        $this->assertSame([], $spm['charges']);

        $planned = $this->move($spm, 'PLANNED')->assertOk()->json('data.spm');
        $this->assertCount(2, $planned['charges']);
        $this->assertSame(['BUY', 'SELL'], array_column($planned['charges'], 'side'));
        $this->assertSame(2_000_000_00, $planned['totals']['sell_minor']);
        $this->assertSame(1_500_000_00, $planned['totals']['buy_minor']);
        $this->assertSame(500_000_00, $planned['totals']['margin_minor']);
        $this->assertSame('PLAN', $planned['charges'][0]['kind']);
        $this->assertSame('FREIGHT · Ongkos angkut', $planned['charges'][0]['charge_code_label']);

        // A later tariff change does not touch the snapshot.
        $this->approvedTariff('SELL', 9_000_000_00, ['customer_id' => $this->m['cust']]);
        $this->assertSame(2_000_000_00, $this->getJson("/api/v1/spm/{$spm['spm']['id']}")->json('data.totals.sell_minor'));
    }

    public function test_an_spm_without_a_tariff_is_planned_with_a_warning(): void
    {
        $spm = $this->spm();
        $r = $this->move($spm, 'PLANNED')->assertOk()->json('data');
        $this->assertNotEmpty($r['warnings']);
        $this->assertSame([], $r['spm']['charges']);
    }

    public function test_planning_needs_both_locations(): void
    {
        $order = $this->as($this->dispatcher)->postJson('/api/v1/orders', [
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'customer_name' => 'Teks Bebas',
            'origin' => 'Jakarta', 'destination' => 'Bandung', 'items' => [['description' => 'Palet', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]],
        ], $this->key())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/orders/{$order}/transition", ['action' => 'confirm'])->assertOk();
        $spm = $this->spm($order);
        $this->assertSame('Jakarta', $spm['spm']['origin_text']);
        $this->move($spm, 'PLANNED')->assertUnprocessable()->assertJsonValidationErrors('origin_location_id');
    }

    public function test_lines_are_free_in_a_draft_and_only_actuals_after_loading(): void
    {
        $spm = $this->spm();
        $id = $spm['spm']['id'];
        $line = $this->as($this->dispatcher)->postJson("/api/v1/spm/{$id}/lines", ['material' => 'Karton', 'quantity' => 10, 'po_number' => 'PO-1', 'weight_kg' => 50])->assertCreated()->json('data');
        $this->assertSame(2, $line['line_no']);
        $this->as($this->dispatcher)->putJson("/api/v1/spm/{$id}/lines/{$line['id']}", ['quantity' => 12])->assertOk();
        $this->as($this->dispatcher)->deleteJson("/api/v1/spm/{$id}/lines/{$line['id']}")->assertNoContent();
        $this->as($this->dispatcher)->deleteJson("/api/v1/spm/{$id}/lines/{$spm['lines'][0]['id']}")->assertUnprocessable();

        $this->move($spm, 'PLANNED')->assertOk();
        $this->as($this->dispatcher)->postJson("/api/v1/spm/{$id}/lines", ['material' => 'Lagi', 'quantity' => 1])->assertStatus(409);
        $this->as($this->dispatcher)->putJson("/api/v1/spm/{$id}/lines/{$spm['lines'][0]['id']}", ['seal_number' => 'S1'])->assertStatus(409);
        $this->move($spm, 'LOADING');
        $this->as($this->dispatcher)->putJson("/api/v1/spm/{$id}/lines/{$spm['lines'][0]['id']}", ['seal_number' => 'S1', 'damage_qty' => 1])->assertOk();
        $this->as($this->dispatcher)->putJson("/api/v1/spm/{$id}/lines/{$spm['lines'][0]['id']}", ['quantity' => 99])->assertUnprocessable();
        $this->assertSame('S1', DB::table('shipment_lines')->where('id', $spm['lines'][0]['id'])->value('seal_number'));
        $this->as($this->dispatcher)->putJson("/api/v1/spm/{$id}", ['reference' => 'X'])->assertStatus(409);
    }

    public function test_changing_the_destination_needs_permission_and_a_reason_and_reprices_a_planned_spm(): void
    {
        $this->approvedTariff('SELL', 1_000_000_00);
        $this->as($this->dispatcher)->postJson('/api/v1/tariffs', ['code' => 'CIR', 'name' => 'Ke Cirebon', 'side' => 'SELL', 'valid_from' => '2026-01-01', 'customer_id' => $this->m['cust']])->assertCreated();
        $spm = $this->spm();
        $this->move($spm, 'PLANNED')->assertOk();
        $id = $spm['spm']['id'];

        $this->as($this->dispatcher)->postJson("/api/v1/spm/{$id}/destination", ['destination_location_id' => $this->m['wh2'], 'reason' => 'Ganti gudang'])->assertForbidden();
        $this->as($this->supervisor)->postJson("/api/v1/spm/{$id}/destination", ['destination_location_id' => $this->m['wh2'], 'reason' => 'x'])->assertUnprocessable();
        $r = $this->as($this->supervisor)->postJson("/api/v1/spm/{$id}/destination", ['destination_location_id' => $this->m['wh2'], 'reason' => 'Gudang penuh, alihkan'])->assertOk()->json('data.spm');
        $this->assertSame('Gudang Cirebon', $r['spm']['destination_text']);
        $this->assertSame(1_000_000_00, $r['totals']['sell_minor'], 'priced again');
        $this->assertStringContainsString('Gudang penuh', collect($r['history'])->last()['reason']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenant, 'action' => 'spm.destination_changed', 'subject_id' => $id]);

        $this->move($spm, 'LOADING');
        $this->move($spm, 'IN_TRANSIT');
        $this->as($this->supervisor)->postJson("/api/v1/spm/{$id}/destination", ['destination_location_id' => $this->m['wh'], 'reason' => 'Terlambat diganti'])->assertStatus(409);
    }

    public function test_manual_charges_need_a_reason_and_survive_repricing(): void
    {
        $this->approvedTariff('SELL', 1_000_000_00);
        $spm = $this->spm();
        $id = $spm['spm']['id'];
        $body = ['side' => 'SELL', 'charge_code_id' => $this->m['LOADING'], 'amount_minor' => 150_000_00, 'reason' => 'Muat tambahan 2 jam'];
        $this->as($this->dispatcher)->postJson("/api/v1/spm/{$id}/charges", $body)->assertStatus(409);
        $this->move($spm, 'PLANNED')->assertOk();
        $this->as($this->dispatcher)->postJson("/api/v1/spm/{$id}/charges", array_replace($body, ['reason' => '']))->assertUnprocessable();
        $this->as($this->dispatcher)->postJson("/api/v1/spm/{$id}/charges", $body)->assertCreated()->assertJsonPath('data.kind', 'UNPLAN')->assertJsonPath('data.source', 'MANUAL');
        $this->as($this->dispatcher)->postJson("/api/v1/spm/{$id}/charges", array_replace($body, ['amount_minor' => -50_000_00, 'reason' => 'Koreksi diskon']))->assertCreated();

        $this->as($this->supervisor)->postJson("/api/v1/spm/{$id}/destination", ['destination_location_id' => $this->m['wh2'], 'reason' => 'Ganti tujuan'])->assertOk();
        $shown = $this->getJson("/api/v1/spm/{$id}")->json('data');
        $this->assertCount(3, $shown['charges'], 'the tariff line is replaced, the two manual lines stay');
        $this->assertSame(1_100_000_00, $shown['totals']['sell_minor']);
    }

    public function test_buying_prices_are_hidden_without_the_permission(): void
    {
        $this->approvedTariff('BUY', 700_000_00);
        $spm = $this->spm();
        $this->move($spm, 'PLANNED')->assertOk();
        $shown = $this->getJson("/api/v1/spm/{$spm['spm']['id']}")->json('data');
        $this->assertSame(700_000_00, $shown['totals']['buy_minor'] ?? null);

        $store = new TenantStore($this->tenant, $this->dispatcher->id);
        $hidden = (new SpmService($store))->show($spm['spm']['id'], false);
        $this->assertSame([], $hidden['charges']);
        $this->assertSame(['sell_minor' => 0], $hidden['totals']);
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $spm = $this->spm();
        $viewer = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $viewer->id, 'role' => 'viewer', 'active' => true]);
        $this->as($viewer)->getJson('/api/v1/spm')->assertOk();
        $order = $this->order();
        $this->as($viewer)->postJson("/api/v1/orders/{$order}/spm")->assertForbidden();
        $this->as($viewer)->postJson("/api/v1/spm/{$spm['spm']['id']}/status", ['to' => 'PLANNED'])->assertForbidden();

        $other = User::factory()->create();
        $tenant2 = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $tenant2, 'slug' => 'other', 'name' => 'Other']);
        DB::table('memberships')->insert(['tenant_id' => $tenant2, 'user_id' => $other->id, 'role' => 'dispatcher', 'active' => true]);
        $this->actingAs($other)->withSession(['tenant_id' => $tenant2])->getJson("/api/v1/spm/{$spm['spm']['id']}")->assertNotFound();
        $this->actingAs($other)->withSession(['tenant_id' => $tenant2])->getJson('/api/v1/spm')->assertOk()->assertJsonPath('total', 0);
    }
}

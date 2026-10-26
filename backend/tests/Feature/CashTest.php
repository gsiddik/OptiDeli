<?php

namespace Tests\Feature;

use App\Domain\Master\MasterDefaults;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** Driver cash (uang jalan): request, approval with limits, disbursement from a cashbox, settlement with receipts, ledger (F3/M5). */
class CashTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private User $dispatcher;

    private User $supervisor;

    private User $head;

    private User $finance;

    private User $cfo;

    private array $m = [];

    private string $trip;

    private string $spm;

    private string $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('dispatcher');
        MasterDefaults::seed($this->tenant);
        $this->dispatcher = auth()->user();
        $this->supervisor = $this->member('supervisor');
        $this->head = $this->member('head_of_logistics');
        $this->finance = $this->member('finance');
        $this->cfo = $this->member('cfo');
        foreach (['FREIGHT', 'UNLOADING', 'TOLL'] as $code) {
            $this->m[$code] = DB::table('charge_codes')->where('tenant_id', $this->tenant)->where('code', $code)->value('id');
        }
        $this->buildTrip();
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $user->id, 'role' => $role, 'active' => true]);

        return $user;
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function as(User $user)
    {
        return $this->actingAs($user)->withSession(['tenant_id' => $this->tenant]);
    }

    private function master(string $resource, array $body): string
    {
        return $this->as($this->dispatcher)->postJson("/api/v1/master/{$resource}", $body, $this->key())->assertCreated()->json('data.id');
    }

    /** A planned trip whose SPM is priced with a BUY tariff of freight (10 jt) and unloading (100 rb). */
    private function buildTrip(): void
    {
        $cust = $this->master('customers', ['code' => 'CUST', 'name' => 'PT Maju', 'payment_terms_days' => 30]);
        $pool = $this->master('locations', ['code' => 'POOL', 'name' => 'Pool', 'type' => 'POOL']);
        $wh = $this->master('locations', ['code' => 'WH', 'name' => 'Gudang', 'type' => 'WAREHOUSE']);
        $vehicle = (string) Str::uuid();
        DB::table('vehicles')->insert(['id' => $vehicle, 'tenant_id' => $this->tenant, 'plate' => 'B 1 CASH', 'type' => 'Box', 'capacity_g' => 1_000_000, 'length_mm' => 4000, 'width_mm' => 2000, 'height_mm' => 2000, 'status' => 'AVAILABLE', 'documents_valid_until' => now()->addYear()->toDateString(), 'readiness_checked_at' => now()]);
        $this->driver = (string) Str::uuid();
        DB::table('drivers')->insert(['id' => $this->driver, 'tenant_id' => $this->tenant, 'name' => 'Budi', 'license_number' => 'SIM-1', 'license_valid_until' => now()->addYear()->toDateString(), 'eligibility_verified_until' => now()->addDays(2), 'active' => true]);

        $t = $this->as($this->dispatcher)->postJson('/api/v1/tariffs', ['code' => 'BUY1', 'name' => 'Beli', 'side' => 'BUY', 'valid_from' => '2026-01-01'])->assertCreated()->json('data.id');
        $this->as($this->dispatcher)->postJson("/api/v1/tariffs/{$t}/lines", ['charge_code_id' => $this->m['FREIGHT'], 'basis' => 'TRIP', 'unit_price_minor' => 10_000_000_00])->assertCreated();
        $this->as($this->dispatcher)->postJson("/api/v1/tariffs/{$t}/lines", ['charge_code_id' => $this->m['UNLOADING'], 'basis' => 'TRIP', 'unit_price_minor' => 100_000_00])->assertCreated();
        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$t}/approve")->assertOk();

        $order = $this->as($this->dispatcher)->postJson('/api/v1/orders', [
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'customer_id' => $cust,
            'origin_location_id' => $pool, 'destination_location_id' => $wh,
            'items' => [['description' => 'Palet', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]],
        ], $this->key())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/orders/{$order}/transition", ['action' => 'confirm'])->assertOk();
        $this->spm = $this->postJson("/api/v1/orders/{$order}/spm")->assertCreated()->json('data.spm.id');
        $this->postJson("/api/v1/spm/{$this->spm}/status", ['to' => 'PLANNED'])->assertOk();
        $awb = $this->getJson("/api/v1/spm/{$this->spm}")->json('data.awbs.0.id');
        $this->trip = $this->postJson('/api/v1/spm-trips', ['spm_id' => $this->spm, 'awb_ids' => [$awb], 'vehicle_id' => $vehicle, 'driver_id' => $this->driver, 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(5)->toIso8601String()])->assertCreated()->json('data.trip.id');
    }

    private function request(?array $lines = null, ?User $by = null)
    {
        return $this->as($by ?? $this->dispatcher)->postJson("/api/v1/spm-trips/{$this->trip}/cash-vouchers", ['purpose' => 'Uang jalan Jakarta-Bandung', 'lines' => $lines ?? [
            ['charge_code_id' => $this->m['TOLL'], 'requested_minor' => 300_000_00, 'reason' => 'Tol Cipularang pergi-pulang'],
            ['charge_code_id' => $this->m['UNLOADING'], 'requested_minor' => 100_000_00],
        ]]);
    }

    private function box(int $topUp = 5_000_000_00): string
    {
        $id = $this->as($this->finance)->postJson('/api/v1/cash/cashboxes', ['code' => 'kas-utama', 'name' => 'Kas utama'])->assertCreated()->json('data.id');
        if ($topUp > 0) {
            $this->as($this->finance)->postJson("/api/v1/cash/cashboxes/{$id}/topups", ['amount_minor' => $topUp, 'memo' => 'Setoran awal'])->assertCreated();
        }

        return $id;
    }

    /** A voucher that is approved and paid out. */
    private function paid(?array $lines = null, int $topUp = 5_000_000_00): array
    {
        $box = $this->box($topUp);
        $v = $this->request($lines)->assertCreated()->json('data');
        $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$v['voucher']['id']}/approve")->assertOk();
        $this->as($this->finance)->postJson("/api/v1/cash/vouchers/{$v['voucher']['id']}/disburse", ['cashbox_id' => $box])->assertOk();

        return ['id' => $v['voucher']['id'], 'box' => $box, 'lines' => $this->getJson("/api/v1/cash/vouchers/{$v['voucher']['id']}")->json('data.lines')];
    }

    private function balance(string $box): int
    {
        return (int) collect($this->as($this->finance)->getJson('/api/v1/cash/overview')->json('data.cashboxes'))->firstWhere('id', $box)['balance_minor'];
    }

    public function test_a_request_marks_lines_plan_or_unplan_and_unplan_needs_a_reason(): void
    {
        $v = $this->request()->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^CV-\d{6}-00001$/', $v['voucher']['number']);
        $this->assertSame('REQUESTED', $v['voucher']['status']);
        $this->assertSame(400_000_00, $v['voucher']['requested_minor']);
        $this->assertSame($this->driver, $v['voucher']['driver_id']);
        $kinds = collect($v['lines'])->pluck('kind', 'charge_code_label');
        $this->assertSame('UNPLAN', $kinds['TOLL · Tol']);
        $this->assertSame('PLAN', $kinds['UNLOADING · Uang bongkar']);
        $this->assertSame(100_000_00, collect($v['lines'])->firstWhere('kind', 'PLAN')['planned_minor']);

        $this->request([['charge_code_id' => $this->m['TOLL'], 'requested_minor' => 100_00]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.reason');
        $this->request([['charge_code_id' => $this->m['UNLOADING'], 'requested_minor' => 150_000_00]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.reason');
        $this->request([['charge_code_id' => $this->m['UNLOADING'], 'requested_minor' => 150_000_00, 'reason' => 'Bongkar dua titik']])->assertCreated();
        $this->request([])->assertUnprocessable();
    }

    public function test_a_voucher_needs_a_trip_that_is_planned_or_running(): void
    {
        $this->as($this->dispatcher)->postJson("/api/v1/spm-trips/{$this->trip}/cancel", ['reason' => 'Batal berangkat'])->assertOk();
        $this->request()->assertStatus(409);
    }

    public function test_nobody_approves_their_own_request_and_the_approval_limit_applies(): void
    {
        $own = $this->request(null, $this->supervisor)->assertCreated()->json('data.voucher.id');
        $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$own}/approve")->assertForbidden();
        $v = $this->request()->assertCreated()->json('data.voucher.id');
        $this->as($this->dispatcher)->postJson("/api/v1/cash/vouchers/{$v}/approve")->assertForbidden();

        $this->as($this->supervisor)->putJson('/api/v1/cash/limit', ['approval_limit_minor' => 200_000_00])->assertForbidden();
        $this->as($this->cfo)->putJson('/api/v1/cash/limit', ['approval_limit_minor' => 200_000_00])->assertOk();
        $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$v}/approve")->assertForbidden();
        $this->as($this->head)->postJson("/api/v1/cash/vouchers/{$v}/approve")->assertOk()->assertJsonPath('data.voucher.status', 'APPROVED')->assertJsonPath('data.voucher.approved_minor', 400_000_00);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenant, 'action' => 'cash.approved', 'subject_id' => $v]);
    }

    public function test_an_approver_can_cut_a_line_but_not_raise_it(): void
    {
        $v = $this->request()->assertCreated()->json('data');
        $toll = collect($v['lines'])->firstWhere('kind', 'UNPLAN')['id'];
        $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$v['voucher']['id']}/approve", ['lines' => [['id' => $toll, 'approved_minor' => 999_999_00]]])->assertUnprocessable();
        $r = $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$v['voucher']['id']}/approve", ['lines' => [['id' => $toll, 'approved_minor' => 200_000_00]]])->assertOk()->json('data');
        $this->assertSame(300_000_00, $r['voucher']['approved_minor']);
    }

    public function test_a_rejection_or_cancellation_needs_a_reason_and_ends_the_voucher(): void
    {
        $v = $this->request()->assertCreated()->json('data.voucher.id');
        $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$v}/reject", ['reason' => 'x'])->assertUnprocessable();
        $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$v}/reject", ['reason' => 'Nilai terlalu besar'])->assertOk()->assertJsonPath('data.voucher.status', 'REJECTED');
        $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$v}/approve")->assertStatus(409);
        $w = $this->request()->assertCreated()->json('data.voucher.id');
        $this->as($this->dispatcher)->postJson("/api/v1/cash/vouchers/{$w}/cancel", ['reason' => 'Tidak jadi'])->assertOk()->assertJsonPath('data.voucher.status', 'CANCELLED');
    }

    public function test_cash_is_paid_from_a_cashbox_that_has_the_balance_and_moves_through_the_ledger(): void
    {
        $v = $this->request()->assertCreated()->json('data.voucher.id');
        $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$v}/approve")->assertOk();
        $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$v}/disburse", ['cashbox_id' => (string) Str::uuid()])->assertForbidden();
        $box = $this->box(100_000_00);
        $this->as($this->finance)->postJson("/api/v1/cash/vouchers/{$v}/disburse", ['cashbox_id' => $box])->assertUnprocessable()->assertJsonValidationErrors('cashbox_id');
        $this->as($this->finance)->postJson("/api/v1/cash/cashboxes/{$box}/topups", ['amount_minor' => 1_000_000_00])->assertCreated();
        $this->as($this->supervisor)->postJson('/api/v1/cash/cashboxes', ['code' => 'x', 'name' => 'x'])->assertForbidden();

        $r = $this->as($this->finance)->postJson("/api/v1/cash/vouchers/{$v}/disburse", ['cashbox_id' => $box])->assertOk()->json('data');
        $this->assertSame('DISBURSED', $r['voucher']['status']);
        $this->assertSame(700_000_00, $this->balance($box));
        $drivers = $this->as($this->finance)->getJson('/api/v1/cash/overview')->json('data.drivers');
        $this->assertSame([['driver_id' => $this->driver, 'driver_name' => 'Budi', 'balance_minor' => 400_000_00]], $drivers);
        $this->as($this->finance)->postJson("/api/v1/cash/vouchers/{$v}/disburse", ['cashbox_id' => $box])->assertStatus(409);

        $ledger = $this->as($this->finance)->getJson("/api/v1/cash/ledger?cashbox_id={$box}")->assertOk()->json('data');
        $this->assertSame(['DISBURSE', 'TOPUP', 'TOPUP'], array_column($ledger, 'entry_type'));
    }

    public function test_the_requester_cannot_pay_out_their_own_voucher(): void
    {
        $box = $this->box();
        $v = $this->request(null, $this->supervisor)->assertCreated()->json('data.voucher.id');
        $this->as($this->head)->postJson("/api/v1/cash/vouchers/{$v}/approve")->assertOk();
        $this->as($this->cfo)->postJson("/api/v1/cash/vouchers/{$v}/disburse", ['cashbox_id' => $box])->assertOk();
        $own = $this->request(null, $this->cfo)->assertCreated()->json('data.voucher.id');
        $this->as($this->head)->postJson("/api/v1/cash/vouchers/{$own}/approve")->assertOk();
        $this->as($this->cfo)->postJson("/api/v1/cash/vouchers/{$own}/disburse", ['cashbox_id' => $box])->assertForbidden();
    }

    public function test_the_ledger_cannot_be_edited_or_removed(): void
    {
        $p = $this->paid();
        $id = DB::table('cash_ledger')->where('voucher_id', $p['id'])->value('id');
        foreach (['UPDATE cash_ledger SET amount_minor = 1 WHERE id = ?', 'DELETE FROM cash_ledger WHERE id = ?'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql, [$id]));
                $this->fail('the ledger accepted a change');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append only', $e->getMessage());
            }
        }
    }

    public function test_settling_needs_receipts_and_turns_extra_spending_into_buy_charges(): void
    {
        $p = $this->paid();
        $toll = collect($p['lines'])->firstWhere('kind', 'UNPLAN')['id'];
        $unload = collect($p['lines'])->firstWhere('kind', 'PLAN')['id'];
        $this->as($this->dispatcher)->postJson("/api/v1/cash/vouchers/{$p['id']}/settle", [])->assertForbidden();
        $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$p['id']}/settle", ['lines' => [['id' => $toll, 'actual_minor' => 280_000_00]], 'returned_minor' => 0])->assertUnprocessable();
        $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$p['id']}/settle", ['lines' => [['id' => $toll, 'actual_minor' => 280_000_00], ['id' => $unload, 'actual_minor' => 100_000_00, 'receipt_ref' => 'NB-7']], 'returned_minor' => 0])->assertUnprocessable();
        $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$p['id']}/settle", ['lines' => [['id' => $toll, 'actual_minor' => 280_000_00, 'receipt_ref' => 'Struk tol 1-4'], ['id' => $unload, 'actual_minor' => 100_000_00, 'receipt_ref' => 'NB-7']], 'returned_minor' => 30_000_00])->assertUnprocessable()->assertJsonValidationErrors('returned');

        $r = $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$p['id']}/settle", ['lines' => [['id' => $toll, 'actual_minor' => 280_000_00, 'receipt_ref' => 'Struk tol 1-4'], ['id' => $unload, 'actual_minor' => 100_000_00, 'receipt_ref' => 'NB-7']], 'returned_minor' => 20_000_00])->assertOk()->json('data');
        $this->assertSame('SETTLED', $r['voucher']['status']);
        $this->assertSame(380_000_00, $r['voucher']['actual_minor']);
        $this->assertSame(20_000_00, $r['voucher']['returned_minor']);
        $this->assertSame(0, $r['voucher']['reimbursed_minor']);

        $charges = DB::table('spm_charges')->where('transport_order_id', $this->spm)->where('cash_voucher_id', $p['id'])->get();
        $this->assertCount(1, $charges, 'the unloading was already priced by the tariff, only the toll is new');
        $this->assertSame('BUY', $charges[0]->side);
        $this->assertSame('UNPLAN', $charges[0]->kind);
        $this->assertSame(280_000_00, (int) $charges[0]->amount_minor);
        $this->assertSame($this->m['TOLL'], $charges[0]->charge_code_id);

        $this->assertSame([], $this->as($this->finance)->getJson('/api/v1/cash/overview')->json('data.drivers'), 'the driver holds nothing: spent 380, returned 20');
        $this->assertSame(4_620_000_00, $this->balance($p['box']));
        $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$p['id']}/settle", ['lines' => [], 'returned_minor' => 0])->assertStatus(422);
    }

    public function test_money_left_with_the_driver_stays_as_an_expected_balance(): void
    {
        $p = $this->paid();
        $toll = collect($p['lines'])->firstWhere('kind', 'UNPLAN')['id'];
        $unload = collect($p['lines'])->firstWhere('kind', 'PLAN')['id'];
        $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$p['id']}/settle", ['lines' => [['id' => $toll, 'actual_minor' => 200_000_00, 'receipt_ref' => 'Struk'], ['id' => $unload, 'actual_minor' => 50_000_00, 'receipt_ref' => 'NB-1']], 'returned_minor' => 0])->assertOk();
        $this->assertSame(150_000_00, $this->as($this->finance)->getJson('/api/v1/cash/overview')->json('data.drivers.0.balance_minor'));
        $this->assertSame(0, DB::table('spm_charges')->where('cash_voucher_id', $p['id'])->where('charge_code_id', $this->m['UNLOADING'])->count());
    }

    public function test_spending_beyond_the_cash_given_is_reimbursed_from_the_cashbox(): void
    {
        $p = $this->paid();
        $before = $this->balance($p['box']);
        $toll = collect($p['lines'])->firstWhere('kind', 'UNPLAN')['id'];
        $unload = collect($p['lines'])->firstWhere('kind', 'PLAN')['id'];
        $r = $this->as($this->supervisor)->postJson("/api/v1/cash/vouchers/{$p['id']}/settle", ['lines' => [['id' => $toll, 'actual_minor' => 350_000_00, 'receipt_ref' => 'Struk'], ['id' => $unload, 'actual_minor' => 130_000_00, 'receipt_ref' => 'NB-2']], 'returned_minor' => 0])->assertOk()->json('data');
        $this->assertSame(80_000_00, $r['voucher']['reimbursed_minor']);
        $this->assertSame($before - 80_000_00, $this->balance($p['box']));
        $this->assertSame([], $this->as($this->finance)->getJson('/api/v1/cash/overview')->json('data.drivers'));
        $amounts = DB::table('spm_charges')->where('cash_voucher_id', $p['id'])->orderBy('amount_minor')->pluck('amount_minor')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([30_000_00, 350_000_00], $amounts, 'the toll in full and the 30 rb above the unloading tariff');
    }

    public function test_the_work_queue_counts_what_waits_for_the_user(): void
    {
        $this->request()->assertCreated();
        $this->assertSame(1, $this->as($this->supervisor)->getJson('/api/v1/workqueue')->json('data.vouchers_to_approve'));
        $this->assertArrayNotHasKey('vouchers_to_approve', $this->as($this->dispatcher)->getJson('/api/v1/workqueue')->json('data'));
        $this->assertArrayHasKey('spm_draft', $this->as($this->dispatcher)->getJson('/api/v1/workqueue')->json('data'));
        $this->assertSame(0, $this->as($this->finance)->getJson('/api/v1/workqueue')->json('data.vouchers_to_disburse'));
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $v = $this->request()->assertCreated()->json('data.voucher.id');
        $viewer = $this->member('viewer');
        $this->as($viewer)->getJson('/api/v1/cash/vouchers')->assertForbidden();
        $this->as($this->dispatcher)->getJson('/api/v1/cash/vouchers')->assertOk()->assertJsonPath('total', 1);

        $other = User::factory()->create();
        $tenant2 = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $tenant2, 'slug' => 'other', 'name' => 'Other']);
        DB::table('memberships')->insert(['tenant_id' => $tenant2, 'user_id' => $other->id, 'role' => 'cfo', 'active' => true]);
        $this->actingAs($other)->withSession(['tenant_id' => $tenant2])->getJson("/api/v1/cash/vouchers/{$v}")->assertNotFound();
        $this->actingAs($other)->withSession(['tenant_id' => $tenant2])->getJson('/api/v1/cash/overview')->assertOk()->assertJsonPath('data.cashboxes', []);
    }
}

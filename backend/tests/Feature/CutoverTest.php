<?php

namespace Tests\Feature;

use App\Domain\Cutover\CutoverService;
use App\Domain\Master\MasterDefaults;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** Cut-over tracker: checklist, parallel reconciliation against legacy control totals, and the gate (F7). */
class CutoverTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private User $dispatcher;

    private User $supervisor;

    private User $head;

    private User $cfo;

    private array $m = [];

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('dispatcher');
        MasterDefaults::seed($this->tenant);
        $this->dispatcher = auth()->user();
        $this->supervisor = $this->member('supervisor');
        $this->head = $this->member('head_of_logistics');
        $this->cfo = $this->member('cfo');
        $this->period = CarbonImmutable::now()->subMonthNoOverflow()->format('Y-m');
        $this->m['cust'] = $this->master('customers', ['code' => 'CUST', 'name' => 'PT Maju', 'payment_terms_days' => 14]);
        $this->m['other'] = $this->master('customers', ['code' => 'LAIN', 'name' => 'PT Lain', 'payment_terms_days' => 14]);
        $this->m['proj'] = $this->master('customer_projects', ['code' => 'PROJ', 'name' => 'Proyek A', 'customer_id' => $this->m['cust']]);
        $this->m['pool'] = $this->master('locations', ['code' => 'POOL', 'name' => 'Pool', 'type' => 'POOL']);
        $this->m['wh'] = $this->master('locations', ['code' => 'WH', 'name' => 'Gudang', 'type' => 'WAREHOUSE']);
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

    private function master(string $resource, array $body): string
    {
        return $this->postJson("/api/v1/master/{$resource}", $body, $this->key())->assertCreated()->json('data.id');
    }

    private function as(User $user)
    {
        return $this->actingAs($user)->withSession(['tenant_id' => $this->tenant]);
    }

    /** An SPM of the customer picked up in the period, with a verified sell and buy charge. */
    private function spm(string $customer, int $sell, int $buy, ?string $project = null, string $status = 'DISCHARGED'): string
    {
        $order = $this->as($this->dispatcher)->postJson('/api/v1/orders', [
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'customer_id' => $customer, 'project_id' => $project,
            'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'],
            'items' => [['description' => 'Palet', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]],
        ], $this->key())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/orders/{$order}/transition", ['action' => 'confirm'])->assertOk();
        $spm = $this->postJson("/api/v1/orders/{$order}/spm", ['lines' => [['material' => 'Paket', 'quantity' => 1, 'weight_kg' => 100]]])->assertCreated()->json('data.spm.id');
        DB::table('transport_orders')->where('id', $spm)->update(['status' => $status, 'planned_pickup_at' => $this->period.'-10 08:00:00']);
        $code = DB::table('charge_codes')->where('tenant_id', $this->tenant)->where('code', 'FREIGHT')->value('id');
        foreach (['SELL' => $sell, 'BUY' => $buy] as $side => $amount) {
            DB::table('spm_charges')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'transport_order_id' => $spm, 'side' => $side, 'kind' => 'PLAN', 'charge_code_id' => $code, 'amount_minor' => $amount, 'status' => 'VERIFIED',
                'verified_by' => $this->dispatcher->id, 'verified_at' => now(), 'created_by' => $this->dispatcher->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $spm;
    }

    private function create(array $o = [])
    {
        return $this->as($this->supervisor)->postJson('/api/v1/cutover', $o + ['code' => 'cut-a', 'name' => 'Cut-over PT Maju', 'customer_id' => $this->m['cust'], 'legacy_system' => 'Sistem Lama A', 'parallel_from' => $this->period.'-01']);
    }

    private function totals(string $id, array $o = [])
    {
        return $this->as($this->supervisor)->putJson("/api/v1/cutover/{$id}/totals", $o + ['period' => $this->period, 'spm_count' => 2, 'sell_minor' => 3_000_000_00, 'buy_minor' => 2_000_000_00, 'source_note' => 'Rekap sistem lama '.$this->period]);
    }

    private function allChecks(string $id): void
    {
        foreach (array_keys(CutoverService::CHECKS) as $code) {
            $this->as($this->supervisor)->putJson("/api/v1/cutover/{$id}/checks/{$code}", ['done' => true])->assertOk();
        }
    }

    private function recon(string $id): array
    {
        return collect($this->as($this->supervisor)->getJson("/api/v1/cutover/{$id}")->json('data.reconciliation'))->keyBy('period')->all();
    }

    public function test_a_tracker_is_created_per_customer_or_project_with_its_checklist(): void
    {
        $this->as($this->dispatcher)->postJson('/api/v1/cutover', [])->assertForbidden();
        $this->create(['legacy_system' => ''])->assertUnprocessable()->assertJsonValidationErrors('legacy_system');
        $this->create(['project_id' => $this->m['proj'], 'customer_id' => $this->m['other']])->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $r = $this->create()->assertCreated()->assertJsonPath('data.cutover.code', 'CUT-A')->assertJsonPath('data.cutover.status', 'PLANNING');
        $this->assertCount(count(CutoverService::CHECKS), $r->json('data.checks'));
        $this->assertFalse($r->json('data.gate.open'));

        $this->create(['code' => 'CUT-B'])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->create(['code' => 'CUT-A', 'customer_id' => $this->m['other']])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->create(['code' => 'CUT-P', 'project_id' => $this->m['proj']])->assertCreated();
        $this->as($this->supervisor)->getJson('/api/v1/cutover')->assertOk()->assertJsonCount(2, 'data');
        $this->as($this->dispatcher)->getJson('/api/v1/cutover')->assertForbidden();
    }

    public function test_the_reconciliation_compares_the_parallel_months_with_the_legacy_totals(): void
    {
        $id = $this->create()->json('data.cutover.id');
        $this->spm($this->m['cust'], 2_000_000_00, 1_300_000_00);
        $this->spm($this->m['cust'], 1_000_000_00, 700_000_00);
        $this->spm($this->m['other'], 9_000_000_00, 9_000_000_00);
        $this->spm($this->m['cust'], 5_000_000_00, 1, null, 'CANCELLED');

        $row = $this->recon($id)[$this->period];
        $this->assertSame('NO_LEGACY', $row['status']);
        $this->assertSame(['spm_count' => 2, 'sell_minor' => 3_000_000_00, 'buy_minor' => 2_000_000_00], $row['deli']);

        $this->totals($id)->assertOk();
        $this->assertSame('MATCHED', $this->recon($id)[$this->period]['status']);

        $this->totals($id, ['sell_minor' => 3_100_000_00, 'spm_count' => 3])->assertOk();
        $row = $this->recon($id)[$this->period];
        $this->assertSame('DIFFERENT', $row['status']);
        $this->assertSame(['spm_count' => -1, 'sell_minor' => -100_000_00, 'buy_minor' => 0], $row['diff']);
        $this->totals($id, ['period' => '2026-13'])->assertUnprocessable();
    }

    public function test_the_tolerance_and_the_project_scope_decide_what_matches(): void
    {
        $scoped = $this->create(['project_id' => $this->m['proj'], 'code' => 'CUT-P', 'tolerance_minor' => 100_00])->json('data.cutover.id');
        $this->spm($this->m['cust'], 2_000_000_00, 1_000_000_00, $this->m['proj']);
        $this->spm($this->m['cust'], 8_000_000_00, 1_000_000_00);

        $this->totals($scoped, ['spm_count' => 1, 'sell_minor' => 2_000_000_00 + 50_00, 'buy_minor' => 1_000_000_00])->assertOk();
        $this->assertSame('MATCHED', $this->recon($scoped)[$this->period]['status'], 'the project only, within the tolerance');
        $this->totals($scoped, ['spm_count' => 1, 'sell_minor' => 2_000_000_00 + 200_00, 'buy_minor' => 1_000_000_00])->assertOk();
        $this->assertSame('DIFFERENT', $this->recon($scoped)[$this->period]['status']);
    }

    public function test_a_difference_is_explained_by_someone_other_than_who_entered_it(): void
    {
        $id = $this->create()->json('data.cutover.id');
        $this->spm($this->m['cust'], 2_000_000_00, 1_000_000_00);
        $this->totals($id, ['spm_count' => 2])->assertOk();

        $this->as($this->supervisor)->postJson("/api/v1/cutover/{$id}/totals/{$this->period}/accept", ['note' => 'Satu SPM legacy dibatalkan'])->assertForbidden();
        $this->as($this->head)->postJson("/api/v1/cutover/{$id}/totals/{$this->period}/accept", ['note' => 'x'])->assertUnprocessable();
        $this->as($this->head)->postJson("/api/v1/cutover/{$id}/totals/{$this->period}/accept", ['note' => 'Satu SPM legacy dibatalkan'])->assertOk();
        $this->assertSame('ACCEPTED', $this->recon($id)[$this->period]['status']);

        $this->totals($id, ['spm_count' => 5])->assertOk();
        $this->assertSame('DIFFERENT', $this->recon($id)[$this->period]['status'], 'new figures withdraw the acceptance');
    }

    public function test_the_gate_opens_only_when_ready_and_is_approved_by_someone_else(): void
    {
        $id = $this->create()->json('data.cutover.id');
        $this->spm($this->m['cust'], 3_000_000_00, 2_000_000_00);
        $this->totals($id, ['spm_count' => 1])->assertOk();

        $this->as($this->supervisor)->postJson("/api/v1/cutover/{$id}/status", ['to' => 'CUT_OVER'])->assertConflict();
        $this->as($this->supervisor)->postJson("/api/v1/cutover/{$id}/status", ['to' => 'PARALLEL'])->assertOk()->assertJsonPath('data.cutover.status', 'PARALLEL');
        $blocked = $this->as($this->cfo)->postJson("/api/v1/cutover/{$id}/status", ['to' => 'CUT_OVER'])->assertUnprocessable();
        $this->assertStringContainsString('Daftar periksa', $blocked->json('errors.status.0'));

        $this->allChecks($id);
        $this->as($this->supervisor)->postJson("/api/v1/cutover/{$id}/status", ['to' => 'CUT_OVER'])->assertForbidden();
        $this->assertTrue($this->as($this->cfo)->getJson("/api/v1/cutover/{$id}")->json('data.gate.open'));

        $this->totals($id, ['period' => CarbonImmutable::now()->subMonthsNoOverflow(2)->format('Y-m'), 'spm_count' => 4])->assertOk();
        $this->as($this->cfo)->postJson("/api/v1/cutover/{$id}/status", ['to' => 'CUT_OVER'])->assertUnprocessable();
        $this->as($this->supervisor)->deleteJson('/api/v1/cutover/'.$id.'/totals/'.CarbonImmutable::now()->subMonthsNoOverflow(2)->format('Y-m'))->assertOk();

        $cut = $this->as($this->cfo)->postJson("/api/v1/cutover/{$id}/status", ['to' => 'CUT_OVER'])->assertOk();
        $this->assertSame('CUT_OVER', $cut->json('data.cutover.status'));
        $this->assertNotNull($cut->json('data.cutover.legacy_frozen_at'));
        $this->assertNotNull($cut->json('data.cutover.approved_by'));
    }

    public function test_the_creator_cannot_approve_their_own_cut_over(): void
    {
        $id = $this->as($this->cfo)->postJson('/api/v1/cutover', ['code' => 'CUT-X', 'name' => 'X', 'customer_id' => $this->m['cust'], 'legacy_system' => 'Sistem Lama B', 'parallel_from' => $this->period.'-01'])->assertCreated()->json('data.cutover.id');
        $this->spm($this->m['cust'], 3_000_000_00, 2_000_000_00);
        $this->totals($id, ['spm_count' => 1])->assertOk();
        $this->as($this->cfo)->postJson("/api/v1/cutover/{$id}/status", ['to' => 'PARALLEL'])->assertOk();
        $this->allChecks($id);
        $this->as($this->cfo)->postJson("/api/v1/cutover/{$id}/status", ['to' => 'CUT_OVER'])->assertForbidden();
        $this->as($this->head)->postJson("/api/v1/cutover/{$id}/status", ['to' => 'CUT_OVER'])->assertOk();
    }

    public function test_after_the_cut_over_the_figures_are_frozen_until_it_is_rolled_back_with_a_reason(): void
    {
        $id = $this->create()->json('data.cutover.id');
        $this->spm($this->m['cust'], 3_000_000_00, 2_000_000_00);
        $this->totals($id, ['spm_count' => 1])->assertOk();
        $this->as($this->supervisor)->postJson("/api/v1/cutover/{$id}/status", ['to' => 'PARALLEL'])->assertOk();
        $this->allChecks($id);
        $this->as($this->cfo)->postJson("/api/v1/cutover/{$id}/status", ['to' => 'CUT_OVER'])->assertOk();

        $this->totals($id)->assertConflict();
        $this->as($this->supervisor)->putJson("/api/v1/cutover/{$id}/checks/MASTER_LOADED", ['done' => false])->assertConflict();
        $this->as($this->head)->postJson("/api/v1/cutover/{$id}/status", ['to' => 'PARALLEL'])->assertUnprocessable();
        $back = $this->as($this->head)->postJson("/api/v1/cutover/{$id}/status", ['to' => 'PARALLEL', 'reason' => 'Legacy masih dipakai gudang'])->assertOk();
        $this->assertSame(['PARALLEL', null], [$back->json('data.cutover.status'), $back->json('data.cutover.legacy_frozen_at')]);
        $this->totals($id)->assertOk();
        $this->assertGreaterThanOrEqual(4, DB::table('audit_events')->where('subject_id', $id)->count());
    }

    public function test_tenants_do_not_see_each_others_trackers(): void
    {
        $id = $this->create()->json('data.cutover.id');
        $other = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $other, 'name' => 'Lain', 'slug' => 'lain', 'active' => true, 'minimum_margin_bps' => 1500, 'created_at' => now(), 'updated_at' => now()]);
        $u = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $other, 'user_id' => $u->id, 'role' => 'cfo', 'active' => true]);
        $this->actingAs($u)->withSession(['tenant_id' => $other])->getJson("/api/v1/cutover/{$id}")->assertNotFound();
        $this->actingAs($u)->withSession(['tenant_id' => $other])->getJson('/api/v1/cutover')->assertOk()->assertJsonCount(0, 'data');
    }
}

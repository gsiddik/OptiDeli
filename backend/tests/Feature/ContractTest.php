<?php

namespace Tests\Feature;

use App\Domain\Master\MasterDefaults;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** Fixed-cost vehicle contracts: attendance, the monthly run, verification, billing and the P&L (F6/M9). */
class ContractTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private User $dispatcher;

    private User $supervisor;

    private User $finance;

    private array $m = [];

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('dispatcher');
        MasterDefaults::seed($this->tenant);
        $this->dispatcher = auth()->user();
        $this->supervisor = $this->member('supervisor');
        $this->finance = $this->member('finance');
        $this->period = CarbonImmutable::now()->subMonthNoOverflow()->format('Y-m');
        $this->m['cust'] = $this->master('customers', ['code' => 'CUST', 'name' => 'PT Maju', 'payment_terms_days' => 14]);
        $this->m['pool'] = $this->master('locations', ['code' => 'POOL', 'name' => 'Pool', 'type' => 'POOL']);
        $this->m['wh'] = $this->master('locations', ['code' => 'WH', 'name' => 'Gudang', 'type' => 'WAREHOUSE']);
        $this->m['vendor'] = (string) Str::uuid();
        DB::table('vendors')->insert(['id' => $this->m['vendor'], 'tenant_id' => $this->tenant, 'name' => 'CV Sewa', 'code' => 'SEWA', 'active' => true]);
        $this->m['vehicle'] = $this->vehicle('B 7000 FX');
        $this->m['driver'] = (string) Str::uuid();
        DB::table('drivers')->insert(['id' => $this->m['driver'], 'tenant_id' => $this->tenant, 'name' => 'Budi', 'license_number' => 'SIM-B', 'license_valid_until' => now()->addYear()->toDateString(), 'eligibility_verified_until' => now()->addDays(2), 'active' => true]);
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

    private function contract(array $o = [])
    {
        return $this->as($this->supervisor)->postJson('/api/v1/contracts', $o + [
            'code' => 'k-1', 'name' => 'Kontrak B 7000 FX', 'customer_id' => $this->m['cust'], 'vehicle_id' => $this->m['vehicle'], 'vendor_id' => $this->m['vendor'],
            'valid_from' => CarbonImmutable::now()->subMonths(3)->format('Y-m-d'), 'monthly_sell_minor' => 26_000_000_00, 'monthly_buy_minor' => 20_000_000_00,
            'trips_included' => 2, 'overage_sell_minor' => 1_000_000_00, 'overage_buy_minor' => 700_000_00, 'working_days' => 26,
        ]);
    }

    /** An SPM of the customer and `$n` completed trips of the contracted vehicle inside the period. */
    private function completedTrips(int $n): void
    {
        $order = $this->as($this->dispatcher)->postJson('/api/v1/orders', [
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'customer_id' => $this->m['cust'],
            'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'],
            'items' => [['description' => 'Palet', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]],
        ], $this->key())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/orders/{$order}/transition", ['action' => 'confirm'])->assertOk();
        $spm = $this->postJson("/api/v1/orders/{$order}/spm")->assertCreated()->json('data.spm.id');
        for ($i = 1; $i <= $n; $i++) {
            DB::table('spm_trips')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'number' => 'PJ-TEST-'.Str::random(5), 'transport_order_id' => $spm, 'vehicle_id' => $this->m['vehicle'], 'driver_id' => $this->m['driver'],
                'status' => 'COMPLETED', 'active' => false, 'starts_at' => $this->period.'-05 08:00:00', 'ends_at' => $this->period.'-05 18:00:00', 'completed_at' => $this->period.'-'.sprintf('%02d', 4 + $i).' 18:00:00',
                'created_by' => $this->dispatcher->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function calc(string $id, ?string $period = null)
    {
        return $this->as($this->supervisor)->postJson("/api/v1/contracts/{$id}/runs", ['period' => $period ?? $this->period]);
    }

    private function verifyBoth(string $id): void
    {
        foreach (['SELL', 'BUY'] as $side) {
            $this->as($this->finance)->postJson("/api/v1/contracts/{$id}/charges/verify", ['period' => $this->period, 'side' => $side, 'decision' => 'VERIFY'])->assertOk();
        }
    }

    public function test_a_contract_is_validated_and_a_vehicle_has_one_customer_at_a_time(): void
    {
        $this->as($this->dispatcher)->postJson('/api/v1/contracts', [])->assertForbidden();
        $id = $this->contract()->assertCreated()->assertJsonPath('data.contract.code', 'K-1')->json('data.contract.id');
        $this->contract(['code' => 'K-2'])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
        $this->contract(['code' => 'K-1', 'vehicle_id' => $this->vehicle('B 8000 FX')])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->contract(['code' => 'K-3', 'vehicle_id' => $this->vehicle('B 9000 FX'), 'valid_until' => '2020-01-01'])->assertUnprocessable()->assertJsonValidationErrors('valid_until');

        $this->as($this->supervisor)->putJson("/api/v1/contracts/{$id}", ['name' => 'Baru', 'valid_from' => CarbonImmutable::now()->subMonths(3)->format('Y-m-d'), 'monthly_sell_minor' => 27_000_000_00, 'monthly_buy_minor' => 20_000_000_00])->assertOk();
        $this->as($this->supervisor)->postJson("/api/v1/contracts/{$id}/end", ['reason' => 'x'])->assertUnprocessable();
        $this->as($this->supervisor)->postJson("/api/v1/contracts/{$id}/end", ['reason' => 'Kontrak selesai'])->assertOk()->assertJsonPath('data.contract.status', 'ENDED');
        $this->contract(['code' => 'K-4'])->assertCreated();
    }

    public function test_the_month_is_prorated_by_attendance_and_extra_trips_are_priced(): void
    {
        $id = $this->contract()->json('data.contract.id');
        $this->completedTrips(3);
        $start = $this->period.'-01';
        $this->as($this->dispatcher)->postJson('/api/v1/attendance', ['vehicle_id' => $this->m['vehicle'], 'from' => $start, 'to' => $this->period.'-20', 'status' => 'PRESENT'])->assertCreated()->assertJsonPath('data.days', 20);
        $this->as($this->dispatcher)->postJson('/api/v1/attendance', ['vehicle_id' => $this->m['vehicle'], 'from' => $this->period.'-21', 'to' => $this->period.'-22', 'status' => 'ABSENT'])->assertCreated();
        $this->assertCount(22, $this->as($this->dispatcher)->getJson("/api/v1/vehicles/{$this->m['vehicle']}/attendance?period={$this->period}")->json('data'));

        $r = $this->calc($id)->assertCreated()->json('data');
        $this->assertSame([], $r['warnings']);
        $by = collect($r['charges'])->keyBy(fn ($c) => $c['side'].'-'.$c['kind']);
        $factor = 20 / 26;
        $this->assertSame((int) round(26_000_000_00 * $factor), $by['SELL-FIXED']['amount_minor']);
        $this->assertSame((int) round(20_000_000_00 * $factor), $by['BUY-FIXED']['amount_minor']);
        $this->assertSame(1_000_000_00, $by['SELL-OVERAGE']['amount_minor'], '3 trips, 2 included');
        $this->assertSame(700_000_00, $by['BUY-OVERAGE']['amount_minor']);
        $calc = json_decode($by['SELL-FIXED']['calculation'], true);
        $this->assertSame(['ATTENDANCE', 20, 3, 1], [$calc['basis'], $calc['billable_days'], $calc['trips'], $calc['extra_trips']]);

        // Running again replaces the proposals instead of adding to them.
        $this->calc($id)->assertCreated();
        $this->assertSame(4, DB::table('contract_charges')->where('contract_id', $id)->where('status', 'PROPOSED')->count());
    }

    public function test_without_attendance_the_month_is_charged_in_full_with_a_warning(): void
    {
        $id = $this->contract()->json('data.contract.id');
        $r = $this->calc($id)->assertCreated()->json('data');
        $this->assertNotEmpty($r['warnings']);
        $this->assertSame(26_000_000_00, collect($r['charges'])->firstWhere('side', 'SELL')['amount_minor']);
        $this->calc($id, CarbonImmutable::now()->addMonth()->format('Y-m'))->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->calc($id, CarbonImmutable::now()->subYears(2)->format('Y-m'))->assertUnprocessable()->assertJsonValidationErrors('period');
    }

    public function test_a_contract_starting_mid_month_is_charged_by_the_calendar(): void
    {
        $id = $this->contract(['valid_from' => $this->period.'-16'])->json('data.contract.id');
        $days = CarbonImmutable::parse($this->period.'-01')->daysInMonth;
        $r = $this->calc($id)->assertCreated()->json('data');
        $expected = (int) round(26_000_000_00 * (($days - 15) / $days));
        $this->assertSame($expected, collect($r['charges'])->firstWhere('side', 'SELL')['amount_minor']);
    }

    public function test_charges_are_verified_by_someone_else_and_then_frozen(): void
    {
        $id = $this->contract()->json('data.contract.id');
        $this->calc($id)->assertCreated();
        $verify = fn (User $u, array $o = []) => $this->as($u)->postJson("/api/v1/contracts/{$id}/charges/verify", $o + ['period' => $this->period, 'side' => 'SELL', 'decision' => 'VERIFY']);
        $verify($this->dispatcher)->assertForbidden();
        $this->as($this->supervisor)->postJson('/api/v1/attendance', ['vehicle_id' => $this->m['vehicle'], 'from' => $this->period.'-01', 'to' => $this->period.'-02', 'status' => 'PRESENT'])->assertCreated();
        $verify($this->finance, ['decision' => 'REJECT'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->verifyBoth($id);
        $this->calc($id)->assertStatus(409);
        $verify($this->finance)->assertUnprocessable();

        $self = $this->member('finance');
        $other = $this->contract(['code' => 'K-9', 'vehicle_id' => $this->vehicle('B 1 ZZ')])->json('data.contract.id');
        DB::table('contract_charges')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'contract_id' => $other, 'period' => $this->period, 'side' => 'SELL', 'kind' => 'FIXED', 'description' => 'x', 'amount_minor' => 100, 'status' => 'PROPOSED', 'created_by' => $self->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->as($self)->postJson("/api/v1/contracts/{$other}/charges/verify", ['period' => $this->period, 'side' => 'SELL', 'decision' => 'VERIFY'])->assertForbidden();
    }

    public function test_verified_contract_charges_are_billed_with_the_spm_charges_and_counted_in_the_pnl(): void
    {
        $id = $this->contract()->json('data.contract.id');
        $this->calc($id)->assertCreated();
        $this->verifyBoth($id);

        $ready = $this->as($this->finance)->getJson('/api/v1/billing/ready')->assertOk()->json('data');
        $this->assertCount(1, $ready);
        $this->assertSame(['CONTRACT', null, 26_000_000_00], [$ready[0]['type'], $ready[0]['spm_id'], $ready[0]['total_minor']]);

        $run = $this->as($this->finance)->postJson('/api/v1/billing/batches', ['customer_id' => $this->m['cust']])->assertCreated()->json('data');
        $this->assertCount(1, $run['invoices']);
        $this->assertSame(26_000_000_00, $run['invoices'][0]['amount_minor']);
        $this->assertNull($run['invoices'][0]['transport_order_id']);
        $line = DB::table('ledger_document_lines')->where('document_id', $run['invoices'][0]['id'])->first();
        $this->assertNull($line->charge_id);
        $this->assertNotNull($line->contract_charge_id);
        $this->assertSame([], $this->as($this->finance)->getJson('/api/v1/billing/ready')->json('data'));
        $shown = $this->as($this->finance)->getJson("/api/v1/billing/invoices/{$run['invoices'][0]['id']}")->assertOk()->json('data');
        $this->assertStringContainsString('Sewa kendaraan', $shown['lines'][0]['description']);

        $pnl = fn (string $dim) => $this->as($this->finance)->getJson("/api/v1/reports/pnl?group_by={$dim}")->assertOk()->json('data');
        $this->assertSame([26_000_000_00, 20_000_000_00, 6_000_000_00], [$pnl('customer')['totals']['sell_minor'], $pnl('customer')['totals']['buy_minor'], $pnl('customer')['totals']['margin_minor']]);
        $this->assertSame('Kontrak K-1', $pnl('spm')['rows'][0]['label']);
        $this->assertSame('B 7000 FX', $pnl('vehicle')['rows'][0]['label']);
        $this->assertSame('CV Sewa', $pnl('vendor')['rows'][0]['label']);

        $this->as($this->finance)->postJson("/api/v1/billing/batches/{$run['batch']['id']}/cancel", ['reason' => 'Salah periode'])->assertOk();
        $this->assertCount(1, $this->as($this->finance)->getJson('/api/v1/billing/ready')->json('data'), 'the contract month is ready again');
    }

    public function test_attendance_rules(): void
    {
        $viewer = $this->member('viewer');
        $this->as($viewer)->postJson('/api/v1/attendance', ['vehicle_id' => $this->m['vehicle'], 'from' => $this->period.'-01', 'to' => $this->period.'-02', 'status' => 'PRESENT'])->assertForbidden();
        $body = ['vehicle_id' => $this->m['vehicle'], 'status' => 'PRESENT'];
        $this->as($this->dispatcher)->postJson('/api/v1/attendance', $body + ['from' => '2026-01-01', 'to' => '2026-06-30'])->assertUnprocessable();
        $this->as($this->dispatcher)->postJson('/api/v1/attendance', $body + ['from' => now()->toDateString(), 'to' => now()->addDays(3)->toDateString()])->assertUnprocessable();
        $this->as($this->dispatcher)->postJson('/api/v1/attendance', $body + ['from' => $this->period.'-03', 'to' => $this->period.'-03'])->assertCreated();
        $this->as($this->dispatcher)->postJson('/api/v1/attendance', ['status' => 'ABSENT'] + $body + ['from' => $this->period.'-03', 'to' => $this->period.'-03'])->assertCreated();
        $this->assertSame('ABSENT', DB::table('vehicle_attendance')->where('work_date', $this->period.'-03')->value('status'));
        $this->assertSame(1, DB::table('vehicle_attendance')->count(), 'a day is recorded once');
    }

    public function test_contracts_are_closed_to_those_who_may_not_see_buying_prices_and_tenants_are_isolated(): void
    {
        $id = $this->contract()->json('data.contract.id');
        $this->as($this->member('viewer'))->getJson('/api/v1/contracts')->assertForbidden();
        $this->as($this->finance)->getJson('/api/v1/contracts')->assertOk()->assertJsonCount(1, 'data');

        $other = User::factory()->create();
        $tenant2 = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $tenant2, 'slug' => 'other', 'name' => 'Other']);
        DB::table('memberships')->insert(['tenant_id' => $tenant2, 'user_id' => $other->id, 'role' => 'cfo', 'active' => true]);
        $this->actingAs($other)->withSession(['tenant_id' => $tenant2])->getJson("/api/v1/contracts/{$id}")->assertNotFound();
    }
}

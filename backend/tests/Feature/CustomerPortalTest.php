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

/** The customer portal: a customer-bound account sees its own shipments and invoices, read-only (F6/M11). */
class CustomerPortalTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private User $dispatcher;

    private User $supervisor;

    private User $finance;

    private User $portal;

    private array $m = [];

    private string $period;

    private string $other;

    private string $spm;

    private string $invoice;

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
        $this->other = $this->master('customers', ['code' => 'LAIN', 'name' => 'PT Lain', 'payment_terms_days' => 30]);
        $this->m['pool'] = $this->master('locations', ['code' => 'POOL', 'name' => 'Pool', 'type' => 'POOL']);
        $this->m['wh'] = $this->master('locations', ['code' => 'WH', 'name' => 'Gudang', 'type' => 'WAREHOUSE']);
        $this->m['vehicle'] = $this->vehicle('B 7000 FX');

        $this->spm = $this->spmFor($this->m['cust']);
        $this->spmFor($this->other);
        $id = $this->as($this->supervisor)->postJson('/api/v1/contracts', [
            'code' => 'k-1', 'name' => 'Kontrak B 7000 FX', 'customer_id' => $this->m['cust'], 'vehicle_id' => $this->m['vehicle'],
            'valid_from' => CarbonImmutable::now()->subMonths(3)->format('Y-m-d'), 'monthly_sell_minor' => 26_000_000_00, 'monthly_buy_minor' => 20_000_000_00,
            'trips_included' => 2, 'overage_sell_minor' => 1_000_000_00, 'overage_buy_minor' => 700_000_00, 'working_days' => 26,
        ])->assertCreated()->json('data.contract.id');
        $this->as($this->supervisor)->postJson("/api/v1/contracts/{$id}/runs", ['period' => $this->period])->assertCreated();
        foreach (['SELL', 'BUY'] as $side) {
            $this->as($this->finance)->postJson("/api/v1/contracts/{$id}/charges/verify", ['period' => $this->period, 'side' => $side, 'decision' => 'VERIFY'])->assertOk();
        }
        $this->invoice = $this->as($this->finance)->postJson('/api/v1/billing/batches', ['customer_id' => $this->m['cust']])->assertCreated()->json('data.invoices.0.id');
        $this->portal = $this->account($this->m['cust']);
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

    private function spmFor(string $customer): string
    {
        $order = $this->as($this->dispatcher)->postJson('/api/v1/orders', [
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'customer_id' => $customer,
            'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'],
            'items' => [['description' => 'Palet', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]],
        ], $this->key())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/orders/{$order}/transition", ['action' => 'confirm'])->assertOk();
        $spm = $this->postJson("/api/v1/orders/{$order}/spm")->assertCreated()->json('data.spm.id');
        DB::table('transport_orders')->where('id', $spm)->update(['status' => 'PLANNED']);

        return $spm;
    }

    private function account(string $customer): User
    {
        $email = strtolower('portal'.Str::random(5)).'@maju.test';
        $body = ['name' => 'Portal', 'email' => $email, 'password' => 'Rahasia-Portal-123!'];
        $this->as($this->supervisor)->postJson("/api/v1/customers/{$customer}/portal-account", $body)->assertForbidden();
        $this->as($this->member('cfo'))->postJson("/api/v1/customers/{$customer}/portal-account", $body)->assertCreated();

        return User::where('email', $email)->firstOrFail();
    }

    public function test_the_account_is_bound_to_one_customer_and_stays_out_of_the_internal_screens(): void
    {
        $this->assertSame($this->m['cust'], DB::table('memberships')->where('user_id', $this->portal->id)->value('customer_id'));
        $this->as($this->portal)->getJson('/api/v1/spm')->assertForbidden();
        $this->as($this->portal)->getJson('/api/v1/contracts')->assertForbidden();
        $this->as($this->portal)->getJson('/api/v1/billing/ready')->assertForbidden();
        $this->as($this->portal)->getJson('/api/v1/vendor/portal')->assertForbidden();
        $this->as($this->dispatcher)->getJson('/api/v1/customer/portal')->assertForbidden();
    }

    public function test_the_customer_sees_only_its_own_shipments_without_internal_data(): void
    {
        $rows = $this->as($this->portal)->getJson('/api/v1/customer/spm')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($this->spm, $rows[0]['id']);
        $this->assertArrayNotHasKey('vendor_id', $rows[0]);
        $this->assertArrayNotHasKey('created_by', $rows[0]);

        $detail = $this->as($this->portal)->getJson("/api/v1/customer/spm/{$this->spm}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('vendor_id', $detail['spm']);
        $this->assertStringNotContainsString('"BUY"', json_encode($detail));
        $theirs = DB::table('transport_orders')->where('customer_id', $this->other)->value('id');
        $this->as($this->portal)->getJson("/api/v1/customer/spm/{$theirs}")->assertNotFound();

        DB::table('transport_orders')->where('id', $this->spm)->update(['status' => 'DRAFT']);
        $this->as($this->portal)->getJson('/api/v1/customer/spm')->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->portal)->getJson("/api/v1/customer/spm/{$this->spm}")->assertNotFound();
    }

    public function test_invoices_are_the_customers_own_and_show_no_buying_side(): void
    {
        $list = $this->as($this->portal)->getJson('/api/v1/customer/invoices')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame($this->invoice, $list[0]['id']);
        $detail = $this->as($this->portal)->getJson("/api/v1/customer/invoices/{$this->invoice}")->assertOk()->json('data');
        $this->assertSame(26_000_000_00, $detail['invoice']['amount_minor']);
        $this->assertNotEmpty($detail['lines']);

        $summary = $this->as($this->portal)->getJson('/api/v1/customer/portal')->assertOk()->json('data');
        $this->assertSame([1, 1, 26_000_000_00], [$summary['open_spm'], $summary['unpaid_invoices'], $summary['outstanding_minor']]);

        $stranger = $this->account($this->other);
        $this->as($stranger)->getJson("/api/v1/customer/invoices/{$this->invoice}")->assertNotFound();
        $this->as($stranger)->getJson('/api/v1/customer/invoices')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_portal_is_read_only_and_cannot_download_files(): void
    {
        $this->as($this->portal)->postJson('/api/v1/customer/spm', [])->assertStatus(405);
        $this->as($this->portal)->putJson("/api/v1/customer/spm/{$this->spm}", [])->assertStatus(405);
        $this->as($this->portal)->getJson('/api/v1/files/pods/'.Str::uuid())->assertForbidden();
    }

    public function test_roles_keep_the_portal_and_customer_binding_together(): void
    {
        $member = DB::table('memberships')->where('user_id', $this->portal->id)->value('id');
        $role = DB::table('roles')->where('tenant_id', $this->tenant)->where('name', 'dispatcher')->value('id');
        $admin = $this->member('tenant_administrator');
        $this->as($admin)->putJson("/api/v1/access/members/{$member}/roles", ['role_ids' => [$role]])->assertUnprocessable();
    }
}

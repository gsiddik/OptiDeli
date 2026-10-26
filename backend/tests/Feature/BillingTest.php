<?php

namespace Tests\Feature;

use App\Domain\Billing\BillingService;
use App\Domain\Ledger\FeedCredentials;
use App\Domain\Ledger\LedgerService;
use App\Domain\Master\MasterDefaults;
use App\Domain\Shared\TenantStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** Verification of SPM charges, billing by invoice template, the vendor bill per SPM (3-way match) and the P&L (F4/M6-M7). */
class BillingTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private User $dispatcher;

    private User $supervisor;

    private User $finance;

    private array $m = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('dispatcher');
        MasterDefaults::seed($this->tenant);
        $this->dispatcher = auth()->user();
        $this->supervisor = $this->member('supervisor');
        $this->finance = $this->member('finance');
        $this->m['cust'] = $this->master('customers', ['code' => 'CUST', 'name' => 'PT Maju', 'payment_terms_days' => 14]);
        $this->m['proj'] = $this->master('customer_projects', ['code' => 'PROJ', 'name' => 'Proyek A', 'customer_id' => $this->m['cust']]);
        $this->m['pool'] = $this->master('locations', ['code' => 'POOL', 'name' => 'Pool Jakarta', 'type' => 'POOL']);
        $this->m['wh'] = $this->master('locations', ['code' => 'WH', 'name' => 'Gudang Bandung', 'type' => 'WAREHOUSE']);
        $this->m['wh2'] = $this->master('locations', ['code' => 'WH2', 'name' => 'Gudang Cirebon', 'type' => 'WAREHOUSE']);
        foreach (['FREIGHT', 'UNLOADING'] as $code) {
            $this->m[$code] = DB::table('charge_codes')->where('tenant_id', $this->tenant)->where('code', $code)->value('id');
        }
        $this->m['vendor'] = (string) Str::uuid();
        DB::table('vendors')->insert(['id' => $this->m['vendor'], 'tenant_id' => $this->tenant, 'name' => 'CV Angkut Jaya', 'code' => 'ANGKUT', 'active' => true]);
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

    private function tariff(string $side, int $freight, array $extra = [], ?int $unloading = null): void
    {
        $t = $this->as($this->dispatcher)->postJson('/api/v1/tariffs', $extra + ['code' => 'T'.Str::random(5), 'name' => 'Tarif', 'side' => $side, 'valid_from' => '2026-01-01'])->assertCreated()->json('data.id');
        $this->as($this->dispatcher)->postJson("/api/v1/tariffs/{$t}/lines", ['charge_code_id' => $this->m['FREIGHT'], 'basis' => 'TRIP', 'unit_price_minor' => $freight])->assertCreated();
        if ($unloading !== null) {
            $this->as($this->dispatcher)->postJson("/api/v1/tariffs/{$t}/lines", ['charge_code_id' => $this->m['UNLOADING'], 'basis' => 'TRIP', 'unit_price_minor' => $unloading])->assertCreated();
        }
        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$t}/approve")->assertOk();
    }

    /** A discharged SPM whose charges are priced from the tariffs (freight sell 2 jt / buy 1 jt, unloading sell 200 rb / buy 100 rb). */
    private function spm(array $o = []): array
    {
        $order = $this->as($this->dispatcher)->postJson('/api/v1/orders', [
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'customer_id' => $this->m['cust'],
            'project_id' => $o['project'] ?? null, 'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'],
            'items' => [['description' => 'Palet', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]],
        ], $this->key())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/orders/{$order}/transition", ['action' => 'confirm'])->assertOk();
        $spm = $this->postJson("/api/v1/orders/{$order}/spm", array_filter(['vendor_id' => $o['vendor'] ?? null, 'lines' => [['material' => 'Paket', 'quantity' => 1, 'weight_kg' => 100]]]))->assertCreated()->json('data.spm.id');
        foreach (['PLANNED', 'LOADING', 'IN_TRANSIT', 'DISCHARGED'] as $to) {
            $this->as($this->dispatcher)->postJson("/api/v1/spm/{$spm}/status", ['to' => $to])->assertOk();
        }
        DB::table('awbs')->where('transport_order_id', $spm)->update(['status' => 'DELIVERED']);

        return ['id' => $spm, 'order' => $order];
    }

    private function prices(): void
    {
        $this->tariff('SELL', 2_000_000_00, [], 200_000_00);
        $this->tariff('BUY', 1_000_000_00, [], 100_000_00);
    }

    private function verifyAll(string $spm, string $side = 'SELL'): void
    {
        $this->as($this->finance)->postJson("/api/v1/spm/{$spm}/charges/verify", ['side' => $side, 'decision' => 'VERIFY'])->assertOk();
    }

    // ---------------------------------------------------------------- verification

    public function test_charges_are_verified_after_discharge_by_someone_who_may_and_did_not_make_them(): void
    {
        $this->prices();
        $order = $this->as($this->dispatcher)->postJson('/api/v1/orders', [
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'customer_id' => $this->m['cust'],
            'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'],
            'items' => [['description' => 'Palet', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]],
        ], $this->key())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/orders/{$order}/transition", ['action' => 'confirm'])->assertOk();
        $early = $this->postJson("/api/v1/orders/{$order}/spm")->assertCreated()->json('data.spm.id');
        $this->postJson("/api/v1/spm/{$early}/status", ['to' => 'PLANNED'])->assertOk();
        $this->as($this->finance)->postJson("/api/v1/spm/{$early}/charges/verify", ['side' => 'SELL', 'decision' => 'VERIFY'])->assertStatus(409);

        $spm = $this->spm()['id'];
        $this->as($this->dispatcher)->postJson("/api/v1/spm/{$spm}/charges/verify", ['side' => 'SELL', 'decision' => 'VERIFY'])->assertForbidden();
        $this->as($this->finance)->postJson("/api/v1/spm/{$spm}/charges/verify", ['side' => 'SELL', 'decision' => 'REJECT'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $r = $this->as($this->finance)->postJson("/api/v1/spm/{$spm}/charges/verify", ['side' => 'SELL', 'decision' => 'VERIFY'])->assertOk()->json('data');
        $this->assertSame([2, 2_200_000_00, 'VERIFIED'], [$r['decided'], $r['total_minor'], $r['status']]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenant, 'action' => 'spm.charges_verified', 'subject_id' => $spm]);
        $this->as($this->finance)->postJson("/api/v1/spm/{$spm}/charges/verify", ['side' => 'SELL', 'decision' => 'VERIFY'])->assertUnprocessable();

        // Buying charges are decided with cost.verify, and the sides do not mix.
        $this->as($this->supervisor)->postJson("/api/v1/spm/{$spm}/charges/verify", ['side' => 'BUY', 'decision' => 'VERIFY'])->assertForbidden();
        $this->as($this->finance)->postJson("/api/v1/spm/{$spm}/charges/verify", ['side' => 'BUY', 'decision' => 'REJECT', 'note' => 'Harga beli salah'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
    }

    public function test_nobody_verifies_a_charge_they_made(): void
    {
        $this->prices();
        $spm = $this->spm()['id'];
        $this->as($this->supervisor)->postJson("/api/v1/spm/{$spm}/charges", ['side' => 'SELL', 'charge_code_id' => $this->m['UNLOADING'], 'amount_minor' => 50_000_00, 'reason' => 'Tambahan bongkar'])->assertCreated();
        $this->as($this->supervisor)->postJson("/api/v1/spm/{$spm}/charges/verify", ['side' => 'SELL', 'decision' => 'VERIFY'])->assertForbidden();
        $this->verifyAll($spm);
    }

    // ---------------------------------------------------------------- billing

    public function test_only_verified_unbilled_selling_charges_are_ready_to_bill(): void
    {
        $this->prices();
        $a = $this->spm()['id'];
        $b = $this->spm()['id'];
        $this->verifyAll($a);
        $this->as($this->dispatcher)->getJson('/api/v1/billing/ready')->assertForbidden();
        $ready = $this->as($this->finance)->getJson('/api/v1/billing/ready')->assertOk()->json('data');
        $this->assertCount(1, $ready);
        $this->assertSame($a, $ready[0]['spm_id']);
        $this->assertSame(2_200_000_00, $ready[0]['total_minor']);
        $this->assertSame('PT Maju', $ready[0]['customer_label']);
        $this->assertSame($b, DB::table('transport_orders')->where('id', $b)->value('id'));
    }

    public function test_a_billing_run_issues_an_invoice_with_lines_and_publishes_them(): void
    {
        $this->prices();
        $spm = $this->spm()['id'];
        $this->verifyAll($spm);

        $run = $this->as($this->finance)->postJson('/api/v1/billing/batches', ['customer_id' => $this->m['cust']])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^BB-\d{6}-00001$/', $run['batch']['number']);
        $this->assertCount(1, $run['invoices']);
        $invoice = $run['invoices'][0];
        $this->assertSame(['CUSTOMER_INVOICE', 'AR-000001', 2_200_000_00, 'OPEN'], [$invoice['kind'], $invoice['number'], $invoice['amount_minor'], $invoice['status']]);
        $this->assertSame(now()->addDays(14)->toDateString(), $invoice['due_date'], 'the customer terms');

        $shown = $this->as($this->finance)->getJson("/api/v1/ledger/documents/{$invoice['id']}")->assertOk()->json('data');
        $this->assertCount(2, $shown['lines']);
        $this->assertSame(['FREIGHT', 'UNLOADING'], collect($shown['lines'])->pluck('charge_code')->sort()->values()->all());

        $this->assertSame([], $this->as($this->finance)->getJson('/api/v1/billing/ready')->json('data'), 'billed charges are no longer ready');
        $this->as($this->finance)->postJson('/api/v1/billing/batches', ['customer_id' => $this->m['cust']])->assertUnprocessable();

        $feed = $this->feed();
        $event = collect($feed)->firstWhere('event_type', 'optideli.customer_invoice.issued');
        $this->assertSame('2200000.00', $event['payload']['amount']);
        $this->assertSame($run['batch']['number'], $event['payload']['billing_batch']);
        $this->assertCount(2, $event['payload']['lines']);
        $this->assertSame('2000000.00', collect($event['payload']['lines'])->firstWhere('charge_code', 'FREIGHT')['amount']);
        $this->assertSame('PT Maju', $event['payload']['customer_name']);
    }

    /** @return list<array<string, mixed>> */
    private function feed(): array
    {
        $token = app(FeedCredentials::class)->issue($this->tenant, 'optientry', 'Entry', 'test')['token'];

        return $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/integration/feed')->assertOk()->json('data');
    }

    public function test_the_template_decides_how_charges_are_grouped(): void
    {
        $this->prices();
        $a = $this->spm()['id'];
        $b = $this->spm()['id'];
        $this->verifyAll($a);
        $this->verifyAll($b);
        $templates = DB::table('invoice_templates')->where('tenant_id', $this->tenant)->pluck('id', 'code');
        $this->assertEqualsCanonicalizing(['STD-SPM', 'SPM-PLAN-UNPLAN', 'PROYEK', 'PERIODE'], $templates->keys()->all());

        $period = $this->as($this->finance)->postJson('/api/v1/billing/batches', ['customer_id' => $this->m['cust'], 'template_id' => $templates['PERIODE'], 'spm_ids' => [$a, $b]])->assertCreated()->json('data');
        $this->assertCount(1, $period['invoices'], 'one invoice for the period');
        $this->assertSame(4_400_000_00, $period['invoices'][0]['amount_minor']);
        $this->as($this->finance)->postJson("/api/v1/billing/batches/{$period['batch']['id']}/cancel", ['reason' => 'Ganti template'])->assertOk();

        $perSpm = $this->as($this->finance)->postJson('/api/v1/billing/batches', ['customer_id' => $this->m['cust']])->assertCreated()->json('data');
        $this->assertCount(2, $perSpm['invoices'], 'the default template is one invoice per SPM');
        $this->as($this->finance)->postJson("/api/v1/billing/batches/{$perSpm['batch']['id']}/cancel", ['reason' => 'Ganti template'])->assertOk();

        $split = $this->as($this->finance)->postJson('/api/v1/billing/batches', ['customer_id' => $this->m['cust'], 'template_id' => $templates['PERIODE']])->assertCreated()->json('data');
        $this->assertCount(1, $split['invoices']);
    }

    public function test_plan_and_unplan_can_be_billed_apart(): void
    {
        $this->prices();
        $spm = $this->spm()['id'];
        $this->as($this->supervisor)->postJson("/api/v1/spm/{$spm}/charges", ['side' => 'SELL', 'charge_code_id' => $this->m['UNLOADING'], 'amount_minor' => 50_000_00, 'reason' => 'Bongkar tambahan'])->assertCreated();
        $this->verifyAll($spm);
        $template = DB::table('invoice_templates')->where('tenant_id', $this->tenant)->where('code', 'SPM-PLAN-UNPLAN')->value('id');
        $run = $this->as($this->finance)->postJson('/api/v1/billing/batches', ['customer_id' => $this->m['cust'], 'template_id' => $template])->assertCreated()->json('data');
        $this->assertEqualsCanonicalizing([2_200_000_00, 50_000_00], array_column($run['invoices'], 'amount_minor'));
    }

    public function test_the_project_and_the_customer_choose_the_template_and_the_project_filters(): void
    {
        $this->prices();
        $periode = DB::table('invoice_templates')->where('tenant_id', $this->tenant)->where('code', 'PERIODE')->value('id');
        $this->as($this->dispatcher)->putJson("/api/v1/master/customers/{$this->m['cust']}", ['name' => 'PT Maju', 'payment_terms_days' => 14, 'invoice_template_id' => $periode])->assertOk();
        $with = $this->spm(['project' => $this->m['proj']])['id'];
        $without = $this->spm()['id'];
        $this->verifyAll($with);
        $this->verifyAll($without);

        $r = $this->as($this->finance)->postJson('/api/v1/billing/batches', ['customer_id' => $this->m['cust'], 'project_id' => $this->m['proj']])->assertCreated()->json('data');
        $this->assertCount(1, $r['invoices']);
        $this->assertSame(2_200_000_00, $r['invoices'][0]['amount_minor'], 'only the project SPM');
        $this->assertSame($periode, $r['batch']['template_id'], 'the customer template applies when the project has none');
    }

    public function test_cancelling_a_run_frees_the_charges_unless_a_payment_was_made(): void
    {
        $this->prices();
        $spm = $this->spm()['id'];
        $this->verifyAll($spm);
        $run = $this->as($this->finance)->postJson('/api/v1/billing/batches', ['customer_id' => $this->m['cust']])->assertCreated()->json('data');
        $invoice = $run['invoices'][0]['id'];

        $this->as($this->finance)->postJson("/api/v1/billing/batches/{$run['batch']['id']}/cancel", ['reason' => 'x'])->assertUnprocessable();
        $this->as($this->finance)->postJson("/api/v1/ledger/documents/{$invoice}/payments", ['amount_minor' => 100_000_00, 'paid_on' => now()->toDateString(), 'method' => 'BANK_TRANSFER', 'reference' => 'TRF-1'])->assertCreated();
        $this->as($this->finance)->postJson("/api/v1/billing/batches/{$run['batch']['id']}/cancel", ['reason' => 'Salah pelanggan'])->assertUnprocessable();
        $this->assertSame(1, DB::table('ledger_document_lines')->where('active', true)->count() > 0 ? 1 : 0);

        $other = $this->spm()['id'];
        $this->verifyAll($other);
        $second = $this->as($this->finance)->postJson('/api/v1/billing/batches', ['customer_id' => $this->m['cust']])->assertCreated()->json('data');
        $this->as($this->finance)->postJson("/api/v1/billing/batches/{$second['batch']['id']}/cancel", ['reason' => 'Salah pelanggan'])->assertOk()->assertJsonPath('data.batch.status', 'CANCELLED');
        $this->assertSame('VOID', DB::table('ledger_documents')->where('id', $second['invoices'][0]['id'])->value('status'));
        $this->assertCount(1, $this->as($this->finance)->getJson('/api/v1/billing/ready')->json('data'), 'the charges are ready again');
        $types = array_column($this->feed(), 'event_type');
        $this->assertContains('optideli.document.voided', $types);
    }

    public function test_an_order_is_billed_either_from_the_order_or_from_its_spm(): void
    {
        $this->prices();
        $spm = $this->spm();
        $this->verifyAll($spm['id']);
        DB::table('orders')->where('id', $spm['order'])->update(['status' => 'DELIVERED']);
        $this->as($this->finance)->postJson('/api/v1/ledger/customer-invoices', ['order_id' => $spm['order']])->assertUnprocessable();

        $legacy = $this->as($this->dispatcher)->postJson('/api/v1/orders', [
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'customer_id' => $this->m['cust'],
            'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'],
            'items' => [['description' => 'Palet', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]],
        ], $this->key())->assertCreated()->json('data.id');
        DB::table('orders')->where('id', $legacy)->update(['status' => 'DELIVERED']);
        $this->as($this->finance)->postJson('/api/v1/ledger/customer-invoices', ['order_id' => $legacy])->assertCreated();
    }

    public function test_tax_is_computed_here_only_when_standalone(): void
    {
        $this->prices();
        $spm = $this->spm()['id'];
        $this->verifyAll($spm);
        $this->as($this->finance)->getJson('/api/v1/billing/settings')->assertOk()->assertJsonPath('data.tax_applied', false);
        config(['optideli.deployment_mode' => 'standalone']);
        $store = new TenantStore($this->tenant, $this->finance->id);
        $run = (new BillingService($store, app(LedgerService::class)))->run(['customer_id' => $this->m['cust']]);
        $invoice = (array) $run['invoices'][0];
        $this->assertSame([2_200_000_00, 242_000_00, 2_442_000_00], [$invoice['subtotal_minor'], $invoice['tax_minor'], $invoice['amount_minor']]);
    }

    // ---------------------------------------------------------------- vendor bill

    public function test_a_vendor_bill_needs_the_three_to_agree(): void
    {
        $this->tariff('SELL', 2_000_000_00);
        $this->tariff('BUY', 1_000_000_00, ['vendor_id' => $this->m['vendor']], 100_000_00);
        $spm = $this->spm(['vendor' => $this->m['vendor']])['id'];
        $bill = fn (array $o = []) => $this->as($this->finance)->postJson("/api/v1/spm/{$spm}/vendor-bill", $o + ['vendor_invoice_number' => 'INV-77', 'amount_minor' => 1_100_000_00]);

        $this->as($this->dispatcher)->getJson("/api/v1/spm/{$spm}/vendor-bill")->assertForbidden();
        $preview = $this->as($this->finance)->getJson("/api/v1/spm/{$spm}/vendor-bill")->assertOk()->json('data');
        $this->assertFalse($preview['ready']);
        $this->assertSame(2, $preview['unverified_count']);

        $bill()->assertUnprocessable()->assertJsonValidationErrors('spm');
        $this->verifyAll($spm, 'BUY');
        DB::table('awbs')->where('transport_order_id', $spm)->update(['status' => 'ON_TRIP']);
        $bill()->assertUnprocessable()->assertJsonValidationErrors('spm');
        DB::table('awbs')->where('transport_order_id', $spm)->update(['status' => 'DELIVERED']);

        $bill(['amount_minor' => 1_150_000_00])->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
        $this->assertSame(0, DB::table('ledger_documents')->where('kind', 'VENDOR_BILL')->count(), 'a mismatch records nothing');
        $ok = $bill()->assertCreated()->json('data.document');
        $this->assertSame(['VENDOR_BILL', 'AP-000001', 1_100_000_00, 'INV-77'], [$ok['kind'], $ok['number'], $ok['amount_minor'], $ok['external_reference']]);
        $this->assertSame(2, DB::table('ledger_document_lines')->where('document_id', $ok['id'])->count());

        $bill()->assertUnprocessable();
        $event = collect($this->feed())->firstWhere('event_type', 'optideli.vendor_bill.recorded');
        $this->assertSame(['ANGKUT', 'INV-77', '1100000.00'], [$event['payload']['vendor_code'], $event['payload']['freight_invoice_number'], $event['payload']['amount']]);
        $this->assertSame($this->m['vendor'], $event['dimensions']['vendor_id']);
    }

    public function test_an_spm_on_own_fleet_has_no_vendor_bill(): void
    {
        $this->prices();
        $spm = $this->spm()['id'];
        $this->verifyAll($spm, 'BUY');
        $this->as($this->finance)->postJson("/api/v1/spm/{$spm}/vendor-bill", ['vendor_invoice_number' => 'X', 'amount_minor' => 100])->assertUnprocessable()->assertJsonValidationErrors('spm');
    }

    // ---------------------------------------------------------------- reports

    public function test_the_contribution_pnl_adds_up_verified_charges_and_shows_the_rest_beside_it(): void
    {
        $this->prices();
        $a = $this->spm(['project' => $this->m['proj']])['id'];
        $b = $this->spm()['id'];
        $this->verifyAll($a);
        $this->verifyAll($a, 'BUY');
        $this->verifyAll($b);

        $by = fn (string $dim) => $this->as($this->finance)->getJson("/api/v1/reports/pnl?group_by={$dim}")->assertOk()->json('data');
        $customer = $by('customer');
        $this->assertSame([4_400_000_00, 1_100_000_00, 3_300_000_00], [$customer['totals']['sell_minor'], $customer['totals']['buy_minor'], $customer['totals']['margin_minor']]);
        $this->assertSame(1, count($customer['rows']));
        $this->assertSame(7500, $customer['totals']['margin_bps']);
        $this->assertSame(1_100_000_00, $customer['totals']['unverified_buy_minor'], 'the buying charges of the second SPM wait');

        $spm = $by('spm');
        $this->assertCount(2, $spm['rows']);
        $this->assertSame($a, collect($spm['rows'])->firstWhere('margin_minor', 1_100_000_00)['key'] ?? $a);
        $project = $by('project');
        $this->assertEqualsCanonicalizing(['Proyek A', 'Tanpa proyek'], array_column($project['rows'], 'label'));
        $this->assertSame('Pool Jakarta → Gudang Bandung', $by('lane')['rows'][0]['label']);
        $this->assertSame('Armada sendiri', $by('vendor')['rows'][0]['label']);
        $this->assertSame('Tanpa kendaraan', $by('vehicle')['rows'][0]['label']);
        $plan = $by('customer')['rows'][0];
        $this->assertSame([4_400_000_00, 0, 1_100_000_00], [$plan['sell_plan_minor'], $plan['sell_unplan_minor'], $plan['buy_plan_minor']]);

        $csv = $this->as($this->finance)->get('/api/v1/reports/pnl?group_by=customer&format=csv')->assertOk()->assertDownload();
        $this->assertStringContainsString('PT Maju', $csv->baseResponse->getFile()->getContent());
        $this->as($this->dispatcher)->getJson('/api/v1/reports/pnl?group_by=customer')->assertForbidden();
        $this->as($this->finance)->getJson('/api/v1/reports/pnl?group_by=moon')->assertUnprocessable();
    }

    public function test_the_catalog_lists_what_the_user_may_open_and_aging_buckets_the_open_invoices(): void
    {
        $this->prices();
        $spm = $this->spm()['id'];
        $this->verifyAll($spm);
        $this->as($this->finance)->postJson('/api/v1/billing/batches', ['customer_id' => $this->m['cust']])->assertCreated();
        $keys = fn (User $u) => array_column($this->as($u)->getJson('/api/v1/reports')->assertOk()->json('data'), 'key');
        $this->assertEqualsCanonicalizing(['pnl', 'ready_to_bill', 'ar_aging', 'spm_status'], $keys($this->finance));
        $this->assertSame(['spm_status'], $keys($this->dispatcher));

        DB::table('ledger_documents')->where('kind', 'CUSTOMER_INVOICE')->update(['due_date' => now()->subDays(45)->toDateString()]);
        $aging = $this->as($this->finance)->getJson('/api/v1/reports/ar-aging')->assertOk()->json('data');
        $this->assertSame([2_200_000_00, 0], [$aging[0]['d31_60'], $aging[0]['current']]);
        $status = $this->as($this->dispatcher)->getJson('/api/v1/reports/spm-status')->assertOk()->json('data');
        $this->assertSame(1, $status['statuses']['DISCHARGED']);
    }

    public function test_the_work_queue_counts_what_waits_to_be_verified_and_billed(): void
    {
        $this->prices();
        $a = $this->spm()['id'];
        $this->spm();
        $queue = fn (User $u) => $this->as($u)->getJson('/api/v1/workqueue')->assertOk()->json('data');
        $this->assertSame([2, 2, 0], [$queue($this->finance)['charges_to_verify_sell'], $queue($this->finance)['charges_to_verify_buy'], $queue($this->finance)['ready_to_bill']]);
        $this->assertArrayNotHasKey('ready_to_bill', $queue($this->dispatcher));

        $this->verifyAll($a);
        $this->assertSame([1, 1], [$queue($this->finance)['charges_to_verify_sell'], $queue($this->finance)['ready_to_bill']]);
    }

    public function test_tenant_isolation_and_the_tax_rate_permission(): void
    {
        $this->prices();
        $spm = $this->spm()['id'];
        $this->verifyAll($spm);
        $other = User::factory()->create();
        $tenant2 = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $tenant2, 'slug' => 'other', 'name' => 'Other']);
        DB::table('memberships')->insert(['tenant_id' => $tenant2, 'user_id' => $other->id, 'role' => 'cfo', 'active' => true]);
        $this->actingAs($other)->withSession(['tenant_id' => $tenant2])->getJson('/api/v1/billing/ready')->assertOk()->assertJsonPath('data', []);
        $this->actingAs($other)->withSession(['tenant_id' => $tenant2])->postJson("/api/v1/spm/{$spm}/charges/verify", ['side' => 'SELL', 'decision' => 'VERIFY'])->assertNotFound();
        $this->as($this->finance)->putJson('/api/v1/billing/settings', ['tax_rate_bps' => 1200])->assertForbidden();
    }
}

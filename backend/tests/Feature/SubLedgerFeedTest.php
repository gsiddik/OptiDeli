<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Wave 5 (K2): the OptiDeli sub-ledger and the Opti Integration Feed v1. */
class SubLedgerFeedTest extends TestCase
{
    use RefreshDatabase;

    private string $tenant;

    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['optideli.deployment_mode' => 'saas', 'optideli.identity_mode' => 'local']);
        $this->tenant = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $this->tenant, 'slug' => 'ledger', 'name' => 'Ledger']);
    }

    private function asRole(string $role, ?string $tenant = null): User
    {
        $tenant ??= $this->tenant;
        $key = $tenant.$role;
        if (! isset($this->users[$key])) {
            $u = User::factory()->create();
            DB::table('memberships')->insert(['tenant_id' => $tenant, 'user_id' => $u->id, 'role' => $role, 'active' => true]);
            $this->users[$key] = $u;
        }
        $this->actingAs($this->users[$key])->withSession(['tenant_id' => $tenant]);

        return $this->users[$key];
    }

    private function create(string $path, array $data)
    {
        return $this->postJson('/api/v1/'.$path, $data, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /** A delivered order with a completed trip and a freight invoice that is ready for payment. */
    private function delivered(int $base = 1600000000, string $currency = 'IDR', ?int $invoiced = null): array
    {
        $this->asRole('dispatcher');
        $o = $this->create('orders', ['customer_name' => 'PT Pelanggan', 'stage' => 'MIDDLE_MILE', 'origin' => 'A', 'destination' => 'B', 'currency' => $currency, 'base_price_minor' => 2000000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'items' => [['description' => 'Box', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]]])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/orders/'.$o.'/transition', ['action' => 'confirm'])->assertOk();
        $v = (string) Str::uuid();
        $d = (string) Str::uuid();
        DB::table('vehicles')->insert(['id' => $v, 'tenant_id' => $this->tenant, 'plate' => Str::random(8), 'type' => 'Box', 'capacity_g' => 1000000, 'length_mm' => 4000, 'width_mm' => 2000, 'height_mm' => 2000, 'documents_valid_until' => now()->addYear()->toDateString(), 'readiness_checked_at' => now()]);
        DB::table('drivers')->insert(['id' => $d, 'tenant_id' => $this->tenant, 'name' => 'Driver', 'license_number' => Str::random(10), 'license_valid_until' => now()->addYear()->toDateString(), 'eligibility_verified_until' => now()->addDay(), 'active' => true]);
        $plan = $this->create('dispatch/plans', ['order_id' => $o, 'vehicle_id' => $v, 'driver_id' => $d, 'vendor_base_minor' => $base, 'vendor_accessorial_minor' => 0, 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(5)->toIso8601String(), 'load_safety_attestation' => 'Load inspection and securement verified by operator.'])->assertCreated()->json('data.id');
        $trip = $this->postJson('/api/v1/dispatch/plans/'.$plan.'/confirm')->assertOk()->json('data.id');
        $vendor = $this->create('vendors', ['name' => 'Vendor Angkut', 'code' => Str::random(8)])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/trips/'.$trip.'/vendor', ['vendor_id' => $vendor, 'contract_reference' => 'CONTRACT-TEST'])->assertOk();
        $leg = $this->postJson('/api/v1/trips/'.$trip.'/legs', ['origin' => 'A', 'destination' => 'B', 'planned_eta' => now()->addHours(4)->toIso8601String()])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/trips/'.$trip.'/transition', ['action' => 'start'])->assertOk();
        $this->postJson('/api/v1/legs/'.$leg.'/transition', ['action' => 'depart'])->assertOk();
        $this->postJson('/api/v1/legs/'.$leg.'/transition', ['action' => 'arrive'])->assertOk();
        $file = UploadedFile::fake()->create('proof.pdf', 2, 'application/pdf');
        $pod = $this->post('/api/v1/trips/'.$trip.'/pods', ['receiver_name' => 'Receiver', 'delivered_units' => '1', 'outcome' => 'DELIVERED', 'file' => $file], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->asRole('supervisor');
        $this->postJson('/api/v1/pods/'.$pod.'/verify', ['decision' => 'verify', 'note' => 'Delivery evidence checked independently.'])->assertOk();
        $this->asRole('dispatcher');
        $this->postJson('/api/v1/trips/'.$trip.'/transition', ['action' => 'complete'])->assertOk();
        $this->asRole('finance');
        $invoice = $this->post('/api/v1/freight/invoices', ['trip_id' => $trip, 'number' => 'INV-'.Str::random(8), 'base_minor' => $invoiced ?? $base, 'accessorial_minor' => 0, 'rounding_minor' => 0, 'document_reference' => 'Supplier invoice document', 'file' => UploadedFile::fake()->create('invoice.pdf', 2, 'application/pdf')], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        if ($invoiced === null) {
            $this->assertSame('READY_FOR_PAYMENT', DB::table('freight_invoices')->where('id', $invoice)->value('status'));
        }

        return compact('o', 'trip', 'vendor', 'invoice');
    }

    private function feedToken(?string $tenant = null): string
    {
        Artisan::call('optideli:feed:issue-credential', ['tenant' => $tenant ?? 'ledger', '--output' => $file = tempnam(sys_get_temp_dir(), 'feed')]);
        $token = trim(file_get_contents($file));
        unlink($file);

        return $token;
    }

    private function feed(string $token, string $query = '')
    {
        $this->app['auth']->forgetGuards();

        return $this->withSession([])->getJson('/api/v1/integration/feed'.$query, ['Authorization' => 'Bearer '.$token]);
    }

    public function test_customer_invoice_needs_delivered_order_and_finance_permission(): void
    {
        $f = $this->delivered();
        $this->asRole('dispatcher');
        $this->postJson('/api/v1/ledger/customer-invoices', ['order_id' => $f['o']])->assertForbidden();
        $this->asRole('finance');
        $draft = $this->create('orders', ['customer_name' => 'X', 'stage' => 'MIDDLE_MILE', 'origin' => 'A', 'destination' => 'B', 'currency' => 'IDR', 'base_price_minor' => 100000, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'items' => [['description' => 'Box', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]]]);
        $this->postJson('/api/v1/ledger/customer-invoices', ['order_id' => $draft->json('data.id')])->assertStatus(422);
        $sell = (int) DB::table('orders')->where('id', $f['o'])->value('sell_minor');
        $this->postJson('/api/v1/ledger/customer-invoices', ['order_id' => $f['o'], 'payment_terms_days' => 14])->assertCreated()
            ->assertJsonPath('data.number', 'AR-000001')->assertJsonPath('data.status', 'OPEN')->assertJsonPath('data.amount_minor', $sell);
        // Replay returns the live document instead of issuing a second invoice.
        $this->postJson('/api/v1/ledger/customer-invoices', ['order_id' => $f['o']])->assertOk()->assertJsonPath('data.number', 'AR-000001');
        $this->assertSame(1, DB::table('ledger_documents')->count());
    }

    public function test_vendor_bill_requires_ready_for_payment_invoice(): void
    {
        $f = $this->delivered(1600000000, 'IDR', 1600000100); // a base-rate mismatch holds the invoice
        $this->asRole('finance');
        $held = DB::table('freight_invoices')->where('id', $f['invoice'])->value('status');
        $this->assertSame('ON_HOLD', $held);
        $this->postJson('/api/v1/ledger/vendor-bills', ['freight_invoice_id' => $f['invoice']])->assertStatus(422);
    }

    public function test_payments_settle_a_document_and_cannot_overpay(): void
    {
        $f = $this->delivered();
        $this->asRole('finance');
        $bill = $this->postJson('/api/v1/ledger/vendor-bills', ['freight_invoice_id' => $f['invoice']])->assertCreated()->assertJsonPath('data.number', 'AP-000001')->json('data');
        $this->assertSame(1600000000, $bill['amount_minor']);
        $pay = fn (int $amount, string $ref) => $this->postJson('/api/v1/ledger/documents/'.$bill['id'].'/payments', ['amount_minor' => $amount, 'paid_on' => now()->toDateString(), 'method' => 'BANK_TRANSFER', 'reference' => $ref]);
        $pay(1000000000, 'TRF-1')->assertCreated()->assertJsonPath('data.direction', 'DISBURSEMENT');
        $this->assertSame('PARTIAL', DB::table('ledger_documents')->where('id', $bill['id'])->value('status'));
        $pay(1000000000, 'TRF-2')->assertStatus(422); // more than the 600000000 outstanding
        $pay(1000000000, 'TRF-1')->assertOk(); // same reference replayed: no second payment
        $this->postJson('/api/v1/ledger/documents/'.$bill['id'].'/payments', ['amount_minor' => 5, 'paid_on' => now()->toDateString(), 'method' => 'CASH', 'reference' => 'TRF-1'])->assertConflict();
        $pay(600000000, 'TRF-3')->assertCreated();
        $this->assertSame('PAID', DB::table('ledger_documents')->where('id', $bill['id'])->value('status'));
        $this->assertSame(2, DB::table('ledger_payments')->count());
        $this->postJson('/api/v1/ledger/documents/'.$bill['id'].'/void', ['reason' => 'Salah catat'])->assertStatus(422); // paid documents are not voided
        $this->getJson('/api/v1/ledger/summary')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_void_frees_the_source_and_is_published(): void
    {
        $f = $this->delivered();
        $this->asRole('finance');
        $doc = $this->postJson('/api/v1/ledger/customer-invoices', ['order_id' => $f['o']])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/ledger/documents/'.$doc.'/void', ['reason' => 'Salah pelanggan'])->assertOk()->assertJsonPath('data.status', 'VOID');
        $this->postJson('/api/v1/ledger/documents/'.$doc.'/void', ['reason' => 'Salah pelanggan'])->assertConflict();
        $this->postJson('/api/v1/ledger/customer-invoices', ['order_id' => $f['o']])->assertCreated()->assertJsonPath('data.number', 'AR-000002');
        $this->assertSame(['optideli.customer_invoice.issued', 'optideli.document.voided', 'optideli.customer_invoice.issued'], DB::table('integration_feed_events')->orderBy('sequence')->pluck('event_type')->all());
    }

    public function test_feed_is_gap_free_decimal_and_tenant_scoped(): void
    {
        $f = $this->delivered(1600000000);
        $this->asRole('finance');
        $doc = $this->postJson('/api/v1/ledger/customer-invoices', ['order_id' => $f['o']])->assertCreated()->json('data');
        $this->postJson('/api/v1/ledger/documents/'.$doc['id'].'/payments', ['amount_minor' => 12345, 'paid_on' => now()->toDateString(), 'method' => 'BANK_TRANSFER', 'reference' => 'RCV-1'])->assertCreated();

        $other = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $other, 'slug' => 'other', 'name' => 'Other']);
        DB::table('integration_feed_counters')->insert(['tenant_id' => $other, 'last_sequence' => 1]);
        DB::table('integration_feed_events')->insert(['event_id' => (string) Str::uuid(), 'tenant_id' => $other, 'sequence' => 1, 'event_type' => 'optideli.document.voided', 'schema_version' => '1.0', 'occurred_at' => now(), 'correlation_id' => 'x', 'source' => '{}', 'dimensions' => '{}', 'payload' => '{}']);

        $token = $this->feedToken();
        $page = $this->feed($token, '?limit=1')->assertOk()->assertJsonPath('has_more', true)->assertJsonPath('next_cursor', 1)->assertJsonPath('high_water', 2);
        $this->assertSame('optideli.customer_invoice.issued', $page->json('data.0.event_type'));
        $this->assertSame('optideli', $page->json('data.0.source_system'));
        $this->assertSame('AR-000001', $page->json('data.0.source.number'));
        $this->assertSame($f['o'], $page->json('data.0.dimensions.order_id'));
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $page->json('data.0.payload.amount'));
        $second = $this->feed($token, '?after=1')->assertOk()->assertJsonPath('has_more', false)->assertJsonPath('next_cursor', 2);
        $this->assertSame('123.45', $second->json('data.0.payload.amount'));
        $this->assertSame('RECEIPT', $second->json('data.0.payload.direction'));
        $this->assertCount(0, $this->feed($token, '?after=2')->json('data'));
        $this->assertSame(['optideli.customer_invoice.issued', 'optideli.payment.recorded'], collect($second->json('data'))->pluck('event_type')->prepend('optideli.customer_invoice.issued')->all());
    }

    public function test_feed_credentials_are_validated_and_revocable(): void
    {
        $this->asRole('finance');
        $this->getJson('/api/v1/integration/feed')->assertUnauthorized();
        $this->feed('odfd_wrong')->assertUnauthorized();
        $token = $this->feedToken();
        $this->feed($token)->assertOk();
        $id = DB::table('integration_feed_credentials')->value('id');
        $this->assertSame(hash('sha256', $token), DB::table('integration_feed_credentials')->value('token_hash'));
        Artisan::call('optideli:feed:revoke-credential', ['id' => $id]);
        $this->feed($token)->assertUnauthorized();
        $expired = $this->feedToken();
        DB::table('integration_feed_credentials')->where('token_hash', hash('sha256', $expired))->update(['expires_at' => now()->subMinute()]);
        $this->feed($expired)->assertUnauthorized();
        $live = $this->feedToken();
        DB::table('tenants')->where('id', $this->tenant)->update(['active' => false]);
        $this->feed($live)->assertForbidden();
    }

    public function test_payments_and_feed_events_are_append_only_on_postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Append-only triggers exist on PostgreSQL only.');
        }
        $f = $this->delivered();
        $this->asRole('finance');
        $doc = $this->postJson('/api/v1/ledger/customer-invoices', ['order_id' => $f['o']])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/ledger/documents/'.$doc.'/payments', ['amount_minor' => 100, 'paid_on' => now()->toDateString(), 'method' => 'CASH', 'reference' => 'R1'])->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('ledger_payments')->update(['amount_minor' => 1]);
    }
}

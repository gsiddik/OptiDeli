<?php

namespace Tests\Feature;

use App\Domain\Integration\Master\MasterRelay;
use App\Domain\Integration\Pickup\PickupFeedback;
use App\Domain\Master\MasterDefaults;
use App\Domain\Shared\TenantStore;
use App\Domain\Spm\SpmService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** Pickup requests from OptiStash: an inbox that becomes an order with an SPM once the warehouse is linked and the defaults are known. */
class PickupRequestsTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private const SECRET = 'whsec_test_secret';

    private const NEXUS_TENANT = '6f1d2c3b-1111-4a5b-8c9d-0123456789ab';

    private string $tenant;

    private string $customer;

    private string $service;

    private string $warehouse;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();
        config(['optideli.deployment_mode' => 'saas', 'optideli.identity_mode' => 'local', 'optideli.optinexus.events.webhook_secret' => self::SECRET]);
        Cache::flush();
        $this->tenant = $this->signInTenantMember('dispatcher');
        DB::table('tenants')->where('id', $this->tenant)->update(['optinexus_tenant_id' => self::NEXUS_TENANT]);
        MasterDefaults::seed($this->tenant);
        $this->customer = $this->master('customers', ['code' => 'CUST', 'name' => 'PT Maju', 'payment_terms_days' => 14]);
        $this->warehouse = $this->master('locations', ['code' => 'GD-CKR', 'name' => 'Gudang Cikarang', 'type' => 'WAREHOUSE']);
        $this->destination = $this->master('locations', ['code' => 'TOKO-A', 'name' => 'Toko A', 'type' => 'CUSTOMER']);
        $this->service = (string) DB::table('services')->where('tenant_id', $this->tenant)->where('code', 'NORMAL')->value('id');
    }

    private function master(string $resource, array $body): string
    {
        return $this->postJson("/api/v1/master/{$resource}", $body, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    }

    private function event(string $shipment = 'SHP-000001', ?array $items = null, string $warehouseCode = 'WH1', ?string $id = null): array
    {
        return [
            'event_id' => $id ?? (string) Str::uuid(), 'event_type' => 'optistash.pickup.requested', 'schema_version' => '1', 'source_system' => 'optistash',
            'tenant_id' => self::NEXUS_TENANT, 'occurred_at' => now()->toIso8601String(), 'correlation_id' => (string) Str::uuid(),
            'data' => ['aggregate_type' => 'shipment', 'aggregate_id' => (string) Str::uuid(), 'correlation' => [], 'payload' => [
                'shipment_number' => $shipment,
                'warehouse' => ['code' => $warehouseCode, 'name' => 'Gudang Cikarang', 'address' => 'Jl. Industri 1', 'city' => 'Bekasi'],
                'items' => $items ?? [['product_code' => 'SKU-1', 'product_name' => 'Susu UHT', 'quantity' => 30, 'uom' => 'PCS'], ['product_code' => 'SKU-2', 'product_name' => 'Teh Botol', 'quantity' => 12, 'uom' => 'DUS']],
            ]],
        ];
    }

    private function deliver(array $event, string $secret = self::SECRET): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $id = (string) Str::uuid();
        $ts = (string) time();

        return $this->call('POST', '/api/v1/integration/optinexus/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_OPTI_WEBHOOK_ID' => $id, 'HTTP_OPTI_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_OPTI_WEBHOOK_SIGNATURE' => 'v1='.hash_hmac('sha256', $id.'.'.$ts.'.'.$body, $secret),
        ], $body);
    }

    private function requestId(string $shipment = 'SHP-000001'): string
    {
        return (string) DB::table('pickup_requests')->where('tenant_id', $this->tenant)->where('shipment_number', $shipment)->value('id');
    }

    private function defaults(bool $auto = true): array
    {
        return ['auto_order' => $auto, 'customer_id' => $this->customer, 'service_id' => $this->service, 'destination_location_id' => $this->destination, 'stage' => 'LAST_MILE'];
    }

    public function test_only_a_signed_event_of_a_known_tenant_is_accepted_and_a_bad_payload_is_refused(): void
    {
        $this->deliver($this->event(), 'wrong-secret')->assertUnauthorized();
        $this->assertSame(0, DB::table('pickup_requests')->count());

        $unknown = $this->event();
        $unknown['tenant_id'] = '11111111-2222-4333-8444-555555555555';
        $this->deliver($unknown)->assertStatus(202)->assertJson(['result' => 'ignored']);
        $this->assertSame(0, DB::table('pickup_requests')->count());

        $other = $this->event();
        $other['source_system'] = 'optifleet';
        $this->deliver($other)->assertStatus(202)->assertJson(['result' => 'ignored']);

        foreach ([
            $this->event('SHP-X', [['product_code' => 'A', 'product_name' => 'A', 'quantity' => 0, 'uom' => 'PCS']]),
            $this->event('SHP-X', [['product_code' => 'A', 'product_name' => 'A', 'quantity' => 1.5, 'uom' => 'PCS']]),
            $this->event('SHP-X', []),
            $this->event('SHP-X', null, ''),
        ] as $bad) {
            $this->deliver($bad)->assertStatus(422)->assertJson(['code' => 'PICKUP_REQUEST_INVALID']);
        }
        $this->assertSame(0, DB::table('pickup_requests')->count());
    }

    public function test_a_request_lands_in_the_inbox_once_even_when_delivered_again(): void
    {
        $event = $this->event();
        $this->deliver($event)->assertStatus(202)->assertJson(['result' => 'created']);
        $this->deliver($event)->assertStatus(202)->assertJson(['result' => 'duplicate']);

        $this->assertSame(1, DB::table('pickup_requests')->count());
        $list = $this->getJson('/api/v1/pickup-requests?status=RECEIVED')->assertOk()->json('data');
        $this->assertSame([['SHP-000001', 'RECEIVED', false, 2, 42]], array_map(fn ($r) => [$r['shipment_number'], $r['status'], $r['warehouse_linked'], $r['lines'], $r['total_quantity']], $list));
        $detail = $this->getJson('/api/v1/pickup-requests/'.$this->requestId())->assertOk()->json('data');
        $this->assertSame(['code' => 'WH1', 'name' => 'Gudang Cikarang', 'address' => 'Jl. Industri 1', 'city' => 'Bekasi'], $detail['warehouse']);
    }

    public function test_the_warehouse_has_to_be_linked_before_an_order_can_be_made(): void
    {
        $this->deliver($this->event());
        $id = $this->requestId();
        $body = ['customer_id' => $this->customer, 'service_id' => $this->service, 'destination_location_id' => $this->destination, 'stage' => 'LAST_MILE'];
        $this->postJson("/api/v1/pickup-requests/{$id}/convert", $body)->assertUnprocessable();
        $this->assertSame(0, DB::table('orders')->count());

        // Only a warehouse location can be linked, and a location only to one warehouse.
        $this->postJson("/api/v1/pickup-requests/{$id}/warehouse", ['location_id' => $this->destination])->assertUnprocessable();
        $this->postJson("/api/v1/pickup-requests/{$id}/warehouse", ['location_id' => $this->warehouse])->assertOk()->assertJsonPath('data.warehouse_location.name', 'Gudang Cikarang');
        $this->assertSame('optistash:WH1', DB::table('locations')->where('id', $this->warehouse)->value('external_ref'));
        $this->deliver($this->event('SHP-000002', null, 'WH2'));
        $this->postJson('/api/v1/pickup-requests/'.$this->requestId('SHP-000002').'/warehouse', ['location_id' => $this->warehouse])->assertUnprocessable();

        $res = $this->postJson("/api/v1/pickup-requests/{$id}/convert", $body)->assertOk();
        $res->assertJsonPath('data.status', 'CONVERTED');
        $order = DB::table('orders')->where('tenant_id', $this->tenant)->sole();
        $this->assertSame(['CONFIRMED', 'SHP-000001', $this->warehouse, $this->destination, $this->customer, 0], [$order->status, $order->reference, $order->origin_location_id, $order->destination_location_id, $order->customer_id, (int) $order->sell_minor]);
        $spm = DB::table('transport_orders')->where('tenant_id', $this->tenant)->sole();
        $this->assertSame(['DRAFT', $order->id, $this->warehouse], [$spm->status, $spm->order_id, $spm->origin_location_id]);
        $lines = DB::table('shipment_lines')->where('transport_order_id', $spm->id)->orderBy('line_no')->get();
        $this->assertSame([['Susu UHT', '30.000'], ['Teh Botol', '12.000']], $lines->map(fn ($l) => [$l->material, $l->quantity])->all());
        // The unit is matched by code (PCS exists), an unknown one (DUS) stays free text in the note.
        $this->assertSame((string) DB::table('units')->where('tenant_id', $this->tenant)->where('code', 'PCS')->value('id'), $lines[0]->unit_id);
        $this->assertNull($lines[1]->unit_id);
        $this->assertStringContainsString('SKU-2', $lines[1]->notes);
        $this->assertSame($spm->id, DB::table('pickup_requests')->where('id', $id)->value('transport_order_id'));

        // Once handled, it cannot be handled again.
        $this->postJson("/api/v1/pickup-requests/{$id}/convert", $body)->assertStatus(409);
        $this->postJson("/api/v1/pickup-requests/{$id}/reject", ['reason' => 'tidak jadi'])->assertStatus(409);
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_with_the_defaults_set_a_request_becomes_an_order_by_itself(): void
    {
        // A request that waits for the warehouse to be linked says why, and is made as soon as the link exists.
        $this->putJson('/api/v1/pickup-settings', $this->defaults())->assertOk();
        $this->deliver($this->event())->assertStatus(202)->assertJson(['result' => 'created']);
        $id = $this->requestId();
        $row = DB::table('pickup_requests')->where('id', $id)->first();
        $this->assertSame('RECEIVED', $row->status);
        $this->assertNotNull($row->auto_failure);

        $this->postJson("/api/v1/pickup-requests/{$id}/warehouse", ['location_id' => $this->warehouse])->assertOk()->assertJsonPath('data.status', 'CONVERTED');
        $this->assertSame(1, DB::table('transport_orders')->count());

        // From then on a request from that warehouse is converted when it arrives, in the name of whoever saved the defaults.
        $this->deliver($this->event('SHP-000002'))->assertStatus(202)->assertJson(['result' => 'converted']);
        $this->assertSame(2, DB::table('orders')->count());
        $this->assertSame([auth()->id()], DB::table('orders')->pluck('created_by')->unique()->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['CONVERTED', 'CONVERTED'], DB::table('pickup_requests')->orderBy('shipment_number')->pluck('status')->all());

        // Switching it off sends the next one to the inbox.
        $this->putJson('/api/v1/pickup-settings', $this->defaults(false))->assertOk();
        $this->deliver($this->event('SHP-000003'))->assertJson(['result' => 'created']);
        $this->assertSame('RECEIVED', DB::table('pickup_requests')->where('shipment_number', 'SHP-000003')->value('status'));
    }

    public function test_the_defaults_must_be_complete_and_real_to_switch_automatic_orders_on(): void
    {
        $this->putJson('/api/v1/pickup-settings', ['auto_order' => true, 'customer_id' => $this->customer, 'stage' => 'LAST_MILE'])->assertUnprocessable()->assertJsonValidationErrors('auto_order');
        $this->putJson('/api/v1/pickup-settings', [...$this->defaults(), 'customer_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->putJson('/api/v1/pickup-settings', [...$this->defaults(), 'stage' => 'SOMEWHERE'])->assertUnprocessable();
        $this->putJson('/api/v1/pickup-settings', $this->defaults())->assertOk()->assertJsonPath('data.auto_order', true);
        $this->getJson('/api/v1/pickup-settings')->assertOk()->assertJsonPath('data.customer_id', $this->customer);
    }

    public function test_a_dispatcher_can_reject_with_a_reason(): void
    {
        $this->deliver($this->event());
        $id = $this->requestId();
        $this->postJson("/api/v1/pickup-requests/{$id}/reject", ['reason' => 'x'])->assertUnprocessable();
        $this->postJson("/api/v1/pickup-requests/{$id}/reject", ['reason' => 'Barang sudah diambil sendiri'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $this->assertSame('Barang sudah diambil sendiri', DB::table('pickup_requests')->where('id', $id)->value('reason'));
        $this->postJson("/api/v1/pickup-requests/{$id}/convert", ['customer_id' => $this->customer, 'service_id' => $this->service, 'destination_location_id' => $this->destination, 'stage' => 'LAST_MILE'])->assertStatus(409);
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $this->deliver($this->event());
        $id = $this->requestId();

        $viewer = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $viewer->id, 'role' => 'viewer', 'active' => true]);
        $this->actingAs($viewer)->withSession(['tenant_id' => $this->tenant]);
        $this->getJson('/api/v1/pickup-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/pickup-requests/{$id}/warehouse", ['location_id' => $this->warehouse])->assertForbidden();
        $this->postJson("/api/v1/pickup-requests/{$id}/reject", ['reason' => 'Tidak berwenang'])->assertForbidden();
        $this->putJson('/api/v1/pickup-settings', $this->defaults())->assertForbidden();

        // Another tenant neither sees nor handles it.
        $other = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $other, 'slug' => 'lain', 'name' => 'Lain']);
        $stranger = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $other, 'user_id' => $stranger->id, 'role' => 'dispatcher', 'active' => true]);
        $this->actingAs($stranger)->withSession(['tenant_id' => $other]);
        $this->getJson('/api/v1/pickup-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/pickup-requests/{$id}")->assertNotFound();
        $this->postJson("/api/v1/pickup-requests/{$id}/reject", ['reason' => 'Bukan milik saya'])->assertNotFound();
    }

    private function feedback(): array
    {
        return DB::table('master_events')->where('event_key', 'optideli.pickup.status_changed')->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($e) => json_decode($e->payload, true))->map(fn ($p) => [$p['shipment_number'], $p['status'], $p['reason'] ?? null])->sort()->values()->all();
    }

    private function convertLinked(string $shipment): string
    {
        $this->deliver($this->event($shipment));
        $id = $this->requestId($shipment);
        if (DB::table('locations')->where('id', $this->warehouse)->value('external_ref') === null) {
            $this->postJson("/api/v1/pickup-requests/{$id}/warehouse", ['location_id' => $this->warehouse])->assertOk();
        }
        $this->postJson("/api/v1/pickup-requests/{$id}/convert", ['customer_id' => $this->customer, 'service_id' => $this->service, 'destination_location_id' => $this->destination, 'stage' => 'LAST_MILE'])->assertOk();

        return (string) DB::table('pickup_requests')->where('id', $id)->value('transport_order_id');
    }

    public function test_optistash_is_told_what_happens_to_its_pickup_request(): void
    {
        $spm = $this->convertLinked('SHP-000001');
        $this->assertSame([['SHP-000001', 'ACCEPTED', null]], $this->feedback());

        $service = new SpmService(new TenantStore($this->tenant, (int) auth()->id()));
        // Planning steps say nothing to the warehouse; leaving on the trip and unloading do.
        DB::table('transport_orders')->where('id', $spm)->update(['status' => 'LOADING']);
        $service->transition($spm, 'IN_TRANSIT', null);
        $service->transition($spm, 'DISCHARGED', null);
        $service->transition($spm, 'DOC_VERIFIED', null);
        $this->assertSame([['SHP-000001', 'ACCEPTED', null], ['SHP-000001', 'DELIVERED', null], ['SHP-000001', 'PICKED_UP', null]], $this->feedback());

        // A cancelled SPM is reported without its internal reason; a rejected request with the dispatcher's reason.
        $cancelled = $this->convertLinked('SHP-000002');
        $service->transition($cancelled, 'CANCELLED', 'Biaya tidak cocok dengan tarif');
        $this->deliver($this->event('SHP-000003'));
        $this->postJson('/api/v1/pickup-requests/'.$this->requestId('SHP-000003').'/reject', ['reason' => 'Barang sudah diambil sendiri'])->assertOk();
        $this->assertSame([['SHP-000002', 'ACCEPTED', null], ['SHP-000002', 'CANCELLED', null], ['SHP-000003', 'REJECTED', 'Barang sudah diambil sendiri']], array_slice($this->feedback(), 3));
    }

    public function test_an_ordinary_spm_or_an_unlinked_tenant_tells_nobody(): void
    {
        $order = (string) Str::uuid();
        DB::table('orders')->insert(['id' => $order, 'tenant_id' => $this->tenant, 'number' => 'OD-X', 'customer_id' => $this->customer, 'customer_name' => 'PT Maju', 'stage' => 'LAST_MILE', 'origin' => 'A', 'destination' => 'B',
            'currency' => 'IDR', 'base_price_minor' => 0, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'sell_minor' => 0, 'status' => 'CONFIRMED', 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        $service = new SpmService(new TenantStore($this->tenant, (int) auth()->id()));
        $service->create($order, ['lines' => [['material' => 'Kayu', 'quantity' => 1]]]);
        $spm = (string) DB::table('transport_orders')->where('order_id', $order)->value('id');
        $service->transition($spm, 'CANCELLED', 'Dibatalkan pelanggan');
        $this->assertSame([], $this->feedback());

        DB::table('tenants')->where('id', $this->tenant)->update(['optinexus_tenant_id' => null]);
        $this->assertFalse(PickupFeedback::publish($this->tenant, (string) Str::uuid(), 'ACCEPTED'));
        $this->assertSame([], $this->feedback());
    }

    public function test_the_status_reaches_optinexus_as_an_event_about_a_shipment(): void
    {
        config(['optideli.optinexus.base_url' => 'http://nexus.test', 'optideli.optinexus.service.client_id' => 'svc', 'optideli.optinexus.service.client_secret' => 'secret']);
        $this->convertLinked('SHP-000001');
        Http::fake(['nexus.test/api/v1/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]), 'nexus.test/api/v1/events' => Http::response([], 202)]);

        $this->assertSame(1, app(MasterRelay::class)->relay()['delivered']);
        Http::assertSent(fn ($r) => $r->url() === 'http://nexus.test/api/v1/events' && $r['event_key'] === 'optideli.pickup.status_changed' && $r['tenant_id'] === self::NEXUS_TENANT
            && $r['data']['aggregate_type'] === 'shipment' && $r['data']['payload']['status'] === 'ACCEPTED' && $r['data']['payload']['shipment_number'] === 'SHP-000001');
    }
}

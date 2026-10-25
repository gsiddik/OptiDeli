<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** An order can point at the customer, project, service and locations of the master data; its free text stays a snapshot. */
class OrderMasterLinkTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('dispatcher');
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function master(string $resource, array $data): array
    {
        return $this->postJson("/api/v1/master/{$resource}", $data, $this->key())->assertCreated()->json('data');
    }

    private function order(array $override = []): array
    {
        return array_replace([
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100000000, 'surcharge_minor' => 0, 'accessorial_minor' => 0,
            'items' => [['description' => 'Palet', 'quantity' => 2, 'unit_weight_g' => 10000, 'length_mm' => 1000, 'width_mm' => 1000, 'height_mm' => 1000, 'stackable' => true]],
        ], $override);
    }

    public function test_an_order_made_from_master_data_snapshots_the_names(): void
    {
        $customer = $this->master('customers', ['code' => 'C1', 'name' => 'PT Maju Jaya', 'payment_terms_days' => 30]);
        $project = $this->master('customer_projects', ['code' => 'P1', 'name' => 'Proyek 1', 'customer_id' => $customer['id']]);
        $from = $this->master('locations', ['code' => 'POOL', 'name' => 'Pool Jakarta', 'type' => 'POOL']);
        $to = $this->master('locations', ['code' => 'WH', 'name' => 'Gudang Bandung', 'type' => 'WAREHOUSE']);
        $service = $this->master('services', ['code' => 'REG', 'name' => 'Reguler', 'sla_hours' => 48]);

        $order = $this->postJson('/api/v1/orders', $this->order([
            'customer_id' => $customer['id'], 'project_id' => $project['id'], 'service_id' => $service['id'],
            'origin_location_id' => $from['id'], 'destination_location_id' => $to['id'],
        ]), $this->key())->assertCreated()->json('data');

        $this->assertSame('PT Maju Jaya', $order['customer_name']);
        $this->assertSame('Pool Jakarta', $order['origin']);
        $this->assertSame('Gudang Bandung', $order['destination']);
        $this->assertSame($customer['id'], $order['customer_id']);

        $shown = $this->getJson('/api/v1/orders/'.$order['id'])->assertOk()->json('data');
        $this->assertSame('C1 · PT Maju Jaya', $shown['customer_id_label']);
        $this->assertSame('P1 · Proyek 1', $shown['project_id_label']);
        $this->assertSame('REG · Reguler', $shown['service_id_label']);
        $this->assertSame('WH · Gudang Bandung', $shown['destination_location_id_label']);

        $listed = $this->getJson('/api/v1/orders')->assertOk()->json('data.0');
        $this->assertSame('C1 · PT Maju Jaya', $listed['customer_id_label']);

        DB::table('customers')->where('id', $customer['id'])->update(['name' => 'PT Maju Makmur']);
        $this->assertSame('PT Maju Jaya', DB::table('orders')->where('id', $order['id'])->value('customer_name'), 'the order keeps the name it was made with');
    }

    public function test_the_master_wins_over_text_sent_with_the_same_order(): void
    {
        $customer = $this->master('customers', ['code' => 'C1', 'name' => 'PT Maju Jaya', 'payment_terms_days' => 30]);

        $order = $this->postJson('/api/v1/orders', $this->order(['customer_id' => $customer['id'], 'customer_name' => 'Nama lain', 'origin' => 'Jakarta', 'destination' => 'Bandung']), $this->key())
            ->assertCreated()->json('data');

        $this->assertSame('PT Maju Jaya', $order['customer_name']);
        $this->assertSame('Jakarta', $order['origin']);
    }

    public function test_an_order_without_master_data_still_works_as_before(): void
    {
        $order = $this->postJson('/api/v1/orders', $this->order(['customer_name' => 'PT Lama', 'origin' => 'Jakarta', 'destination' => 'Bandung']), $this->key())->assertCreated()->json('data');

        $this->assertNull($order['customer_id']);
        $this->assertSame('PT Lama', $order['customer_name']);
        $this->postJson('/api/v1/orders', $this->order(['origin' => 'Jakarta', 'destination' => 'Bandung']), $this->key())->assertUnprocessable()->assertJsonValidationErrors('customer_name');
        $this->postJson('/api/v1/orders', $this->order(['customer_name' => 'PT Lama']), $this->key())->assertUnprocessable()->assertJsonValidationErrors(['origin', 'destination']);
    }

    public function test_references_must_be_active_rows_of_this_tenant_and_the_project_must_fit_the_customer(): void
    {
        $customer = $this->master('customers', ['code' => 'C1', 'name' => 'PT Satu', 'payment_terms_days' => 30]);
        $other = $this->master('customers', ['code' => 'C2', 'name' => 'PT Dua', 'payment_terms_days' => 30]);
        $project = $this->master('customer_projects', ['code' => 'P1', 'name' => 'Proyek 1', 'customer_id' => $customer['id']]);
        $base = ['origin' => 'A', 'destination' => 'B'];

        $this->postJson('/api/v1/orders', $this->order($base + ['customer_id' => $other['id'], 'project_id' => $project['id']]), $this->key())
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->postJson('/api/v1/orders', $this->order($base + ['customer_name' => 'X', 'project_id' => $project['id']]), $this->key())
            ->assertUnprocessable()->assertJsonValidationErrors('customer_id');

        $foreignTenant = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $foreignTenant, 'slug' => 'other', 'name' => 'Other']);
        $foreign = (string) Str::uuid();
        DB::table('customers')->insert(['id' => $foreign, 'tenant_id' => $foreignTenant, 'code' => 'F', 'name' => 'Foreign', 'payment_terms_days' => 30]);
        $this->postJson('/api/v1/orders', $this->order($base + ['customer_id' => $foreign]), $this->key())->assertUnprocessable()->assertJsonValidationErrors('customer_id');

        $this->postJson("/api/v1/master/customers/{$customer['id']}/status", ['active' => false, 'reason' => 'tidak aktif lagi'], $this->key())->assertOk();
        $this->postJson('/api/v1/orders', $this->order($base + ['customer_id' => $customer['id']]), $this->key())->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    }

    public function test_repeating_the_same_request_returns_the_same_order(): void
    {
        $customer = $this->master('customers', ['code' => 'C1', 'name' => 'PT Satu', 'payment_terms_days' => 30]);
        $key = $this->key();
        $payload = $this->order(['customer_id' => $customer['id'], 'origin' => 'A', 'destination' => 'B']);

        $first = $this->postJson('/api/v1/orders', $payload, $key)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/orders', $payload, $key)->assertCreated()->assertJsonPath('data.id', $first);
        $this->assertSame(1, DB::table('orders')->where('tenant_id', $this->tenant)->count());
    }
}

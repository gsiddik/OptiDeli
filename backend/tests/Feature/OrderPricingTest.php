<?php

namespace Tests\Feature;

use App\Domain\Master\MasterDefaults;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** Orders priced from the transporter price mapping: transporter, vehicle, unit per cargo line, read-only price, manual price with approval, lock at confirmation. */
class OrderPricingTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private User $dispatcher;

    private User $supervisor;

    /** @var array<string, string> */
    private array $m = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('dispatcher');
        MasterDefaults::seed($this->tenant);
        DB::table('tenants')->where('id', $this->tenant)->update(['minimum_margin_bps' => 0]);
        $this->dispatcher = auth()->user();
        $this->supervisor = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $this->supervisor->id, 'role' => 'supervisor', 'active' => true]);

        $this->m['cust'] = $this->master('customers', ['code' => 'CUST', 'name' => 'PT Maju', 'payment_terms_days' => 30]);
        $this->m['cust2'] = $this->master('customers', ['code' => 'CUST2', 'name' => 'PT Lain', 'payment_terms_days' => 30]);
        $region = $this->master('regions', ['code' => '32', 'name' => 'Jawa Barat']);
        $this->m['bdg'] = $this->master('cities', ['code' => '32.73', 'name' => 'Bandung', 'region_id' => $region]);
        $this->m['jkt'] = $this->master('cities', ['code' => '31.71', 'name' => 'Jakarta', 'region_id' => $region]);
        $this->m['pool'] = $this->master('locations', ['code' => 'POOL', 'name' => 'Pool Jakarta', 'type' => 'POOL', 'city_id' => $this->m['jkt']]);
        $this->m['wh'] = $this->master('locations', ['code' => 'WH', 'name' => 'Gudang Bandung', 'type' => 'WAREHOUSE', 'city_id' => $this->m['bdg']]);
        $this->m['cde'] = $this->master('carrier_types', ['code' => 'CDE', 'name' => 'CDE']);
        $this->m['cdd'] = $this->master('carrier_types', ['code' => 'CDD', 'name' => 'CDD']);
        $this->m['vendor'] = (string) Str::uuid();
        DB::table('vendors')->insert(['id' => $this->m['vendor'], 'tenant_id' => $this->tenant, 'name' => 'CV Vendor', 'code' => 'VND', 'active' => true]);
        $this->m['FREIGHT'] = DB::table('charge_codes')->where('tenant_id', $this->tenant)->where('code', 'FREIGHT')->value('id');
        foreach (['PCS', 'PALLET', 'DRUM'] as $code) {
            $this->m[$code] = DB::table('units')->where('tenant_id', $this->tenant)->where('code', $code)->value('id');
        }
        $this->m['truckVendor'] = $this->vehicle('B 1 VND', $this->m['vendor'], $this->m['cde']);
        $this->m['truckOwn'] = $this->vehicle('B 2 OWN', null, $this->m['cde']);
        $this->m['truckNoType'] = $this->vehicle('B 3 NOT', $this->m['vendor'], null);

        // The vendor's mapping: CDE pallet 1.000.000 + 15%, CDE pcs 10.000 + 20%; the own fleet's: CDE pallet 900.000 + 10%.
        $this->mapping($this->m['vendor'], [['PALLET', 'cde', 1_000_000_00, 1500], ['PCS', 'cde', 10_000_00, 2000]], 'MAPV');
        $this->mapping(null, [['PALLET', 'cde', 900_000_00, 1000]], 'MAPO');
    }

    private function master(string $resource, array $body): string
    {
        return $this->postJson("/api/v1/master/{$resource}", $body, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    }

    private function as(User $user)
    {
        return $this->actingAs($user)->withSession(['tenant_id' => $this->tenant]);
    }

    private function vehicle(string $plate, ?string $vendor, ?string $type): string
    {
        $id = (string) Str::uuid();
        DB::table('vehicles')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'plate' => $plate, 'type' => 'Box', 'capacity_g' => 1_000_000, 'length_mm' => 4000, 'width_mm' => 2000, 'height_mm' => 2000, 'status' => 'AVAILABLE',
            'documents_valid_until' => now()->addYear()->toDateString(), 'readiness_checked_at' => now(), 'vendor_id' => $vendor, 'carrier_type_id' => $type, 'active' => true,
            'verification_status' => 'VERIFIED', 'verified_by' => $vendor === null ? null : $this->dispatcher->id, 'verified_at' => $vendor === null ? null : now()]);

        return $id;
    }

    /** @param list<array{0: string, 1: string, 2: int, 3: int}> $rows unit code, fleet type key, initial rate, margin in basis points */
    private function mapping(?string $vendor, array $rows, string $code): string
    {
        $t = $this->as($this->dispatcher)->postJson('/api/v1/tariffs', ['code' => $code, 'name' => $code, 'side' => 'BUY', 'vendor_id' => $vendor, 'valid_from' => '2026-01-01'])->assertCreated()->json('data.id');
        foreach ($rows as [$unit, $type, $rate, $bps]) {
            $this->as($this->dispatcher)->postJson("/api/v1/tariffs/{$t}/lines", [
                'charge_code_id' => $this->m['FREIGHT'], 'origin_city_id' => $this->m['jkt'], 'destination_city_id' => $this->m['bdg'], 'carrier_type_id' => $this->m[$type],
                'basis' => 'UNIT', 'unit_id' => $this->m[$unit], 'unit_price_minor' => $rate, 'margin_mode' => 'PERCENT', 'margin_value' => $bps,
            ])->assertCreated();
        }
        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$t}/approve")->assertOk();

        return $t;
    }

    private function item(string $unit, int $qty, array $o = []): array
    {
        return $o + ['description' => 'Barang '.$unit, 'quantity' => $qty, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true, 'unit_id' => $this->m[$unit]];
    }

    private function body(array $o = []): array
    {
        return $o + [
            'price_mode' => 'MAPPING', 'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'customer_id' => $this->m['cust'], 'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'],
            'transporter_vendor_id' => $this->m['vendor'], 'vehicle_id' => $this->m['truckVendor'], 'items' => [$this->item('PALLET', 4)],
        ];
    }

    private function create(array $o = [], ?User $by = null)
    {
        return $this->as($by ?? $this->dispatcher)->postJson('/api/v1/orders', $this->body($o), ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_the_price_comes_from_the_mapping_and_cannot_be_typed(): void
    {
        $o = $this->create(['base_price_minor' => 1, 'surcharge_minor' => 5, 'accessorial_minor' => 7])->assertCreated()->json('data');
        $this->assertSame(4_600_000_00, $o['sell_minor'], '4 pallets at 1.000.000 + 15%');
        $this->assertSame(4_600_000_00, $o['base_price_minor']);
        $this->assertSame(0, $o['surcharge_minor']);
        $this->assertSame(0, $o['accessorial_minor']);
        $this->assertSame('MAPPING', $o['price_source']);
        $this->assertSame('FIXED', $o['price_status']);
        $this->assertSame($this->m['cde'], $o['carrier_type_id'], 'the fleet type comes from the vehicle');
        $this->assertSame($this->m['truckVendor'], $o['vehicle_id']);

        $shown = $this->as($this->dispatcher)->getJson("/api/v1/orders/{$o['id']}")->assertOk()->json('data');
        $this->assertSame('B 1 VND', $shown['vehicle_label']);
        $this->assertSame(4_600_000_00, $shown['pricing_snapshot']['total_minor']);
        $this->assertSame(1_150_000_00, $shown['pricing_snapshot']['items'][0]['unit_price_minor']);
        $this->assertSame($this->m['PALLET'], $shown['items'][0]['unit_id']);
        $this->assertStringNotContainsString('initial', json_encode($shown['pricing_snapshot']), 'no buying price in the snapshot');
        $this->assertStringNotContainsString('1000000', json_encode($shown['pricing_snapshot']));
    }

    public function test_several_units_are_priced_per_line_and_added_up(): void
    {
        $o = $this->create(['items' => [$this->item('PALLET', 2), $this->item('PCS', 10)]])->assertCreated()->json('data');
        $this->assertSame(2_300_000_00 + 120_000_00, $o['sell_minor']);
    }

    public function test_the_transporter_decides_the_rate_and_the_vehicle_must_belong_to_it(): void
    {
        $own = $this->create(['transporter_vendor_id' => null, 'vehicle_id' => $this->m['truckOwn']])->assertCreated()->json('data');
        $this->assertSame(4 * 990_000_00, $own['sell_minor'], 'own fleet: 900.000 + 10%');
        $this->assertNull($own['transporter_vendor_id']);

        $this->create(['transporter_vendor_id' => null])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id'); // a vendor's vehicle with the own fleet
        $this->create(['vehicle_id' => $this->m['truckOwn']])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
        $this->create(['vehicle_id' => $this->m['truckNoType']])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
        $byType = $this->create(['vehicle_id' => null, 'carrier_type_id' => $this->m['cde']])->assertCreated()->json('data');
        $this->assertNull($byType['vehicle_id']);
        $this->create(['vehicle_id' => null])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
    }

    public function test_a_line_without_a_mapping_row_needs_a_manual_price_with_a_reason_and_a_second_person(): void
    {
        $this->create(['items' => [$this->item('DRUM', 1)]])->assertUnprocessable()->assertJsonValidationErrors('items.0.manual_price_minor');
        $this->create(['items' => [$this->item('DRUM', 1, ['manual_price_minor' => 500_000_00])]])->assertUnprocessable()->assertJsonValidationErrors('price_reason');

        $o = $this->create(['price_reason' => 'Drum tidak ada di matriks', 'items' => [$this->item('PALLET', 1), $this->item('DRUM', 1, ['manual_price_minor' => 500_000_00])]])->assertCreated()->json('data');
        $this->assertSame(1_150_000_00 + 500_000_00, $o['sell_minor']);
        $this->assertSame('APPROVAL', $o['price_source']);
        $this->assertSame('PENDING', $o['price_status']);

        $confirm = fn (User $u) => $this->as($u)->postJson("/api/v1/orders/{$o['id']}/transition", ['action' => 'confirm']);
        $confirm($this->dispatcher)->assertStatus(409);
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$o['id']}/price-decision", ['decision' => 'approve'])->assertForbidden(); // no rate.approve
        $mine = $this->create(['price_reason' => 'x', 'items' => [$this->item('DRUM', 1, ['manual_price_minor' => 1_00])]], $this->supervisor)->assertCreated()->json('data');
        $this->as($this->supervisor)->postJson("/api/v1/orders/{$mine['id']}/price-decision", ['decision' => 'approve'])->assertForbidden(); // not your own request
        $this->as($this->supervisor)->postJson("/api/v1/orders/{$o['id']}/price-decision", ['decision' => 'reject'])->assertUnprocessable(); // a rejection needs a note

        $this->as($this->supervisor)->postJson("/api/v1/orders/{$o['id']}/price-decision", ['decision' => 'approve', 'note' => 'ok'])->assertOk()->assertJsonPath('data.price_status', 'APPROVED');
        $confirm($this->dispatcher)->assertOk()->assertJsonPath('data.status', 'CONFIRMED');
        $this->assertNotNull(DB::table('orders')->where('id', $o['id'])->value('price_locked_at'));
        $this->assertSame(1_650_000_00, (int) DB::table('orders')->where('id', $o['id'])->value('sell_minor'), 'an approved manual price stays as approved');
        $this->as($this->supervisor)->postJson("/api/v1/orders/{$o['id']}/price-decision", ['decision' => 'approve'])->assertStatus(409);

        $rejected = $this->create(['price_reason' => 'y', 'items' => [$this->item('DRUM', 1, ['manual_price_minor' => 100_00])]])->assertCreated()->json('data');
        $this->as($this->supervisor)->postJson("/api/v1/orders/{$rejected['id']}/price-decision", ['decision' => 'reject', 'note' => 'terlalu murah'])->assertOk()->assertJsonPath('data.price_status', 'REJECTED');
        $confirm($this->dispatcher)->assertStatus(409);
    }

    public function test_the_price_is_locked_at_confirmation_and_not_recomputed_afterwards(): void
    {
        $o = $this->create()->assertCreated()->json('data');
        $this->assertSame(4_600_000_00, $o['sell_minor']);
        // A new version of the vendor's mapping with a higher rate becomes effective before the order is confirmed.
        $v1 = DB::table('rate_tables')->where('code', 'MAPV')->value('id');
        $v2 = $this->as($this->dispatcher)->postJson("/api/v1/tariffs/{$v1}/versions", ['valid_from' => now()->toDateString()])->assertCreated()->json('data.id');
        $line = $this->as($this->dispatcher)->getJson("/api/v1/tariffs/{$v2}")->json('data.lines')[0];
        $this->as($this->dispatcher)->putJson("/api/v1/tariffs/{$v2}/lines/{$line['id']}", [
            'charge_code_id' => $this->m['FREIGHT'], 'origin_city_id' => $this->m['jkt'], 'destination_city_id' => $this->m['bdg'], 'carrier_type_id' => $this->m['cde'],
            'basis' => 'UNIT', 'unit_id' => $this->m['PALLET'], 'unit_price_minor' => 2_000_000_00, 'margin_mode' => 'PERCENT', 'margin_value' => 1500,
        ])->assertOk();
        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$v2}/approve")->assertOk();

        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$o['id']}/transition", ['action' => 'confirm'])->assertOk()->assertJsonPath('data.sell_minor', 4 * 2_300_000_00);
        $locked = DB::table('orders')->where('id', $o['id'])->first();
        $this->assertNotNull($locked->price_locked_at);
        $this->assertSame(4 * 2_300_000_00, json_decode($locked->pricing_snapshot, true)['total_minor']);

        $this->as($this->supervisor)->postJson('/api/v1/tariffs/'.$v2.'/retire', ['reason' => 'uji harga terkunci'])->assertOk();
        $this->assertSame(4 * 2_300_000_00, (int) DB::table('orders')->where('id', $o['id'])->value('sell_minor'), 'a confirmed order is not repriced');
    }

    public function test_the_contract_of_the_customer_wins_and_the_margin_follows_the_customer(): void
    {
        $contract = $this->as($this->dispatcher)->postJson('/api/v1/tariffs', ['code' => 'KONTRAK', 'name' => 'Kontrak', 'side' => 'SELL', 'customer_id' => $this->m['cust'], 'valid_from' => '2026-01-01'])->assertCreated()->json('data.id');
        $this->as($this->dispatcher)->postJson("/api/v1/tariffs/{$contract}/lines", ['charge_code_id' => $this->m['FREIGHT'], 'basis' => 'UNIT', 'unit_id' => $this->m['PALLET'], 'unit_price_minor' => 900_000_00, 'carrier_type_id' => $this->m['cde']])->assertCreated();
        $this->as($this->supervisor)->postJson("/api/v1/tariffs/{$contract}/approve")->assertOk();
        $this->assertSame(4 * 900_000_00, $this->create()->assertCreated()->json('data.sell_minor'), 'contract price for the customer with a contract');
        $this->assertSame(4_600_000_00, $this->create(['customer_id' => $this->m['cust2']])->assertCreated()->json('data.sell_minor'), 'other customers get the computed price');
    }

    public function test_the_preview_shows_the_price_without_writing_and_without_buying_prices(): void
    {
        $before = DB::table('orders')->count();
        $p = $this->as($this->dispatcher)->postJson('/api/v1/orders/price-preview', [
            'customer_id' => $this->m['cust'], 'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'], 'transporter_vendor_id' => $this->m['vendor'], 'vehicle_id' => $this->m['truckVendor'],
            'items' => [['description' => 'Palet', 'quantity' => 4, 'unit_id' => $this->m['PALLET']], ['description' => 'Drum', 'quantity' => 1, 'unit_id' => $this->m['DRUM']]],
        ])->assertOk()->json('data');
        $this->assertSame($before, DB::table('orders')->count());
        $this->assertSame(4_600_000_00, $p['total_minor']);
        $this->assertSame([1], $p['unpriced']);
        $this->assertTrue($p['items'][0]['priced']);
        $this->assertFalse($p['items'][1]['priced']);
        $this->assertSame($this->m['cde'], $p['carrier_type_id']);
        $this->assertStringNotContainsString('1000000', json_encode($p), 'the buying price is never in the preview');
    }

    public function test_orders_without_the_mapping_keep_working_as_before(): void
    {
        $o = $this->as($this->dispatcher)->postJson('/api/v1/orders', [
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100_000_00, 'surcharge_minor' => 5_000_00, 'accessorial_minor' => 1_000_00, 'customer_id' => $this->m['cust'],
            'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'], 'transporter_vendor_id' => $this->m['vendor'],
            'items' => [['description' => 'Palet', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->assertSame(106_000_00, $o['sell_minor']);
        $this->assertSame('MANUAL', $o['price_source']);
        $this->assertSame('FIXED', $o['price_status']);
        $this->assertNull($o['transporter_vendor_id'], 'a typed price order ignores the mapping fields');
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$o['id']}/transition", ['action' => 'confirm'])->assertOk();
        $this->as($this->dispatcher)->postJson('/api/v1/orders', [
            'stage' => 'FIRST_MILE', 'currency' => 'IDR', 'base_price_minor' => 100, 'surcharge_minor' => 0, 'accessorial_minor' => 0, 'customer_id' => $this->m['cust'],
            'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'],
            'items' => [['description' => 'Palet', 'quantity' => 1, 'unit_weight_g' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'stackable' => true, 'manual_price_minor' => 5]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
    }
}

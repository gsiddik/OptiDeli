<?php

namespace Tests\Feature;

use App\Domain\Master\MasterDefaults;
use App\Domain\Tariff\BaseTariff;
use App\Domain\Tariff\TariffResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** Transporter price mapping: BUY lines per fleet type and cargo unit, the margin (default, per customer, per project) and the base tariff. */
class PricingMappingTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private User $maker;

    private User $checker;

    /** @var array<string, string> */
    private array $m = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('dispatcher');
        MasterDefaults::seed($this->tenant);
        $this->maker = auth()->user();
        $this->checker = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $this->checker->id, 'role' => 'supervisor', 'active' => true]);

        $this->m['cust'] = $this->master('customers', ['code' => 'CUST', 'name' => 'PT Maju', 'payment_terms_days' => 30]);
        $this->m['cust2'] = $this->master('customers', ['code' => 'CUST2', 'name' => 'PT Lain', 'payment_terms_days' => 30]);
        $this->m['proj'] = $this->master('customer_projects', ['code' => 'PROJ', 'name' => 'Proyek', 'customer_id' => $this->m['cust']]);
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
        foreach (['PCS', 'PALLET'] as $code) {
            $this->m[$code] = DB::table('units')->where('tenant_id', $this->tenant)->where('code', $code)->value('id');
        }
    }

    private function master(string $resource, array $body): string
    {
        return $this->postJson("/api/v1/master/{$resource}", $body, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    }

    private function as(User $user)
    {
        return $this->actingAs($user)->withSession(['tenant_id' => $this->tenant]);
    }

    private function buyTable(array $o = []): array
    {
        return $this->as($this->maker)->postJson('/api/v1/tariffs', $o + ['code' => 'MAP', 'name' => 'Pemetaan', 'side' => 'BUY', 'vendor_id' => $this->m['vendor'], 'valid_from' => '2026-01-01'])->assertCreated()->json('data');
    }

    /** A mapping row of fleet type CDE from Jakarta to Bandung, per pallet unless stated otherwise. */
    private function row(string $table, array $o = [])
    {
        return $this->as($this->maker)->postJson("/api/v1/tariffs/{$table}/lines", $o + [
            'charge_code_id' => $this->m['FREIGHT'], 'origin_city_id' => $this->m['jkt'], 'destination_city_id' => $this->m['bdg'], 'carrier_type_id' => $this->m['cde'],
            'basis' => 'UNIT', 'unit_id' => $this->m['PALLET'], 'unit_price_minor' => 1_000_000_00, 'margin_mode' => 'PERCENT', 'margin_value' => 1500,
        ]);
    }

    private function approve(string $table): void
    {
        $this->as($this->checker)->postJson("/api/v1/tariffs/{$table}/approve")->assertOk();
    }

    private function resolve(array $o = [])
    {
        return $this->as($this->maker)->postJson('/api/v1/tariffs/resolve', $o + [
            'date' => '2026-06-01', 'vendor_id' => $this->m['vendor'], 'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'],
            'carrier_type_id' => $this->m['cde'], 'unit_id' => $this->m['PALLET'], 'quantities' => ['unit' => 4],
        ]);
    }

    public function test_the_base_tariff_is_the_initial_rate_with_the_margin_and_cannot_be_typed(): void
    {
        $t = $this->buyTable();
        $row = $this->row($t['id'], ['sell_unit_price_minor' => 1])->assertCreated()->json('data');
        $this->assertSame(1_150_000_00, $row['sell_unit_price_minor'], 'initial rate 1.000.000 plus 15%; a typed value is ignored');
        $this->assertSame('UNIT', $row['basis']);

        $flat = $this->row($t['id'], ['carrier_type_id' => $this->m['cdd'], 'margin_mode' => 'AMOUNT', 'margin_value' => 125_000_00])->assertCreated()->json('data');
        $this->assertSame(1_125_000_00, $flat['sell_unit_price_minor']);

        $this->as($this->maker)->putJson("/api/v1/tariffs/{$t['id']}/lines/{$flat['id']}", [
            'charge_code_id' => $this->m['FREIGHT'], 'carrier_type_id' => $this->m['cdd'], 'origin_city_id' => $this->m['jkt'], 'destination_city_id' => $this->m['bdg'],
            'basis' => 'UNIT', 'unit_id' => $this->m['PALLET'], 'unit_price_minor' => 2_000_000_00, 'margin_mode' => 'AMOUNT', 'margin_value' => 125_000_00,
        ])->assertOk()->assertJsonPath('data.sell_unit_price_minor', 2_125_000_00);
        $this->assertSame(110, BaseTariff::price(100, 'PERCENT', 1000));
    }

    public function test_unit_and_margin_rules_are_enforced(): void
    {
        $t = $this->buyTable();
        $this->row($t['id'], ['unit_id' => null])->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        $this->row($t['id'], ['basis' => 'TRIP'])->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        $this->row($t['id'], ['margin_mode' => 'PERCENT', 'margin_value' => null])->assertUnprocessable()->assertJsonValidationErrors('margin_value');
        $this->row($t['id'], ['margin_mode' => 'PERCENT', 'margin_value' => 200_000])->assertUnprocessable()->assertJsonValidationErrors('margin_value');
        $this->row($t['id'], ['margin_mode' => 'BAD'])->assertUnprocessable()->assertJsonValidationErrors('margin_mode');
        $this->row($t['id'], ['margin_mode' => null, 'margin_value' => null])->assertCreated()->assertJsonPath('data.sell_unit_price_minor', null);

        $sell = $this->as($this->maker)->postJson('/api/v1/tariffs', ['code' => 'SEL', 'name' => 'Jual', 'side' => 'SELL', 'valid_from' => '2026-01-01'])->assertCreated()->json('data');
        $this->row($sell['id'])->assertUnprocessable()->assertJsonValidationErrors('margin_mode');
    }

    public function test_a_mapping_row_prices_by_fleet_type_and_unit(): void
    {
        $t = $this->buyTable();
        $this->row($t['id'])->assertCreated();
        $this->row($t['id'], ['unit_id' => $this->m['PCS'], 'unit_price_minor' => 10_000_00, 'margin_value' => 2000])->assertCreated();
        $this->row($t['id'], ['carrier_type_id' => $this->m['cdd'], 'unit_price_minor' => 700_000_00])->assertCreated();
        $this->approve($t['id']);

        $pallet = $this->resolve()->assertOk()->json('data.lines.0');
        $this->assertSame('MAPPING', $pallet['sell']['source']);
        $this->assertSame(1_150_000_00, $pallet['sell']['unit_price_minor'], 'Tarif Dasar');
        $this->assertSame(4_600_000_00, $pallet['sell']['amount_minor']);
        $this->assertSame(4_000_000_00, $pallet['buy']['amount_minor']);
        $this->assertSame(600_000_00, $pallet['margin_minor']);
        $this->assertSame('DEFAULT', $pallet['sell']['mapping']['scope']);

        $pcs = $this->resolve(['unit_id' => $this->m['PCS'], 'quantities' => ['unit' => 10]])->json('data.lines.0');
        $this->assertSame(120_000_00, $pcs['sell']['amount_minor'], 'each unit has its own row');
        $cdd = $this->resolve(['carrier_type_id' => $this->m['cdd']])->json('data.lines.0');
        $this->assertSame(805_000_00 * 4, $cdd['sell']['amount_minor'], 'each fleet type has its own base price');

        $none = $this->resolve(['unit_id' => null, 'quantities' => ['unit' => 4]])->json('data');
        $this->assertSame([], $none['lines'], 'a unit row never matches cargo without that unit');
        $this->assertNotEmpty($none['warnings']);
        $this->assertSame([], $this->resolve(['carrier_type_id' => null, 'unit_id' => $this->m['PCS'], 'quantities' => ['unit' => 1]])->json('data.lines.1') ?? []);
    }

    public function test_the_margin_can_differ_per_customer_and_per_project(): void
    {
        $t = $this->buyTable();
        $row = $this->row($t['id'])->assertCreated()->json('data');
        $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/lines/{$row['id']}/margins", ['customer_id' => $this->m['cust'], 'margin_mode' => 'PERCENT', 'margin_value' => 2500])->assertOk();
        $projectMargin = $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/lines/{$row['id']}/margins", ['customer_id' => $this->m['cust'], 'project_id' => $this->m['proj'], 'margin_mode' => 'AMOUNT', 'margin_value' => 50_000_00])->assertOk()->json('data');
        $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/lines/{$row['id']}/margins", ['customer_id' => $this->m['cust'], 'margin_mode' => 'PERCENT', 'margin_value' => 3000])->assertOk();
        $this->assertSame(2, DB::table('rate_line_margins')->where('rate_line_id', $row['id'])->count(), 'the same customer scope is replaced, not duplicated');
        $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/lines/{$row['id']}/margins", ['customer_id' => $this->m['cust2'], 'project_id' => $this->m['proj'], 'margin_mode' => 'PERCENT', 'margin_value' => 1])->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->approve($t['id']);

        $unit = fn (array $o) => $this->resolve($o)->json('data.lines.0.sell');
        $this->assertSame(1_150_000_00, $unit([])['unit_price_minor'], 'no customer: the margin of the row');
        $this->assertSame(1_150_000_00, $unit(['customer_id' => $this->m['cust2']])['unit_price_minor'], 'another customer: the margin of the row');
        $c = $unit(['customer_id' => $this->m['cust']]);
        $this->assertSame(1_300_000_00, $c['unit_price_minor']);
        $this->assertSame('CUSTOMER', $c['mapping']['scope']);
        $p = $unit(['project_id' => $this->m['proj']]);
        $this->assertSame(1_050_000_00, $p['unit_price_minor'], 'the project margin wins over the customer margin');
        $this->assertSame('PROJECT', $p['mapping']['scope']);

        $this->as($this->maker)->deleteJson("/api/v1/tariffs/{$t['id']}/lines/{$row['id']}/margins/{$projectMargin['id']}")->assertStatus(409);
    }

    public function test_margins_follow_a_new_version_and_an_approved_table_is_frozen(): void
    {
        $t = $this->buyTable();
        $row = $this->row($t['id'])->assertCreated()->json('data');
        $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/lines/{$row['id']}/margins", ['customer_id' => $this->m['cust'], 'margin_mode' => 'PERCENT', 'margin_value' => 2500])->assertOk();
        $this->approve($t['id']);
        $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/lines/{$row['id']}/margins", ['customer_id' => $this->m['cust2'], 'margin_mode' => 'PERCENT', 'margin_value' => 1])->assertStatus(409);

        $v2 = $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/versions", ['valid_from' => '2026-07-01'])->assertCreated()->json('data');
        $copied = $this->as($this->maker)->getJson("/api/v1/tariffs/{$v2['id']}")->assertOk()->json('data.lines.0');
        $this->assertSame(1_150_000_00, $copied['sell_unit_price_minor']);
        $this->assertCount(1, $copied['margins']);
        $this->assertSame($this->m['cust'], $copied['margins'][0]['customer_id']);
        $this->assertNotSame($row['id'], $copied['id']);
    }

    public function test_a_contract_tariff_wins_and_a_mapping_margin_wins_over_a_markup_rule(): void
    {
        $t = $this->buyTable();
        $this->row($t['id'])->assertCreated();
        $this->row($t['id'], ['carrier_type_id' => $this->m['cdd'], 'margin_mode' => null, 'margin_value' => null, 'unit_price_minor' => 500_000_00])->assertCreated();
        $this->approve($t['id']);
        $this->as($this->maker)->postJson('/api/v1/tariffs/markups', ['code' => 'M10', 'name' => '10%', 'mode' => 'PERCENT', 'percent_bps' => 1000])->assertCreated();

        $this->assertSame('MAPPING', $this->resolve()->json('data.lines.0.sell.source'), 'the margin of the row beats the generic markup rule');
        $noMargin = $this->resolve(['carrier_type_id' => $this->m['cdd']])->json('data.lines.0.sell');
        $this->assertSame('MARKUP', $noMargin['source'], 'a row without margin still falls back to the markup rule');
        $this->assertSame(2_200_000_00, $noMargin['amount_minor']);

        $contract = $this->as($this->maker)->postJson('/api/v1/tariffs', ['code' => 'KONTRAK', 'name' => 'Kontrak', 'side' => 'SELL', 'customer_id' => $this->m['cust'], 'valid_from' => '2026-01-01'])->assertCreated()->json('data');
        $this->as($this->maker)->postJson("/api/v1/tariffs/{$contract['id']}/lines", ['charge_code_id' => $this->m['FREIGHT'], 'basis' => 'UNIT', 'unit_id' => $this->m['PALLET'], 'unit_price_minor' => 900_000_00, 'carrier_type_id' => $this->m['cde']])->assertCreated();
        $this->approve($contract['id']);
        $won = $this->resolve(['customer_id' => $this->m['cust']])->json('data.lines.0');
        $this->assertSame('TABLE', $won['sell']['source']);
        $this->assertSame(3_600_000_00, $won['sell']['amount_minor']);
        $this->assertSame('MAPPING', $this->resolve(['customer_id' => $this->m['cust2']])->json('data.lines.0.sell.source'), 'other customers keep the computed price');
    }

    public function test_a_user_without_buy_rights_sees_only_the_base_tariff(): void
    {
        $t = $this->buyTable();
        $this->row($t['id'])->assertCreated();
        $this->approve($t['id']);
        $hidden = TariffResolver::withoutBuy($this->resolve()->json('data'));
        $sell = $hidden['lines'][0]['sell'];
        $this->assertSame(1_150_000_00, $sell['unit_price_minor']);
        $this->assertSame(4_600_000_00, $sell['amount_minor']);
        $this->assertSame('DERIVED', $sell['source']);
        $this->assertArrayNotHasKey('mapping', $sell);
        $this->assertArrayNotHasKey('buy', $hidden['lines'][0]);
        $this->assertStringNotContainsString('1000000', json_encode($hidden));
    }
}

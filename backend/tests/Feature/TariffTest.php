<?php

namespace Tests\Feature;

use App\Domain\Master\MasterDefaults;
use App\Domain\Shared\TenantStore;
use App\Domain\Tariff\TariffResolver;
use App\Domain\Tariff\TariffService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** Two-sided versioned tariffs, maker-checker approval, markup and the resolver (ARK/OP adoption, F1/M2). */
class TariffTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private User $maker;

    private User $checker;

    /** @var array<string, string> master ids by name */
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
        $this->m['zone'] = $this->master('zones', ['code' => 'JABAR', 'name' => 'Jabar']);
        $this->m['pool'] = $this->master('locations', ['code' => 'POOL', 'name' => 'Pool Jakarta', 'type' => 'POOL', 'city_id' => $this->m['jkt']]);
        $this->m['wh'] = $this->master('locations', ['code' => 'WH', 'name' => 'Gudang Bandung', 'type' => 'WAREHOUSE', 'city_id' => $this->m['bdg']]);
        $this->m['cde'] = $this->master('carrier_types', ['code' => 'CDE', 'name' => 'CDE']);
        $this->m['cdd'] = $this->master('carrier_types', ['code' => 'CDD', 'name' => 'CDD']);
        $this->m['vendor'] = (string) Str::uuid();
        DB::table('vendors')->insert(['id' => $this->m['vendor'], 'tenant_id' => $this->tenant, 'name' => 'CV Vendor', 'code' => 'VND', 'active' => true]);
        foreach (['FREIGHT', 'LOADING', 'TOLL'] as $code) {
            $this->m[$code] = DB::table('charge_codes')->where('tenant_id', $this->tenant)->where('code', $code)->value('id');
        }
        $this->m['express'] = DB::table('services')->where('tenant_id', $this->tenant)->where('code', 'EXPRESS')->value('id');
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

    private function table(array $o = []): array
    {
        return $this->as($this->maker)->postJson('/api/v1/tariffs', $o + ['code' => 'jkt-bdg', 'name' => 'Tarif Jakarta-Bandung', 'side' => 'SELL', 'valid_from' => '2026-01-01'])->assertCreated()->json('data');
    }

    private function line(string $table, array $o = []): array
    {
        return $this->as($this->maker)->postJson("/api/v1/tariffs/{$table}/lines", $o + ['charge_code_id' => $this->m['FREIGHT'], 'basis' => 'TRIP', 'unit_price_minor' => 100_000_00])->assertCreated()->json('data');
    }

    private function approved(array $table, array $lines): array
    {
        $t = $this->table($table);
        foreach ($lines as $l) {
            $this->line($t['id'], $l);
        }
        $this->as($this->checker)->postJson("/api/v1/tariffs/{$t['id']}/approve")->assertOk();

        return $t;
    }

    private function resolve(array $o = [])
    {
        return $this->as($this->maker)->postJson('/api/v1/tariffs/resolve', $o + ['date' => '2026-06-01', 'origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh']]);
    }

    public function test_a_tariff_is_drafted_with_lines_and_cannot_be_approved_by_its_author(): void
    {
        $t = $this->table();
        $this->assertSame('JKT-BDG', $t['code']);
        $this->assertSame('DRAFT', $t['status']);
        $this->as($this->checker)->postJson("/api/v1/tariffs/{$t['id']}/approve")->assertUnprocessable();
        $this->line($t['id'], ['origin_city_id' => $this->m['jkt'], 'destination_city_id' => $this->m['bdg']]);
        $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/approve")->assertForbidden();

        $this->as($this->checker)->postJson("/api/v1/tariffs/{$t['id']}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.approved_by', $this->checker->id);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenant, 'action' => 'tariff.approved', 'subject_id' => $t['id']]);
        $this->assertDatabaseHas('outbox_events', ['tenant_id' => $this->tenant, 'type' => 'tariff.approved.v1']);
    }

    public function test_an_approved_tariff_is_frozen_and_changes_go_through_a_new_version(): void
    {
        $t = $this->approved([], [['origin_city_id' => $this->m['jkt']]]);
        $this->as($this->maker)->putJson("/api/v1/tariffs/{$t['id']}", ['name' => 'Baru', 'valid_from' => '2026-01-01'])->assertStatus(409);
        $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/lines", ['charge_code_id' => $this->m['LOADING'], 'basis' => 'TRIP', 'unit_price_minor' => 1])->assertStatus(409);

        $v2 = $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/versions", ['valid_from' => '2026-07-01'])->assertCreated()->json('data');
        $this->assertSame(2, $v2['version']);
        $this->assertSame('DRAFT', $v2['status']);
        $this->assertSame(1, DB::table('rate_lines')->where('rate_table_id', $v2['id'])->count(), 'lines are copied');
        $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/versions", ['valid_from' => '2026-08-01'])->assertStatus(409);

        $this->as($this->checker)->postJson("/api/v1/tariffs/{$v2['id']}/approve")->assertOk();
        $this->assertSame('2026-06-30', substr(DB::table('rate_tables')->where('id', $t['id'])->value('valid_until'), 0, 10), 'the earlier version ends the day before');
    }

    public function test_a_new_version_cannot_start_before_an_approved_one(): void
    {
        $t = $this->approved(['valid_from' => '2026-05-01'], [[]]);
        $v2 = $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/versions", ['valid_from' => '2026-04-01'])->assertCreated()->json('data');
        $this->as($this->checker)->postJson("/api/v1/tariffs/{$v2['id']}/approve")->assertUnprocessable();
    }

    public function test_lines_are_validated(): void
    {
        $t = $this->table();
        $base = ['charge_code_id' => $this->m['FREIGHT'], 'basis' => 'TRIP', 'unit_price_minor' => 100];
        $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/lines", array_replace($base, ['unit_price_minor' => -1]))->assertUnprocessable()->assertJsonValidationErrors('unit_price_minor');
        $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/lines", array_replace($base, ['basis' => 'PARSEC']))->assertUnprocessable()->assertJsonValidationErrors('basis');
        $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/lines", $base + ['origin_city_id' => $this->m['jkt'], 'origin_zone_id' => $this->m['zone']])->assertUnprocessable()->assertJsonValidationErrors('origin_location_id');
        $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/lines", array_replace($base, ['charge_code_id' => $this->m['TOLL']]))->assertUnprocessable()->assertJsonValidationErrors('charge_code_id');
        $this->line($t['id']);
        $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/lines", $base)->assertUnprocessable()->assertJsonValidationErrors('charge_code_id');
        $this->as($this->maker)->postJson('/api/v1/tariffs', ['code' => 'X', 'name' => 'X', 'side' => 'SELL', 'vendor_id' => $this->m['vendor'], 'valid_from' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('vendor_id');
        $this->as($this->maker)->postJson('/api/v1/tariffs', ['code' => 'X', 'name' => 'X', 'side' => 'SELL', 'project_id' => $this->m['proj'], 'valid_from' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->as($this->maker)->postJson('/api/v1/tariffs', ['code' => 'X', 'name' => 'X', 'side' => 'SELL', 'customer_id' => $this->m['cust2'], 'project_id' => $this->m['proj'], 'valid_from' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->as($this->maker)->postJson('/api/v1/tariffs', ['code' => 'JKT-BDG', 'name' => 'Dobel', 'side' => 'SELL', 'valid_from' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_the_most_specific_selling_line_wins(): void
    {
        $this->approved(['code' => 'GEN', 'name' => 'Umum'], [['unit_price_minor' => 1_000_00], ['origin_zone_id' => $this->m['zone'], 'unit_price_minor' => 1_100_00]]);
        $this->assertSame(1_000_00, $this->resolve()->assertOk()->json('data.lines.0.sell.unit_price_minor'));

        $this->approved(['code' => 'CITY', 'name' => 'Kota'], [['origin_city_id' => $this->m['jkt'], 'destination_city_id' => $this->m['bdg'], 'unit_price_minor' => 900_00]]);
        $this->assertSame(900_00, $this->resolve()->json('data.lines.0.sell.unit_price_minor'), 'a lane beats anywhere');

        $this->approved(['code' => 'LOC', 'name' => 'Lokasi'], [['origin_location_id' => $this->m['pool'], 'destination_location_id' => $this->m['wh'], 'unit_price_minor' => 800_00]]);
        $this->assertSame(800_00, $this->resolve()->json('data.lines.0.sell.unit_price_minor'), 'a location beats a city');

        $this->approved(['code' => 'CUSTT', 'name' => 'Pelanggan', 'customer_id' => $this->m['cust']], [['unit_price_minor' => 1_200_00]]);
        $this->assertSame(800_00, $this->resolve()->json('data.lines.0.sell.unit_price_minor'), 'not asked for that customer');
        $this->assertSame(1_200_00, $this->resolve(['customer_id' => $this->m['cust']])->json('data.lines.0.sell.unit_price_minor'), 'a customer tariff beats a general one');
        $this->assertSame(800_00, $this->resolve(['customer_id' => $this->m['cust2']])->json('data.lines.0.sell.unit_price_minor'));

        $this->approved(['code' => 'PROJT', 'name' => 'Proyek', 'customer_id' => $this->m['cust'], 'project_id' => $this->m['proj']], [['unit_price_minor' => 1_300_00]]);
        $this->assertSame(1_300_00, $this->resolve(['project_id' => $this->m['proj']])->json('data.lines.0.sell.unit_price_minor'));
    }

    public function test_carrier_type_service_validity_and_retirement(): void
    {
        $t = $this->approved(['code' => 'A'], [['unit_price_minor' => 500_00], ['carrier_type_id' => $this->m['cdd'], 'unit_price_minor' => 700_00], ['service_id' => $this->m['express'], 'unit_price_minor' => 900_00]]);
        $this->assertSame(500_00, $this->resolve()->json('data.lines.0.sell.unit_price_minor'));
        $this->assertSame(700_00, $this->resolve(['carrier_type_id' => $this->m['cdd']])->json('data.lines.0.sell.unit_price_minor'));
        $this->assertSame(500_00, $this->resolve(['carrier_type_id' => $this->m['cde']])->json('data.lines.0.sell.unit_price_minor'));
        $this->assertSame(900_00, $this->resolve(['service_id' => $this->m['express']])->json('data.lines.0.sell.unit_price_minor'));

        $this->assertEmpty($this->resolve(['date' => '2025-12-31'])->json('data.lines'), 'before the tariff starts');
        $this->assertNotEmpty($this->resolve(['date' => '2025-12-31'])->json('data.warnings'));

        $this->as($this->checker)->postJson("/api/v1/tariffs/{$t['id']}/retire", ['reason' => 'x'])->assertUnprocessable();
        $this->as($this->checker)->postJson("/api/v1/tariffs/{$t['id']}/retire", ['reason' => 'Diganti kontrak baru'])->assertOk()->assertJsonPath('data.status', 'RETIRED');
        $this->assertEmpty($this->resolve()->json('data.lines'), 'a retired tariff is not used');
    }

    public function test_quantities_minimum_charge_and_a_missing_quantity(): void
    {
        $this->approved(['code' => 'KG'], [['basis' => 'KG', 'unit_price_minor' => 500, 'min_charge_minor' => 100_000_00]]);
        $r = $this->resolve()->json('data');
        $this->assertNull($r['lines'][0]['sell']['amount_minor']);
        $this->assertNotEmpty($r['warnings']);

        $this->assertSame(100_000_00, $this->resolve(['quantities' => ['kg' => 100]])->json('data.lines.0.sell.amount_minor'), 'the minimum charge applies');
        $this->assertSame(25_000_000, $this->resolve(['quantities' => ['kg' => 50000]])->json('data.totals.sell_minor'));
    }

    public function test_buying_prices_derive_a_selling_price_through_markup_unless_a_fixed_tariff_exists(): void
    {
        $this->approved(['code' => 'VB', 'name' => 'Beli vendor', 'side' => 'BUY', 'vendor_id' => $this->m['vendor']], [['unit_price_minor' => 1_000_000_00], ['charge_code_id' => $this->m['LOADING'], 'unit_price_minor' => 50_000_00]]);
        $buy = ['vendor_id' => $this->m['vendor']];

        $none = $this->resolve($buy)->json('data');
        $this->assertSame(1_050_000_00, $none['totals']['buy_minor']);
        $this->assertSame(0, $none['totals']['sell_minor']);
        $this->assertNotEmpty($none['warnings']);

        $this->as($this->maker)->postJson('/api/v1/tariffs/markups', ['code' => 'M10', 'name' => '10%', 'mode' => 'PERCENT', 'percent_bps' => 1000])->assertCreated();
        $r = $this->resolve($buy)->json('data');
        $freight = $r['lines'][0];
        $this->assertSame('FREIGHT', $freight['charge_code']['code']);
        $this->assertSame(1_100_000_00, $freight['sell']['amount_minor']);
        $this->assertSame('MARKUP', $freight['sell']['source']);
        $this->assertSame(100_000_00, $freight['margin_minor']);

        $this->as($this->maker)->postJson('/api/v1/tariffs/markups', ['code' => 'MC', 'name' => 'Pelanggan', 'mode' => 'PERCENT', 'percent_bps' => 2000, 'customer_id' => $this->m['cust']])->assertCreated();
        $this->assertSame(1_200_000_00, $this->resolve($buy + ['customer_id' => $this->m['cust']])->json('data.lines.0.sell.amount_minor'), 'the customer rule is more specific');

        $this->approved(['code' => 'FIX', 'name' => 'Jual tetap'], [['unit_price_minor' => 1_500_000_00]]);
        $this->assertSame('TABLE', $this->resolve($buy)->json('data.lines.0.sell.source'), 'a fixed selling tariff overrides the computed price');
        $this->assertSame(500_000_00, $this->resolve($buy)->json('data.lines.0.margin_minor'));
    }

    public function test_an_amount_markup_is_added_once(): void
    {
        $this->approved(['code' => 'VB', 'name' => 'Beli', 'side' => 'BUY'], [['unit_price_minor' => 1_000_00]]);
        $this->as($this->maker)->postJson('/api/v1/tariffs/markups', ['code' => 'FLAT', 'name' => 'Flat', 'mode' => 'AMOUNT', 'amount_minor' => 250_00])->assertCreated();
        $this->assertSame(1_250_00, $this->resolve()->json('data.totals.sell_minor'));
        $this->as($this->maker)->postJson('/api/v1/tariffs/markups', ['code' => 'BAD', 'name' => 'Salah', 'mode' => 'PERCENT'])->assertUnprocessable()->assertJsonValidationErrors('percent_bps');
    }

    public function test_buying_prices_are_hidden_without_the_permission(): void
    {
        $this->approved(['code' => 'VB', 'name' => 'Beli', 'side' => 'BUY'], [['unit_price_minor' => 1_000_00]]);
        $this->as($this->maker)->postJson('/api/v1/tariffs/markups', ['code' => 'M10', 'name' => '10%', 'mode' => 'PERCENT', 'percent_bps' => 1000])->assertCreated();
        $result = $this->resolve()->json('data');
        $hidden = TariffResolver::withoutBuy($result);

        $this->assertArrayNotHasKey('buy', $hidden['lines'][0]);
        $this->assertArrayNotHasKey('margin_minor', $hidden['lines'][0]);
        $this->assertArrayNotHasKey('markup', $hidden['lines'][0]['sell']);
        $this->assertSame(['sell_minor' => 1_100_00], $hidden['totals']);

        $store = new TenantStore($this->tenant, $this->maker->id);
        $this->assertSame(0, (new TariffService($store))->list(null, null, null, 1, false)['total'], 'buying tables are not listed');
        $this->assertSame(1, (new TariffService($store))->list(null, null, null, 1, true)['total']);
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $t = $this->table();
        $viewer = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $viewer->id, 'role' => 'viewer', 'active' => true]);
        $this->as($viewer)->getJson('/api/v1/tariffs')->assertForbidden();
        $this->as($this->maker)->getJson('/api/v1/tariffs')->assertOk()->assertJsonPath('total', 1);
        $this->as($this->maker)->postJson("/api/v1/tariffs/{$t['id']}/retire", ['reason' => 'tidak boleh'])->assertForbidden();

        $other = User::factory()->create();
        $tenant2 = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $tenant2, 'slug' => 'other', 'name' => 'Other']);
        DB::table('memberships')->insert(['tenant_id' => $tenant2, 'user_id' => $other->id, 'role' => 'dispatcher', 'active' => true]);
        $this->actingAs($other)->withSession(['tenant_id' => $tenant2])->getJson("/api/v1/tariffs/{$t['id']}")->assertNotFound();
        $this->actingAs($other)->withSession(['tenant_id' => $tenant2])->getJson('/api/v1/tariffs')->assertOk()->assertJsonPath('total', 0);
    }

    private function upload(string $tableId, string $csv)
    {
        return $this->as($this->maker)->post("/api/v1/tariffs/{$tableId}/imports", ['file' => UploadedFile::fake()->createWithContent('baris.csv', $csv)], $this->key() + ['Accept' => 'application/json']);
    }

    public function test_lines_are_imported_from_a_spreadsheet_into_a_draft(): void
    {
        $this->as($this->maker)->get('/api/v1/tariffs/lines-template?format=xlsx')->assertOk()->assertDownload('template-baris-tarif.xlsx');
        $t = $this->table();
        $csv = "Kode biaya (kode);Dasar tarif;Tarif per satuan (Rp);Biaya minimum (Rp);Asal: kota (kode);Tujuan: kota (kode)\n"
            ."freight;Per trip;1.500.000;;31.71;32.73\n"
            ."LOADING;KG;250,50;100000;;\n"
            ."NOPE;TRIP;100;;;\n"
            ."FREIGHT;TRIP;abc;;;\n";
        $job = $this->upload($t['id'], $csv)->assertCreated()->json('data');
        $this->assertSame(2, $job['rows_new']);
        $this->assertSame(2, $job['rows_error']);
        $this->assertSame(0, DB::table('rate_lines')->where('rate_table_id', $t['id'])->count(), 'nothing is written before the commit');

        $this->as($this->maker)->postJson("/api/v1/master/imports/{$job['id']}/commit", [], $this->key())->assertUnprocessable();
        $done = $this->as($this->maker)->postJson("/api/v1/master/imports/{$job['id']}/commit", ['skip_errors' => true], $this->key())->assertOk()->json('data');
        $this->assertSame(2, $done['result']['created']);
        $freight = DB::table('rate_lines')->where('rate_table_id', $t['id'])->where('charge_code_id', $this->m['FREIGHT'])->first();
        $this->assertSame(150_000_000, (int) $freight->unit_price_minor);
        $this->assertSame($this->m['jkt'], $freight->origin_city_id);
        $loading = DB::table('rate_lines')->where('rate_table_id', $t['id'])->where('charge_code_id', $this->m['LOADING'])->first();
        $this->assertSame(25_050, (int) $loading->unit_price_minor);
        $this->assertSame('KG', $loading->basis);

        // The same rows again hold no change; a new price updates the line.
        $head = "Kode biaya (kode);Dasar tarif;Tarif per satuan (Rp);Asal: kota (kode);Tujuan: kota (kode)\n";
        $again = $this->upload($t['id'], $head."FREIGHT;TRIP;1500000;31.71;32.73\n")->assertCreated()->json('data');
        $this->assertSame(1, $again['rows_unchanged']);
        $changed = $this->upload($t['id'], $head."FREIGHT;TRIP;1600000;31.71;32.73\n")->assertCreated()->json('data');
        $this->assertSame(1, $changed['rows_update']);
        $this->as($this->maker)->postJson("/api/v1/master/imports/{$changed['id']}/commit", [], $this->key())->assertOk();
        $this->assertSame(160_000_000, (int) DB::table('rate_lines')->where('id', $freight->id)->value('unit_price_minor'));
        $this->assertSame(2, DB::table('rate_lines')->where('rate_table_id', $t['id'])->count());
    }

    public function test_lines_cannot_be_imported_into_an_approved_tariff_or_without_permission(): void
    {
        $t = $this->approved([], [[]]);
        $csv = "Kode biaya (kode);Dasar tarif;Tarif per satuan (Rp)\nLOADING;TRIP;100\n";
        $this->upload($t['id'], $csv)->assertStatus(409);

        $draft = $this->table(['code' => 'D2']);
        $viewer = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $viewer->id, 'role' => 'finance', 'active' => true]);
        $this->as($viewer)->post("/api/v1/tariffs/{$draft['id']}/imports", ['file' => UploadedFile::fake()->createWithContent('b.csv', $csv)], ['Accept' => 'application/json'])->assertForbidden();
        $job = $this->upload($draft['id'], $csv)->assertCreated()->json('data');
        $this->as($viewer)->postJson("/api/v1/master/imports/{$job['id']}/commit", [], $this->key())->assertForbidden();
    }

    public function test_detail_shows_lines_with_labels_and_versions(): void
    {
        $t = $this->table();
        $this->line($t['id'], ['origin_city_id' => $this->m['jkt']]);
        $d = $this->as($this->maker)->getJson("/api/v1/tariffs/{$t['id']}")->assertOk()->json('data');
        $this->assertSame('31.71 · Jakarta', $d['lines'][0]['origin_city_label']);
        $this->assertSame('FREIGHT · Ongkos angkut', $d['lines'][0]['charge_code_label']);
        $this->assertCount(1, $d['versions']);
    }
}

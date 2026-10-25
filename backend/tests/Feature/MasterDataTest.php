<?php

namespace Tests\Feature;

use App\Domain\Master\MasterDefaults;
use App\Domain\Master\MasterRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** Master data (ARK/OP adoption, F1/M1): customers, projects, locations, regional reference, carrier types, goods, services, charge codes. */
class MasterDataTest extends TestCase
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

    private function customer(array $o = []): array
    {
        return $this->postJson('/api/v1/master/customers', $o + ['code' => 'cust-01', 'name' => 'PT Maju Jaya', 'payment_terms_days' => 30], $this->key())->assertCreated()->json('data');
    }

    public function test_the_schema_describes_every_master_for_the_screen(): void
    {
        $schema = $this->getJson('/api/v1/master/schema')->assertOk()->json('data');

        $this->assertSame(array_keys(MasterRegistry::all()), array_column($schema['resources'], 'key'));
        $this->assertArrayHasKey('customers', $schema['groups']);
        foreach ($schema['resources'] as $resource) {
            foreach ($resource['fields'] as $field) {
                $this->assertArrayNotHasKey('rules', $field, 'server-only rules are not sent');
            }
        }
    }

    public function test_a_customer_is_created_normalized_and_audited(): void
    {
        $c = $this->customer(['tax_id' => '01.234.567.8-901.000', 'pic_email' => 'pic@maju.test']);

        $this->assertSame('CUST-01', $c['code'], 'codes are stored upper case');
        $this->assertSame('012345678901000', $c['tax_id'], 'only the digits of the tax number are kept');
        $this->assertTrue((bool) $c['active']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenant, 'action' => 'master.customers.created', 'subject_id' => $c['id']]);
        $this->assertDatabaseHas('outbox_events', ['tenant_id' => $this->tenant, 'type' => 'master.customers.created.v1']);
    }

    public function test_codes_are_unique_per_tenant_and_validated(): void
    {
        $this->customer();
        $this->postJson('/api/v1/master/customers', ['code' => 'cust-01', 'name' => 'Lain', 'payment_terms_days' => 30], $this->key())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/master/customers', ['code' => 'bad code!', 'name' => 'Lain', 'payment_terms_days' => 30], $this->key())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/master/customers', ['code' => 'X1', 'name' => 'Lain', 'payment_terms_days' => 30, 'tax_id' => '123'], $this->key())->assertUnprocessable()->assertJsonValidationErrors('tax_id');
        $this->postJson('/api/v1/master/customers', ['code' => 'X1', 'name' => 'Lain', 'payment_terms_days' => 999], $this->key())->assertUnprocessable()->assertJsonValidationErrors('payment_terms_days');
        $this->postJson('/api/v1/master/customers', ['code' => 'X1', 'name' => 'Lain', 'payment_terms_days' => 30, 'pic_email' => 'nope'], $this->key())->assertUnprocessable()->assertJsonValidationErrors('pic_email');

        // Another tenant may use the same code.
        $other = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $other, 'slug' => 'other', 'name' => 'Other']);
        DB::table('customers')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $other, 'code' => 'OTHER-1', 'name' => 'X', 'payment_terms_days' => 30]);
        $this->customer(['code' => 'other-1']);
    }

    public function test_only_members_with_master_manage_may_write_but_operations_users_read(): void
    {
        $this->customer();
        $this->signInTenantMember('viewer', 'viewer-tenant');

        $this->getJson('/api/v1/master/customers')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/v1/master/customers', ['code' => 'V1', 'name' => 'Nope', 'payment_terms_days' => 30], $this->key())->assertForbidden();
    }

    public function test_a_tenant_never_sees_or_changes_anothers_master_data(): void
    {
        $mine = $this->customer();
        $other = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $other, 'slug' => 'other', 'name' => 'Other']);
        $foreign = (string) Str::uuid();
        DB::table('customers')->insert(['id' => $foreign, 'tenant_id' => $other, 'code' => 'F1', 'name' => 'Foreign', 'payment_terms_days' => 30]);

        $this->getJson('/api/v1/master/customers')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $mine['id']);
        $this->putJson("/api/v1/master/customers/{$foreign}", ['name' => 'Hijack', 'payment_terms_days' => 30], $this->key())->assertNotFound();
        $this->postJson("/api/v1/master/customers/{$foreign}/status", ['active' => false, 'reason' => 'bukan milik saya'], $this->key())->assertNotFound();
        $this->assertSame('Foreign', DB::table('customers')->where('id', $foreign)->value('name'));
    }

    public function test_a_project_belongs_to_a_customer_of_the_same_tenant(): void
    {
        $c = $this->customer();
        $p = $this->postJson('/api/v1/master/customer_projects', ['code' => 'prj-1', 'name' => 'Proyek 1', 'customer_id' => $c['id'], 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31'], $this->key())
            ->assertCreated()->json('data');
        $this->assertSame('CUST-01 · PT Maju Jaya', $p['customer_id_label']);

        $other = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $other, 'slug' => 'other', 'name' => 'Other']);
        $foreign = (string) Str::uuid();
        DB::table('customers')->insert(['id' => $foreign, 'tenant_id' => $other, 'code' => 'F1', 'name' => 'Foreign', 'payment_terms_days' => 30]);

        $this->postJson('/api/v1/master/customer_projects', ['code' => 'prj-2', 'name' => 'X', 'customer_id' => $foreign], $this->key())->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->postJson('/api/v1/master/customer_projects', ['code' => 'prj-3', 'name' => 'X', 'customer_id' => $c['id'], 'valid_from' => '2026-06-01', 'valid_until' => '2026-01-01'], $this->key())->assertUnprocessable()->assertJsonValidationErrors('valid_until');
    }

    public function test_a_location_needs_both_coordinates_a_valid_range_and_a_unique_name(): void
    {
        $base = ['code' => 'pool-jkt', 'name' => 'Pool Jakarta', 'type' => 'POOL'];
        $this->postJson('/api/v1/master/locations', $base + ['latitude' => -6.17511], $this->key())->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->postJson('/api/v1/master/locations', $base + ['geofence_radius_m' => 200], $this->key())->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);
        $this->postJson('/api/v1/master/locations', $base + ['latitude' => 95, 'longitude' => 106.8], $this->key())->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $this->postJson('/api/v1/master/locations', $base + ['latitude' => -6.1, 'longitude' => 181], $this->key())->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->postJson('/api/v1/master/locations', array_replace($base, ['type' => 'MOON']), $this->key())->assertUnprocessable()->assertJsonValidationErrors('type');

        $ok = $this->postJson('/api/v1/master/locations', $base + ['latitude' => -6.17511, 'longitude' => 106.865039, 'geofence_radius_m' => 250], $this->key())->assertCreated()->json('data');
        $this->assertEquals(-6.17511, $ok['latitude']);
        $this->postJson('/api/v1/master/locations', ['code' => 'pool-2', 'name' => 'Pool Jakarta', 'type' => 'POOL'], $this->key())->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/master/locations', ['code' => 'wh-1', 'name' => 'Gudang 1', 'type' => 'WAREHOUSE'], $this->key())->assertCreated();
    }

    public function test_references_resolve_through_region_city_and_area(): void
    {
        $region = $this->postJson('/api/v1/master/regions', ['code' => '32', 'name' => 'Jawa Barat'], $this->key())->assertCreated()->json('data');
        $city = $this->postJson('/api/v1/master/cities', ['code' => '32.73', 'name' => 'Kota Bandung', 'region_id' => $region['id']], $this->key())->assertCreated()->json('data');
        $zone = $this->postJson('/api/v1/master/zones', ['code' => 'Z1', 'name' => 'Zona 1'], $this->key())->assertCreated()->json('data');
        $area = $this->postJson('/api/v1/master/areas', ['code' => 'BDG-UTARA', 'name' => 'Bandung Utara', 'city_id' => $city['id'], 'zone_id' => $zone['id']], $this->key())->assertCreated()->json('data');

        $this->assertSame('32.73 · Kota Bandung', $area['city_id_label']);
        $this->assertSame('Z1 · Zona 1', $area['zone_id_label']);
        $this->postJson('/api/v1/master/cities', ['code' => 'X', 'name' => 'Tanpa provinsi'], $this->key())->assertUnprocessable()->assertJsonValidationErrors('region_id');
    }

    public function test_goods_charge_codes_and_services_follow_their_rules(): void
    {
        $unit = $this->postJson('/api/v1/master/units', ['code' => 'LTR', 'name' => 'Liter'], $this->key())->assertCreated()->json('data');
        $this->postJson('/api/v1/master/goods', ['code' => 'SKU-1', 'name' => 'Minyak goreng', 'unit_id' => $unit['id'], 'unit_weight_g' => 900, 'stackable' => '0'], $this->key())->assertCreated()->assertJsonPath('data.stackable', false);
        $this->postJson('/api/v1/master/goods', ['code' => 'SKU-2', 'name' => 'Tanpa kebiasaan'], $this->key())->assertUnprocessable()->assertJsonValidationErrors('stackable');

        $this->postJson('/api/v1/master/charge_codes', ['code' => 'ASAM', 'name' => 'Uang asam', 'side' => 'BUY', 'nature' => 'ALLOWANCE', 'fact_key' => 'asam'], $this->key())->assertCreated()->assertJsonPath('data.fact_key', 'ASAM');
        $this->postJson('/api/v1/master/charge_codes', ['code' => 'BAD', 'name' => 'Salah', 'side' => 'BUY', 'nature' => 'ALLOWANCE', 'fact_key' => '1 bad key'], $this->key())->assertUnprocessable()->assertJsonValidationErrors('fact_key');
        $this->postJson('/api/v1/master/charge_codes', ['code' => 'BAD2', 'name' => 'Salah', 'side' => 'NEITHER', 'nature' => 'ALLOWANCE', 'fact_key' => 'X'], $this->key())->assertUnprocessable()->assertJsonValidationErrors('side');
        $this->postJson('/api/v1/master/services', ['code' => 'SLOW', 'name' => 'Lambat', 'sla_hours' => 0], $this->key())->assertUnprocessable()->assertJsonValidationErrors('sla_hours');
    }

    public function test_an_update_keeps_the_code_records_only_real_changes_and_audits_them(): void
    {
        $c = $this->customer();

        $this->putJson("/api/v1/master/customers/{$c['id']}", ['code' => 'HACKED', 'name' => 'PT Maju Makmur', 'payment_terms_days' => 45], $this->key())
            ->assertOk()->assertJsonPath('data.code', 'CUST-01')->assertJsonPath('data.name', 'PT Maju Makmur');
        $event = DB::table('audit_events')->where('action', 'master.customers.updated')->first();
        $changes = json_decode($event->metadata, true)['changes'];
        $this->assertSame(['name', 'payment_terms_days'], array_keys($changes));
        $this->assertSame('PT Maju Jaya', $changes['name']['from']);

        $this->putJson("/api/v1/master/customers/{$c['id']}", ['name' => 'PT Maju Makmur', 'payment_terms_days' => 45], $this->key())->assertOk();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'master.customers.updated')->count(), 'an update that changes nothing writes no event');
    }

    public function test_deactivating_needs_a_reason_hides_from_options_and_is_reversible(): void
    {
        $c = $this->customer();
        $this->customer(['code' => 'cust-02', 'name' => 'PT Dua']);

        $this->postJson("/api/v1/master/customers/{$c['id']}/status", ['active' => false], $this->key())->assertUnprocessable();
        $this->postJson("/api/v1/master/customers/{$c['id']}/status", ['active' => false, 'reason' => 'kontrak berakhir'], $this->key())->assertOk()->assertJsonPath('data.active', false);

        $this->assertCount(1, $this->getJson('/api/v1/master/customers/options')->assertOk()->json('data'));
        $this->getJson('/api/v1/master/customers?active=0')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.code', 'CUST-01');
        $this->getJson('/api/v1/master/customers?active=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.code', 'CUST-02');
        $this->assertDatabaseHas('audit_events', ['action' => 'master.customers.deactivated', 'subject_id' => $c['id']]);

        $this->postJson("/api/v1/master/customers/{$c['id']}/status", ['active' => true], $this->key())->assertOk()->assertJsonPath('data.active', true);
        $this->assertDatabaseHas('audit_events', ['action' => 'master.customers.activated', 'subject_id' => $c['id']]);
    }

    public function test_the_list_searches_across_code_name_and_tax_id_and_pages(): void
    {
        $this->customer(['code' => 'a1', 'name' => 'Alpha Trading', 'tax_id' => '111111111111111']);
        $this->customer(['code' => 'b1', 'name' => 'Beta Logistik', 'tax_id' => '222222222222222']);

        $this->getJson('/api/v1/master/customers?q=alpha')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.code', 'A1');
        $this->getJson('/api/v1/master/customers?q=2222')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.code', 'B1');
        $this->getJson('/api/v1/master/customers?q=100%25')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/master/customers?page=1')->assertOk()->assertJsonPath('current_page', 1)->assertJsonPath('last_page', 1);
    }

    public function test_unknown_masters_are_not_found(): void
    {
        $this->getJson('/api/v1/master/nothing')->assertNotFound();
        $this->postJson('/api/v1/master/users', ['code' => 'X', 'name' => 'Y'], $this->key())->assertNotFound();
    }

    public function test_starter_data_is_idempotent_and_never_overwrites_a_tenants_changes(): void
    {
        MasterDefaults::seed($this->tenant);
        $this->assertSame(1, DB::table('units')->where('tenant_id', $this->tenant)->where('code', 'PALLET')->count());
        $this->assertGreaterThanOrEqual(3, DB::table('services')->where('tenant_id', $this->tenant)->count());

        DB::table('units')->where('tenant_id', $this->tenant)->where('code', 'PALLET')->update(['name' => 'Palet kayu', 'active' => false]);
        MasterDefaults::seed($this->tenant);

        $unit = DB::table('units')->where('tenant_id', $this->tenant)->where('code', 'PALLET')->first();
        $this->assertSame('Palet kayu', $unit->name);
        $this->assertFalse((bool) $unit->active);
        $this->assertSame('DRIVER_ADVANCE', DB::table('charge_codes')->where('tenant_id', $this->tenant)->where('code', 'DRIVER_ADVANCE')->value('fact_key'));
    }
}

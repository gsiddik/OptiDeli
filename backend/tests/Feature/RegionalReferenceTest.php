<?php

namespace Tests\Feature;

use App\Domain\Master\RegionalReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** The official province and regency/city list (Kemendagri codes) is loaded into a tenant's master data on request. */
class RegionalReferenceTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('dispatcher');
    }

    public function test_the_reference_file_is_the_complete_official_list(): void
    {
        $rows = RegionalReference::rows();

        $this->assertCount(38, array_filter($rows, fn ($r) => ! str_contains($r[0], '.')));
        $this->assertCount(514, array_filter($rows, fn ($r) => str_contains($r[0], '.')));
        $this->assertSame(count($rows), count(array_unique(array_column($rows, 0))), 'codes are unique');
        $names = array_column($rows, 1, 0);
        $this->assertSame('Jawa Barat', $names['32']);
        $this->assertSame('Kota Bandung', $names['32.73']);
        $this->assertSame('Kota Administrasi Jakarta Utara', $names['31.72'], 'no stray spaces');
    }

    public function test_loading_adds_every_province_and_city_linked_to_its_province(): void
    {
        $this->postJson('/api/v1/master/regional-reference')->assertOk()
            ->assertJsonPath('data.regions_created', 38)->assertJsonPath('data.cities_created', 514)
            ->assertJsonPath('data.regions_existing', 0)->assertJsonPath('data.cities_existing', 0);

        $this->assertSame(38, DB::table('regions')->where('tenant_id', $this->tenant)->count());
        $this->assertSame(514, DB::table('cities')->where('tenant_id', $this->tenant)->count());
        $bandung = DB::table('cities')->where('tenant_id', $this->tenant)->where('code', '32.73')->first();
        $this->assertSame('32', DB::table('regions')->where('id', $bandung->region_id)->value('code'));
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenant, 'action' => 'master.regional_reference.loaded']);
    }

    public function test_loading_again_changes_nothing_and_keeps_a_tenants_edits(): void
    {
        $this->postJson('/api/v1/master/regional-reference')->assertOk();
        DB::table('regions')->where('tenant_id', $this->tenant)->where('code', '32')->update(['name' => 'Jabar', 'active' => false]);
        DB::table('cities')->where('tenant_id', $this->tenant)->where('code', '32.73')->delete();

        $this->postJson('/api/v1/master/regional-reference')->assertOk()
            ->assertJsonPath('data.regions_created', 0)->assertJsonPath('data.regions_existing', 38)
            ->assertJsonPath('data.cities_created', 1)->assertJsonPath('data.cities_existing', 513);

        $region = DB::table('regions')->where('tenant_id', $this->tenant)->where('code', '32')->first();
        $this->assertSame('Jabar', $region->name);
        $this->assertFalse((bool) $region->active);
        $this->assertSame(38, DB::table('regions')->where('tenant_id', $this->tenant)->count());
    }

    public function test_it_is_per_tenant_and_needs_permission(): void
    {
        $other = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $other, 'slug' => 'other', 'name' => 'Other']);
        $this->postJson('/api/v1/master/regional-reference')->assertOk();
        $this->assertSame(0, DB::table('regions')->where('tenant_id', $other)->count());

        $this->signInTenantMember('viewer', 'viewer-tenant');
        $this->postJson('/api/v1/master/regional-reference')->assertForbidden();
    }

    public function test_the_loaded_cities_are_usable_in_the_master_screens(): void
    {
        $this->postJson('/api/v1/master/regional-reference')->assertOk();

        $this->getJson('/api/v1/master/cities?q=Bandung')->assertOk()->assertJsonPath('data.0.region_id_label', '32 · Jawa Barat');
        $this->getJson('/api/v1/master/regions/options')->assertOk()->assertJsonCount(38, 'data');
        $city = $this->getJson('/api/v1/master/cities/options')->assertOk()->json('data.0');
        $this->assertArrayHasKey('region_id', $city, 'options carry the parent so a form can filter');
    }
}

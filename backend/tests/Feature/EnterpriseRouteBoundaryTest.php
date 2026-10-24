<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorRateCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

class EnterpriseRouteBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use SignsInTenantMember;

    public static function enterpriseRoutes(): array
    {
        return [
            ['GET', '/api/v1/rate-cards'], ['POST', '/api/v1/rate-cards'], ['POST', '/api/v1/rate-cards/calculate-estimate'],
            ['GET', '/api/v1/tenders'], ['POST', '/api/v1/tenders/waterfall/start'], ['POST', '/api/v1/tenders/offers/x/respond'],
            ['POST', '/api/v1/load-optimization/build'],
            ['POST', '/api/v1/telematics/ping'], ['GET', '/api/v1/telematics/live'], ['POST', '/api/v1/telematics/radar-embed'], ['POST', '/api/v1/telematics/alerts/x/resolve'],
            ['POST', '/api/v1/esg/calculate-trip'], ['GET', '/api/v1/esg/summary'], ['GET', '/api/v1/esg/records'],
            ['POST', '/api/v1/edi/inbound-204'], ['POST', '/api/v1/edi/generate-214'], ['POST', '/api/v1/edi/sync-210'], ['GET', '/api/v1/edi/transmissions'],
            ['GET', '/api/v1/ecosystem/context'], ['GET', '/api/v1/fleet/assets/B-1/readiness'], ['POST', '/api/v1/accounting/sync'],
        ];
    }

    #[DataProvider('enterpriseRoutes')]
    public function test_anonymous_requests_are_rejected_even_with_tenant_header(string $method, string $uri): void
    {
        $this->json($method, $uri, [], ['X-Tenant-Id' => 'demo'])->assertUnauthorized();
    }

    public function test_unprefixed_v1_duplicates_are_gone(): void
    {
        $this->getJson('/v1/rate-cards', ['X-Tenant-Id' => 'demo'])->assertNotFound();
    }

    public function test_tenant_header_cannot_reach_another_tenant(): void
    {
        $tenant = $this->signInTenantMember();
        foreach (['demo' => 'VND-OTHER', $tenant => 'VND-MINE'] as $owner => $vendor) {
            VendorRateCard::create(['tenant_id' => $owner, 'vendor_id' => $vendor, 'vehicle_type' => 'CDD', 'zone_origin' => 'A', 'zone_destination' => 'B', 'base_rate_minor_units' => 1, 'rate_per_km_minor_units' => 1, 'min_distance_km' => 0, 'is_active' => true]);
        }

        $vendors = $this->getJson('/api/v1/rate-cards', ['X-Tenant-Id' => 'demo'])->assertOk()->json('data.*.vendor_id');

        $this->assertSame(['VND-MINE'], $vendors);
    }

    public function test_viewer_cannot_mutate_but_can_read(): void
    {
        $this->signInTenantMember('viewer');
        $this->getJson('/api/v1/esg/summary')->assertOk();
        $this->postJson('/api/v1/accounting/sync', ['order_id' => 'O-1', 'customer_ar_minor_units' => 1, 'vendor_ap_minor_units' => 1])->assertForbidden();
        $this->postJson('/api/v1/rate-cards', [])->assertForbidden();
    }

    public function test_vendor_member_is_kept_out(): void
    {
        $tenant = $this->signInTenantMember('dispatcher');
        $vendor = (string) Str::uuid();
        DB::table('vendors')->insert(['id' => $vendor, 'tenant_id' => $tenant, 'name' => 'Vendor', 'code' => 'VND', 'active' => true]);
        DB::table('memberships')->update(['role' => 'vendor', 'vendor_id' => $vendor]);

        $this->getJson('/api/v1/edi/transmissions')->assertForbidden();
    }

    public function test_login_works_with_shipped_config_defaults(): void
    {
        // No config override: guards against identity_mode / standalone_tenant disappearing from config/optideli.php.
        $tenant = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $tenant, 'slug' => config('optideli.standalone_tenant'), 'name' => 'Demo']);
        $user = User::factory()->create(['email' => 'demo@example.test', 'password' => Hash::make('Test-only-password!')]);
        DB::table('memberships')->insert(['tenant_id' => $tenant, 'user_id' => $user->id, 'role' => 'dispatcher', 'active' => true]);

        $this->assertSame('local', config('optideli.identity_mode'));
        $this->postJson('/api/v1/auth/login', ['email' => 'demo@example.test', 'password' => 'Test-only-password!', 'tenant' => 'demo'])->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.tenant.slug', 'demo');
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Platform\PlatformAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Platform-Test-Pass!1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['optideli.deployment_mode' => 'saas', 'optideli.identity_mode' => 'local']);
        PlatformAccess::sync();
    }

    private function platformUser(string $role = 'platform_administrator'): User
    {
        $user = User::factory()->create(['email' => strtolower(Str::random(8)).'@platform.test', 'password' => Hash::make(self::PASSWORD)]);
        DB::table('platform_role_assignments')->insert(['user_id' => $user->id, 'platform_role_id' => DB::table('platform_roles')->where('name', $role)->value('id'), 'created_at' => now()]);

        return $user;
    }

    private function login(User $user): void
    {
        $this->postJson('/api/v1/platform/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
    }

    public function test_superadmin_creates_tenant_and_its_administrator_who_can_manage_roles(): void
    {
        $this->login($this->platformUser());
        $tenant = $this->postJson('/api/v1/platform/tenants', ['slug' => 'acme', 'name' => 'Acme Logistics'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/platform/tenants/{$tenant}/administrators", ['name' => 'Acme Admin', 'email' => 'admin@acme.test', 'password' => 'Acme-Admin-Pass!9'])->assertCreated();
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'platform.tenant_admin_created', 'subject_id' => $tenant]);

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@acme.test', 'password' => 'Acme-Admin-Pass!9', 'tenant' => 'acme'])->assertOk();
        $this->getJson('/api/v1/access/roles')->assertOk();
        $this->getJson('/api/v1/platform/tenants')->assertForbidden();
    }

    public function test_suspending_a_tenant_blocks_its_members(): void
    {
        $tenant = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $tenant, 'slug' => 'beta', 'name' => 'Beta']);
        $member = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $tenant, 'user_id' => $member->id, 'role' => 'dispatcher', 'active' => true]);
        $this->login($this->platformUser());

        $this->postJson("/api/v1/platform/tenants/{$tenant}/status", ['active' => false, 'reason' => 'Kontrak berakhir, tenant ditangguhkan.'])->assertOk();

        $this->actingAs($member)->withSession(['tenant_id' => $tenant])->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_support_role_can_only_read(): void
    {
        $this->login($this->platformUser('platform_support'));
        $this->getJson('/api/v1/platform/tenants')->assertOk();
        $this->postJson('/api/v1/platform/tenants', ['slug' => 'nope', 'name' => 'Nope'])->assertForbidden();
    }

    public function test_tenant_members_cannot_use_platform_login_or_api(): void
    {
        $member = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $this->postJson('/api/v1/platform/auth/login', ['email' => $member->email, 'password' => self::PASSWORD])->assertUnprocessable();
        $this->actingAs($member)->getJson('/api/v1/platform/tenants')->assertForbidden();
    }

    public function test_platform_session_does_not_open_tenant_routes(): void
    {
        $admin = $this->platformUser();
        $tenant = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $tenant, 'slug' => 'gamma', 'name' => 'Gamma']);
        DB::table('memberships')->insert(['tenant_id' => $tenant, 'user_id' => $admin->id, 'role' => 'cfo', 'active' => true]);
        $this->login($admin);

        $this->getJson('/api/v1/orders')->assertForbidden();
    }

    public function test_standalone_platform_only_creates_the_standalone_tenant(): void
    {
        config(['optideli.deployment_mode' => 'standalone', 'optideli.standalone_tenant' => 'demo']);
        $this->login($this->platformUser());
        $this->postJson('/api/v1/platform/tenants', ['slug' => 'other', 'name' => 'Other'])->assertUnprocessable();
        $this->postJson('/api/v1/platform/tenants', ['slug' => 'demo', 'name' => 'Demo'])->assertCreated();
    }

    public function test_bootstrap_command_creates_a_platform_administrator(): void
    {
        putenv('OPTIDELI_PLATFORM_ADMIN_PASSWORD='.self::PASSWORD);
        try {
            $this->artisan('optideli:create-platform-admin', ['email' => 'root@platform.test'])->assertExitCode(0);
        } finally {
            putenv('OPTIDELI_PLATFORM_ADMIN_PASSWORD');
        }
        $this->postJson('/api/v1/platform/auth/login', ['email' => 'root@platform.test', 'password' => self::PASSWORD])->assertOk();
        $this->getJson('/api/v1/platform/auth/me')->assertOk()->assertJsonPath('data.permissions.0', 'platform.admin.manage');
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Access\SystemRoles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['optideli.deployment_mode' => 'saas', 'optideli.identity_mode' => 'local']);
        $this->tenant = $this->tenant('test');
    }

    private function tenant(string $slug): string
    {
        $id = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $id, 'slug' => $slug, 'name' => ucfirst($slug)]);

        return $id;
    }

    private function member(string $role, ?string $tenant = null): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $tenant ?? $this->tenant, 'user_id' => $user->id, 'role' => $role, 'active' => true]);

        return $user;
    }

    private function as(User $user, ?string $tenant = null): static
    {
        return $this->actingAs($user)->withSession(['tenant_id' => $tenant ?? $this->tenant]);
    }

    private function membershipId(User $user): int
    {
        return DB::table('memberships')->where('user_id', $user->id)->value('id');
    }

    private function roleId(string $name): string
    {
        return DB::table('roles')->where('tenant_id', $this->tenant)->where('name', $name)->value('id');
    }

    public function test_legacy_roles_keep_their_access_through_system_roles(): void
    {
        $this->as($this->member('dispatcher'))->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.role', 'dispatcher')
            ->assertJsonPath('data.permissions', ['enterprise.manage', 'operations.manage', 'operations.view']);
        $this->as($this->member('viewer'))->getJson('/api/v1/auth/me')->assertJsonPath('data.permissions', ['operations.view']);
    }

    public function test_unknown_legacy_role_gets_no_access(): void
    {
        $this->as($this->member('intruder'))->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_admin_creates_custom_role_and_assigns_it(): void
    {
        $admin = $this->member('tenant_administrator');
        $clerk = $this->member('viewer');
        $this->as($admin)->getJson('/api/v1/auth/me')->assertOk();

        $role = $this->as($admin)->postJson('/api/v1/access/roles', ['name' => 'Policy clerk', 'permissions' => ['operations.view', 'policy.manage']])->assertCreated()->json('data.id');
        $this->as($admin)->putJson('/api/v1/access/members/'.$this->membershipId($clerk).'/roles', ['role_ids' => [$role]])->assertOk();

        $this->as($clerk)->getJson('/api/v1/auth/me')->assertJsonPath('data.permissions', ['operations.view', 'policy.manage']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenant, 'action' => 'access.member_roles_changed']);
    }

    public function test_a_member_cannot_grant_permissions_they_do_not_hold(): void
    {
        $admin = $this->member('tenant_administrator');
        $this->as($admin)->getJson('/api/v1/auth/me')->assertOk();
        $limited = $this->member('viewer');
        $role = $this->as($admin)->postJson('/api/v1/access/roles', ['name' => 'Role admin', 'permissions' => ['operations.view', 'access.role.manage', 'access.role.view']])->assertCreated()->json('data.id');
        $this->as($admin)->putJson('/api/v1/access/members/'.$this->membershipId($limited).'/roles', ['role_ids' => [$role]])->assertOk();

        $this->as($limited)->postJson('/api/v1/access/roles', ['name' => 'Escalate', 'permissions' => ['policy.manage']])->assertForbidden();
    }

    public function test_nobody_changes_their_own_roles_and_system_roles_are_fixed(): void
    {
        $admin = $this->member('tenant_administrator');
        $this->as($admin)->getJson('/api/v1/auth/me')->assertOk();

        $this->as($admin)->putJson('/api/v1/access/members/'.$this->membershipId($admin).'/roles', ['role_ids' => [$this->roleId('viewer')]])->assertForbidden();
        $this->as($admin)->putJson('/api/v1/access/roles/'.$this->roleId('viewer'), ['name' => 'viewer', 'permissions' => ['operations.view', 'policy.manage']])->assertStatus(409);
        $this->as($admin)->deleteJson('/api/v1/access/roles/'.$this->roleId('cfo'))->assertStatus(409);
    }

    public function test_removing_access_is_not_undone_by_the_legacy_label(): void
    {
        $admin = $this->member('tenant_administrator');
        $cfo = $this->member('cfo');
        $this->as($cfo)->getJson('/api/v1/auth/me')->assertOk();
        $this->as($admin)->putJson('/api/v1/access/members/'.$this->membershipId($cfo).'/roles', ['role_ids' => [$this->roleId('viewer')]])->assertOk();

        $this->as($cfo)->putJson('/api/v1/commercial/policy', ['minimum_margin_bps' => 1000, 'stage_margin_bps' => [], 'reason' => 'Mencoba ubah kebijakan margin tanpa izin.'])->assertForbidden();
        app(SystemRoles::class)->syncAll();
        $this->as($cfo)->getJson('/api/v1/auth/me')->assertJsonPath('data.permissions', ['operations.view']);
    }

    public function test_roles_of_another_tenant_cannot_be_assigned(): void
    {
        $other = $this->tenant('other');
        $this->as($this->member('viewer', $other), $other)->getJson('/api/v1/auth/me')->assertOk();
        $foreignRole = DB::table('roles')->where('tenant_id', $other)->where('name', 'cfo')->value('id');
        $admin = $this->member('tenant_administrator');
        $target = $this->member('viewer');
        $this->as($admin)->getJson('/api/v1/auth/me')->assertOk();

        $this->as($admin)->putJson('/api/v1/access/members/'.$this->membershipId($target).'/roles', ['role_ids' => [$foreignRole]])->assertUnprocessable();
        $this->as($admin)->getJson('/api/v1/access/roles')->assertOk()->assertJsonMissing(['id' => $foreignRole]);
    }

    public function test_role_management_needs_permission(): void
    {
        $this->as($this->member('cfo'))->getJson('/api/v1/access/roles')->assertForbidden();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Domain\Platform\PlatformAccess;
use App\Domain\Platform\TenantAdministration;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Superadmin console API (/api/v1/platform). Separate login; tenant data is never read here beyond tenant metadata. */
class PlatformController extends Controller
{
    public function login(Request $request)
    {
        abort_unless(config('optideli.identity_mode') === 'local', 503, 'Login platform lokal tidak aktif.');
        $data = $request->validate(['email' => 'required|email|max:254', 'password' => 'required|string|max:1024']);
        $user = User::where('email', strtolower($data['email']))->first();
        $valid = $user ? Hash::check($data['password'], $user->password) : Hash::check($data['password'], '$2y$12$8G9O0YJy4t3GpGO7cTIijOBRQPfaANkBhnU8YfrfC/n2C/4ELkZfW');
        if (! $user || ! $valid || PlatformAccess::permissionsFor($user->id) === []) {
            throw ValidationException::withMessages(['email' => 'Kredensial platform tidak sesuai.']);
        }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('tenant_id');
        $request->session()->put('platform', true);
        PlatformAccess::audit($user->id, 'platform.login', (string) $user->id);

        return response()->json(['data' => ['name' => $user->name]]);
    }

    public function me(Request $request)
    {
        return response()->json(['data' => ['id' => $request->user()->id, 'name' => $request->user()->name, 'permissions' => $request->attributes->get('platform_permissions'), 'deployment_mode' => config('optideli.deployment_mode'), 'standalone_tenant' => config('optideli.deployment_mode') === 'standalone' ? config('optideli.standalone_tenant') : null]]);
    }

    public function tenants(Request $request)
    {
        PlatformAccess::authorize($request, 'platform.tenant.view');
        $members = DB::table('memberships')->where('active', true)->selectRaw('tenant_id, count(*) as total')->groupBy('tenant_id')->pluck('total', 'tenant_id');

        return response()->json(['data' => DB::table('tenants')->orderBy('name')->get(['id', 'slug', 'name', 'active', 'created_at'])
            ->map(fn ($t) => [...(array) $t, 'active_members' => (int) ($members[$t->id] ?? 0)])]);
    }

    public function createTenant(Request $request, TenantAdministration $admin)
    {
        PlatformAccess::authorize($request, 'platform.tenant.manage');
        $data = $request->validate(['slug' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'], 'name' => 'required|string|min:2|max:150']);

        return response()->json(['data' => $admin->create($request->user()->id, $data)], 201);
    }

    public function tenantStatus(Request $request, string $id, TenantAdministration $admin)
    {
        PlatformAccess::authorize($request, 'platform.tenant.manage');
        $data = $request->validate(['active' => 'required|boolean', 'reason' => 'required|string|min:10|max:2000']);

        return response()->json(['data' => $admin->setActive($request->user()->id, $id, (bool) $data['active'], $data['reason'])]);
    }

    public function createTenantAdmin(Request $request, string $id, TenantAdministration $admin)
    {
        PlatformAccess::authorize($request, 'platform.tenant_admin.create');
        $data = $request->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:254', 'password' => ['required', 'string', Password::min(12)->letters()->mixedCase()->numbers()->symbols()]]);

        return response()->json(['data' => $admin->createAdministrator($request->user()->id, $id, $data)], 201);
    }

    public function audit(Request $request)
    {
        PlatformAccess::authorize($request, 'platform.audit.view');

        return response()->json(DB::table('platform_audit_events')->orderByDesc('created_at')->paginate(50));
    }
}

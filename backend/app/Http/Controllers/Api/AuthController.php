<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\SessionState;
use App\Domain\Integration\Optinexus\IdentityProviderUnavailable;
use App\Domain\Integration\Optinexus\OidcClient;
use App\Domain\Integration\Optinexus\OptinexusSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // In OptiNexus mode tenant users sign in at OptiNexus only; the Superadmin keeps the separate platform login.
        abort_if(OptinexusSettings::active(), 403, 'Masuk melalui OptiNexus.');
        abort_unless(config('optideli.identity_mode') === 'local', 503, 'Unsupported identity mode.');
        abort_unless(in_array(config('optideli.deployment_mode'), ['saas', 'standalone'], true), 503);
        $data = $request->validate(['email' => 'required|email|max:254', 'password' => 'required|string|max:1024', 'tenant' => 'required|string|max:80']);
        $tenant = DB::table('tenants')->where('slug', $data['tenant'])->where('active', true)->first();
        $user = User::where('email', strtolower($data['email']))->first();
        $valid = $user && Hash::check($data['password'], $user->password) && $user->active;
        if (! $user) {
            Hash::check($data['password'], '$2y$12$8G9O0YJy4t3GpGO7cTIijOBRQPfaANkBhnU8YfrfC/n2C/4ELkZfW');
        }
        $membership = $tenant && $user ? DB::table('memberships')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('active', true)->exists() : false;
        $standalone = config('optideli.deployment_mode') !== 'standalone' || ($tenant?->slug === config('optideli.standalone_tenant'));
        if (! $valid || ! $membership || ! $standalone) {
            throw ValidationException::withMessages(['email' => 'Kredensial atau tenant tidak sesuai.']);
        }
        SessionState::start($request, $user, $tenant->id);

        return response()->json(['data' => ['name' => $user->name]]);
    }

    public function me(Request $request)
    {
        $tenant = $request->attributes->get('tenant');

        return response()->json(['data' => ['id' => $request->user()->id, 'name' => $request->user()->name, 'role' => $request->attributes->get('role'), 'permissions' => $request->attributes->get('permissions'), 'tenant' => ['name' => $tenant->name, 'slug' => $tenant->slug, 'minimum_margin_bps' => $tenant->minimum_margin_bps, 'stage_margin_bps' => json_decode($tenant->stage_margin_bps ?? '{}')], 'deployment_mode' => config('optideli.deployment_mode'), 'identity_mode' => config('optideli.identity_mode')]]);
    }

    public function logout(Request $request, OidcClient $oidc)
    {
        $hint = $request->session()->get('optinexus_sso') ? $request->session()->get('optinexus_id_token_hint') : false;
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($hint === false) {
            return response()->noContent();
        }
        try {
            // Ends the OptiNexus session too, so the next visit does not sign the person straight back in.
            $logoutUrl = $oidc->logoutUrl(is_string($hint) ? $hint : null);
        } catch (IdentityProviderUnavailable) {
            $logoutUrl = null;
        }

        return response()->json(['data' => ['logout_url' => $logoutUrl]]);
    }
}

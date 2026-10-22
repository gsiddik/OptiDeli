<?php

namespace App\Http\Controllers\Api;

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
        abort_unless(config('optideli.identity_mode') === 'local', 503, 'OptiNexus identity adapter is pending configuration.');
        abort_unless(in_array(config('optideli.deployment_mode'), ['saas', 'standalone'], true), 503);
        $data = $request->validate(['email' => 'required|email|max:254', 'password' => 'required|string|max:1024', 'tenant' => 'required|string|max:80']);
        $tenant = DB::table('tenants')->where('slug', $data['tenant'])->where('active', true)->first();
        $user = User::where('email', strtolower($data['email']))->first();
        $valid = $user && Hash::check($data['password'], $user->password);
        if (! $user) {
            Hash::check($data['password'], '$2y$12$8G9O0YJy4t3GpGO7cTIijOBRQPfaANkBhnU8YfrfC/n2C/4ELkZfW');
        }
        $membership = $tenant && $user ? DB::table('memberships')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('active', true)->exists() : false;
        $standalone = config('optideli.deployment_mode') !== 'standalone' || ($tenant?->slug === config('optideli.standalone_tenant'));
        if (! $valid || ! $membership || ! $standalone) {
            throw ValidationException::withMessages(['email' => 'Kredensial atau tenant tidak sesuai.']);
        }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenant->id);

        return response()->json(['data' => ['name' => $user->name]]);
    }

    public function me(Request $request)
    {
        $tenant = $request->attributes->get('tenant');

        return response()->json(['data' => ['id' => $request->user()->id, 'name' => $request->user()->name, 'role' => $request->attributes->get('role'), 'tenant' => ['name' => $tenant->name, 'slug' => $tenant->slug, 'minimum_margin_bps' => $tenant->minimum_margin_bps, 'stage_margin_bps' => json_decode($tenant->stage_margin_bps ?? '{}')], 'deployment_mode' => config('optideli.deployment_mode')]]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}

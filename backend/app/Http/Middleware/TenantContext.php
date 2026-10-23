<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(in_array(config('optideli.deployment_mode'), ['standalone', 'saas'], true), 503, 'Unsupported deployment mode.');
        abort_unless(config('optideli.identity_mode') === 'local', 503, 'OptiNexus identity adapter has not been configured.');
        $tenant = DB::table('tenants')->where('id', $request->session()->get('tenant_id'))->where('active', true)->first();
        abort_unless($tenant, 403, 'Active tenant required.');
        if (config('optideli.deployment_mode') === 'standalone') {
            abort_unless($tenant->slug === config('optideli.standalone_tenant'), 403, 'Standalone tenant mismatch.');
        }
        $membership = DB::table('memberships')->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->where('active', true)->first();
        abort_unless($membership && in_array($membership->role, ['viewer', 'dispatcher', 'supervisor', 'head_of_logistics', 'cfo', 'finance', 'finance_supervisor', 'vendor'], true), 403, 'Active membership required.');
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('role', $membership->role);
        if ($membership->role === 'vendor') {
            abort_unless($membership->vendor_id && DB::table('vendors')->where('id', $membership->vendor_id)->where('tenant_id', $tenant->id)->where('active', true)->exists(), 403, 'Keanggotaan vendor tidak aktif.');
            $request->attributes->set('vendor_id', $membership->vendor_id);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Domain\Access\Access;
use App\Domain\Access\SessionState;
use App\Domain\Access\SystemRoles;
use App\Domain\Integration\Optinexus\IdentityProviderUnavailable;
use App\Domain\Integration\Optinexus\NexusPermissionSource;
use App\Domain\Integration\Optinexus\OptinexusSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(in_array(config('optideli.deployment_mode'), ['standalone', 'saas'], true), 503, 'Unsupported deployment mode.');
        $sso = OptinexusSettings::active();
        abort_unless($sso || config('optideli.identity_mode') === 'local', 503, 'Unsupported identity mode.');
        if (! SessionState::valid($request, $request->user()) || ($sso && ! $request->session()->get('optinexus_sso'))) {
            // Ended by OptiNexus, deactivated, or a password session left over from before the switch to OptiNexus.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            abort(401, 'Sesi telah berakhir. Silakan masuk kembali.');
        }
        $tenant = DB::table('tenants')->where('id', $request->session()->get('tenant_id'))->where('active', true)->first();
        abort_unless($tenant, 403, 'Active tenant required.');
        if (config('optideli.deployment_mode') === 'standalone') {
            abort_unless($tenant->slug === config('optideli.standalone_tenant'), 403, 'Standalone tenant mismatch.');
        }
        $membership = DB::table('memberships')->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->where('active', true)->first();
        abort_unless($membership, 403, 'Active membership required.');
        if ($sso) {
            try {
                // OptiNexus decides; the answer is cached at most 300 s. An outage fails closed.
                $permissions = app(NexusPermissionSource::class)->forMembership($membership, $tenant);
            } catch (IdentityProviderUnavailable) {
                abort(503, 'Layanan identitas OptiNexus sedang tidak tersedia. Coba lagi sebentar.');
            }
        } else {
            if (! $membership->roles_bootstrapped) {
                app(SystemRoles::class)->bootstrapMembership($membership);
            }
            $permissions = Access::permissionsFor($membership->id);
        }
        abort_if($permissions === [], 403, 'Active membership required.');
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('membership_id', $membership->id);
        $request->attributes->set('role', $membership->role);
        $request->attributes->set('permissions', $permissions);
        if ($membership->vendor_id !== null || in_array('vendor.portal', $permissions, true)) {
            abort_unless($membership->vendor_id && DB::table('vendors')->where('id', $membership->vendor_id)->where('tenant_id', $tenant->id)->where('active', true)->exists(), 403, 'Keanggotaan vendor tidak aktif.');
            $request->attributes->set('vendor_id', $membership->vendor_id);
        }

        return $next($request);
    }
}

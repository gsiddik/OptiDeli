<?php

namespace App\Http\Middleware;

use App\Domain\Tracking\TrackingCredentials;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Authenticates the machine that sends positions and geofence events. The tenant comes from the credential only. */
class TrackingAuthentication
{
    public function handle(Request $r, Closure $next): Response
    {
        $token = $r->bearerToken();
        if (! $token) {
            return response()->json(['message' => 'Bearer token wajib.', 'code' => 'TRACKING_UNAUTHENTICATED'], 401);
        }
        $credential = app(TrackingCredentials::class)->resolve($token);
        if (! $credential) {
            return response()->json(['message' => 'Kredensial tidak valid, kedaluwarsa, atau dicabut.', 'code' => 'TRACKING_UNAUTHENTICATED'], 401);
        }
        if (! DB::table('tenants')->where('id', $credential->tenant_id)->where('active', true)->exists()) {
            return response()->json(['message' => 'Tenant tidak aktif.', 'code' => 'TRACKING_TENANT_NOT_ACTIVE'], 403);
        }
        $r->attributes->set('tracking_credential', $credential);

        return $next($r);
    }
}

<?php

namespace App\Http\Middleware;

use App\Domain\Ledger\FeedCredentials;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Authenticates a machine caller of the Opti Integration Feed. The tenant comes from the credential only, never from the request. */
class FeedAuthentication
{
    public function handle(Request $r, Closure $next): Response
    {
        $token = $r->bearerToken();
        if (! $token) {
            return response()->json(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Bearer token wajib.']], 401);
        }
        $credential = app(FeedCredentials::class)->resolve($token);
        if (! $credential) {
            return response()->json(['error' => ['code' => 'INVALID_CREDENTIAL', 'message' => 'Kredensial tidak valid, kedaluwarsa, atau dicabut.']], 401);
        }
        if (! DB::table('tenants')->where('id', $credential->tenant_id)->where('active', true)->exists()) {
            return response()->json(['error' => ['code' => 'TENANT_INACTIVE', 'message' => 'Tenant tidak aktif.']], 403);
        }
        $r->attributes->set('feed_credential', $credential);

        return $next($r);
    }
}

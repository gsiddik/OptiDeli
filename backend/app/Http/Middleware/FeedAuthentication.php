<?php

namespace App\Http\Middleware;

use App\Domain\Integration\Optinexus\OptinexusSettings;
use App\Domain\Ledger\FeedCredentials;
use App\Domain\Ledger\FeedPairing;
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
            return response()->json(['message' => 'Bearer token wajib.', 'code' => 'FEED_UNAUTHENTICATED'], 401);
        }
        $credential = app(FeedCredentials::class)->resolve($token);
        if (! $credential) {
            return response()->json(['message' => 'Kredensial tidak valid, kedaluwarsa, atau dicabut.', 'code' => 'FEED_UNAUTHENTICATED'], 401);
        }
        if (! DB::table('tenants')->where('id', $credential->tenant_id)->where('active', true)->exists()) {
            return response()->json(['message' => 'Tenant tidak aktif.', 'code' => 'FEED_TENANT_NOT_ACTIVE'], 403);
        }
        if ($refusal = app(FeedPairing::class)->refusal($credential)) {
            $response = response()->json(['message' => $refusal[2], 'code' => $refusal[1]], $refusal[0]);

            return $refusal[0] === 503 ? $response->header('Retry-After', '30') : $response;
        }
        $r->attributes->set('feed_credential', $credential);
        if (app(FeedPairing::class)->required()) {
            $r->attributes->set('feed_pairing', [
                'nexus_tenant_id' => $credential->nexus_tenant_id, 'producer_application' => OptinexusSettings::applicationCode(),
                'consumer_application' => (string) config('optideli.optinexus.feed.consumer_application'),
            ]);
        }

        return $next($r);
    }
}

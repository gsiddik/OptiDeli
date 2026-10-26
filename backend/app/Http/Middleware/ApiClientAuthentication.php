<?php

namespace App\Http\Middleware;

use App\Domain\Customer\ApiClients;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Bearer authentication of a customer's API client, with the scope the route needs: ->middleware('api.client:spm.read'). */
class ApiClientAuthentication
{
    public function handle(Request $r, Closure $next, string $scope): Response
    {
        $token = $r->bearerToken();
        if (! $token) {
            return response()->json(['message' => 'Bearer token wajib.', 'code' => 'API_UNAUTHENTICATED'], 401);
        }
        $client = app(ApiClients::class)->resolve($token);
        if (! $client) {
            return response()->json(['message' => 'Token tidak valid, kedaluwarsa, atau dicabut.', 'code' => 'API_UNAUTHENTICATED'], 401);
        }
        if (! DB::table('tenants')->where('id', $client->tenant_id)->where('active', true)->exists()) {
            return response()->json(['message' => 'Tenant tidak aktif.', 'code' => 'API_TENANT_NOT_ACTIVE'], 403);
        }
        if (! in_array($scope, $client->scopes, true)) {
            return response()->json(['message' => "Token tidak memiliki scope {$scope}.", 'code' => 'API_FORBIDDEN_SCOPE'], 403);
        }
        $r->attributes->set('api_client', $client);

        return $next($r);
    }
}

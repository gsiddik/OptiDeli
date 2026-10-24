<?php

namespace App\Http\Controllers\Api;

use App\Domain\Integration\Optinexus\IdentityProviderUnavailable;
use App\Domain\Integration\Optinexus\NexusApi;
use App\Domain\Integration\Optinexus\OptinexusSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * K5: the OptiRadar map inside OptiDeli. OptiDeli never holds a Radar credential: OptiNexus mints a short-lived,
 * read-only embed session for the tenant (and checks the tenant is entitled to OptiRadar); the browser only gets the URL.
 */
class RadarEmbedController extends Controller
{
    public function __construct(private readonly NexusApi $nexus) {}

    public function session(Request $request): JsonResponse
    {
        $nexusTenantId = $request->attributes->get('tenant')->optinexus_tenant_id ?? null;
        if (! $nexusTenantId || OptinexusSettings::missingForService()) {
            return $this->fail('RADAR_EMBED_NOT_AVAILABLE', 'Peta OptiRadar membutuhkan tenant yang terhubung ke OptiNexus.', 409);
        }

        try {
            $response = $this->nexus->send('POST', '/embed/sessions', [
                'tenant_id' => $nexusTenantId, 'target' => 'optiradar', 'ttl_seconds' => 600,
            ], false, NexusApi::SCOPE_EMBED);
        } catch (IdentityProviderUnavailable) {
            return $this->fail('RADAR_EMBED_UNAVAILABLE', 'OptiNexus tidak dapat dihubungi.', 503);
        }

        if ($response->status() === 403) {
            return $this->fail('RADAR_EMBED_NOT_ENTITLED', 'Tenant ini belum berlangganan OptiRadar.', 403);
        }
        if (! $response->successful() || ! is_string($response->json('data.url'))) {
            return $this->fail('RADAR_EMBED_UNAVAILABLE', 'OptiRadar tidak tersedia saat ini.', 503);
        }

        return response()->json(['data' => [
            'url' => $response->json('data.url'),
            'expires_at' => $response->json('data.expires_at'),
        ]]);
    }

    private function fail(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }
}

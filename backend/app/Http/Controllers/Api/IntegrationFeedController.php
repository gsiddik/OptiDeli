<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Opti Integration Feed v1 (docs/integration/OPTI_INTEGRATION_FEED_V1.md): a consumer pulls this tenant's financial facts by sequence. */
class IntegrationFeedController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $credential = $r->attributes->get('feed_credential');
        $q = $r->validate(['after' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:500']);
        $after = (int) ($q['after'] ?? 0);
        $limit = (int) ($q['limit'] ?? 100);

        $rows = DB::table('integration_feed_events')->where('tenant_id', $credential->tenant_id)->where('sequence', '>', $after)
            ->orderBy('sequence')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();
        $highWater = (int) DB::table('integration_feed_counters')->where('tenant_id', $credential->tenant_id)->value('last_sequence');
        $tenantRef = $credential->nexus_tenant_id ?? $credential->tenant_id;

        return response()->json([
            'data' => $page->map(fn ($e) => [
                'event_id' => $e->event_id, 'sequence' => (int) $e->sequence, 'event_type' => $e->event_type, 'schema_version' => $e->schema_version,
                'source_system' => 'optideli', 'tenant_id' => $tenantRef, 'occurred_at' => CarbonImmutable::parse($e->occurred_at)->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'correlation_id' => $e->correlation_id, 'source' => json_decode($e->source, true), 'dimensions' => json_decode($e->dimensions, true), 'payload' => json_decode($e->payload, true),
            ]),
            'next_cursor' => $page->isEmpty() ? $after : (int) $page->last()->sequence,
            'has_more' => $hasMore,
            'high_water' => $highWater,
        ]);
    }
}

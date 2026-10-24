<?php

namespace App\Domain\Ledger;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes Opti Integration Feed v1 events. Must run inside the caller's transaction: the per-tenant counter row is locked until
 * that transaction commits, so sequences are gap-free and in commit order, and a rolled-back business change consumes no number.
 */
final class FeedRecorder
{
    public const SCHEMA_VERSION = '1.0';

    /**
     * @param  array{type: string, id: string, number: string}  $source
     * @param  array<string, mixed>  $dimensions
     * @param  array<string, mixed>  $payload
     */
    public function record(string $tenantId, string $eventType, string $correlationId, array $source, array $dimensions, array $payload): string
    {
        DB::table('integration_feed_counters')->insertOrIgnore(['tenant_id' => $tenantId, 'last_sequence' => 0]);
        $counter = DB::table('integration_feed_counters')->where('tenant_id', $tenantId)->lockForUpdate()->first();
        $sequence = $counter->last_sequence + 1;
        DB::table('integration_feed_counters')->where('tenant_id', $tenantId)->update(['last_sequence' => $sequence]);

        $id = (string) Str::uuid();
        DB::table('integration_feed_events')->insert([
            'event_id' => $id, 'tenant_id' => $tenantId, 'sequence' => $sequence, 'event_type' => $eventType, 'schema_version' => self::SCHEMA_VERSION,
            'occurred_at' => now(), 'correlation_id' => $correlationId, 'source' => json_encode($source, JSON_THROW_ON_ERROR),
            'dimensions' => json_encode($dimensions, JSON_THROW_ON_ERROR), 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }
}

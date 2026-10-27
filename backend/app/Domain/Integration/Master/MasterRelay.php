<?php

namespace App\Domain\Integration\Master;

use App\Domain\Integration\Optinexus\IdentityProviderUnavailable;
use App\Domain\Integration\Optinexus\NexusApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Delivers the master data outbox to OptiNexus (POST /events, scope event.write), which hands it to the other platforms.
 * The row id is the event id, so a retry after a timeout never makes a second event. Network errors, 5xx, 401/408/429 and an
 * event OptiNexus does not know yet (422 EVENT_INVALID) are retried with backoff (1 min doubling to 1 h) until MAX_ATTEMPTS;
 * any other refusal parks the row as FAILED (`optideli:master:relay --retry-failed` puts it back).
 */
final class MasterRelay
{
    public const MAX_ATTEMPTS = 200;

    public function __construct(private readonly NexusApi $api) {}

    /** @return array{delivered:int,retrying:int,failed:int} */
    public function relay(int $batch = 100): array
    {
        $result = ['delivered' => 0, 'retrying' => 0, 'failed' => 0];
        // Only tenants linked to OptiNexus: rows of an unlinked tenant wait without taking batch places from the others.
        $rows = DB::table('master_events')->where('master_events.status', 'PENDING')
            ->whereIn('master_events.tenant_id', DB::table('tenants')->whereNotNull('optinexus_tenant_id')->select('id'))
            ->whereRaw("(master_events.last_attempted_at is null or master_events.last_attempted_at <= now() - (least(60 * power(2, master_events.attempts), 3600) * interval '1 second'))")
            ->orderBy('created_at')->limit($batch)->get();

        foreach ($rows as $row) {
            $nexus = DB::table('tenants')->where('id', $row->tenant_id)->value('optinexus_tenant_id');
            if (! $nexus) {
                continue; // an unlinked tenant has nobody to tell; the row waits
            }
            DB::table('master_events')->where('id', $row->id)->update(['attempts' => $row->attempts + 1, 'last_attempted_at' => now()]);

            try {
                $response = $this->api->send('POST', '/events', [
                    'event_id' => $row->id, 'event_key' => $row->event_key, 'event_version' => '1',
                    'occurred_at' => CarbonImmutable::parse($row->created_at)->toIso8601String(), 'tenant_id' => $nexus,
                    'correlation_id' => $row->aggregate_id,
                    'data' => ['aggregate_type' => $row->event_key === VendorMaster::DRIVER ? 'driver' : 'vehicle', 'aggregate_id' => $row->aggregate_id, 'correlation' => (object) [], 'payload' => json_decode($row->payload, true)],
                ], scope: NexusApi::SCOPE_EVENT);
            } catch (IdentityProviderUnavailable $e) {
                $this->retry($row, Str::limit($e->getMessage(), 300), $result);
                if ($e->transient) {
                    break; // OptiNexus is unreachable: do not hammer it with the rest of the batch
                }

                continue;
            }

            $status = $response->status();
            if ($response->successful()) {
                DB::table('master_events')->where('id', $row->id)->update(['status' => 'DELIVERED', 'delivered_at' => now(), 'last_error' => null]);
                $result['delivered']++;
            } elseif (($status === 422 && $response->json('error.code') === 'EVENT_INVALID') || in_array($status, [401, 408, 429], true) || $status >= 500) {
                $this->retry($row, "HTTP {$status}", $result);
            } else {
                DB::table('master_events')->where('id', $row->id)->update(['status' => 'FAILED', 'last_error' => Str::limit("HTTP {$status}: ".$response->body(), 1000)]);
                $result['failed']++;
            }
        }

        return $result;
    }

    public function retryFailed(): int
    {
        return DB::table('master_events')->where('status', 'FAILED')->update(['status' => 'PENDING', 'attempts' => 0, 'last_attempted_at' => null]);
    }

    private function retry(object $row, string $error, array &$result): void
    {
        $parked = $row->attempts + 1 >= self::MAX_ATTEMPTS;
        DB::table('master_events')->where('id', $row->id)->where('status', 'PENDING')->update(['status' => $parked ? 'FAILED' : 'PENDING', 'last_error' => $error]);
        $result[$parked ? 'failed' : 'retrying']++;
    }
}

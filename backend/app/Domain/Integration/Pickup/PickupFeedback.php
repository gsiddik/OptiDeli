<?php

namespace App\Domain\Integration\Pickup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tells OptiStash what happened to a pickup request it sent (docs/integration/PICKUP_REQUESTS.md section 5): ACCEPTED when it became an order
 * and an SPM, REJECTED (with the dispatcher's reason), PICKED_UP when the SPM left on its trip, DELIVERED when it was unloaded at the
 * destination, CANCELLED when the SPM was cancelled. The facts go through the same outbox as the master data events (master_events), written in
 * the transaction of the change, and reach OptiStash through the OptiNexus event broker. Nothing else about the SPM leaves OptiDeli.
 */
final class PickupFeedback
{
    public const EVENT = 'optideli.pickup.status_changed';

    /** SPM status => what OptiStash is told. The other SPM statuses are internal planning and say nothing to the warehouse. */
    private const SPM_STATUS = ['IN_TRANSIT' => 'PICKED_UP', 'DISCHARGED' => 'DELIVERED', 'CANCELLED' => 'CANCELLED'];

    public static function forSpm(string $tenantId, string $spmId, string $spmStatus): bool
    {
        $status = self::SPM_STATUS[$spmStatus] ?? null;
        if ($status === null) {
            return false;
        }
        $request = DB::table('pickup_requests')->where('tenant_id', $tenantId)->where('transport_order_id', $spmId)->first(['id']);

        return $request ? self::publish($tenantId, $request->id, $status) : false;
    }

    /** Queues one status for OptiStash. Nothing for a tenant that is not linked to OptiNexus (nobody to tell). */
    public static function publish(string $tenantId, string $requestId, string $status, ?string $reason = null): bool
    {
        $linked = DB::table('tenants')->where('id', $tenantId)->whereNotNull('optinexus_tenant_id')->exists();
        $request = DB::table('pickup_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->first(['id', 'shipment_number', 'source_system']);
        if (! $linked || ! $request || $request->source_system !== PickupRequests::SOURCE) {
            return false;
        }
        DB::table('master_events')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'event_key' => self::EVENT, 'aggregate_id' => $request->id,
            'payload' => json_encode(array_filter([
                'shipment_number' => $request->shipment_number, 'status' => $status, 'occurred_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'reason' => $reason !== null ? mb_substr($reason, 0, 300) : null,
            ], fn ($v) => $v !== null), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return true;
    }
}

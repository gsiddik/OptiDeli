<?php

namespace App\Domain\Access;

use App\Domain\Integration\Optinexus\IdentityProviderUnavailable;
use App\Domain\Integration\Optinexus\NexusApi;
use App\Domain\Integration\Optinexus\OptinexusSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Seats of a tenant in OptiDeli. A seat is a person who can sign in to run the tenant's operations: its own staff and the users of its
 * vendors. Customers' portal users do not take a seat. The number the subscription includes (OptiNexus `user_limit`) does not stop anyone
 * being added: it is what is not charged. What is above it is billed by OptiNexus from the usage OptiDeli reports once a month: the
 * month's peak, so a person added and removed within the month still counts.
 */
final class Seats
{
    public const METER = 'user_seat';

    /** @return array{internal: int, vendor: int, total: int} */
    public static function used(string $tenantId): array
    {
        $rows = DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.tenant_id', $tenantId)->where('memberships.active', true)->where('users.active', true)
            ->whereNull('memberships.customer_id')->whereNull('memberships.optinexus_deactivated_at')
            ->selectRaw('count(*) filter (where memberships.vendor_id is null) as internal, count(*) filter (where memberships.vendor_id is not null) as vendor')->first();
        $internal = (int) $rows->internal;
        $vendor = (int) $rows->vendor;

        return ['internal' => $internal, 'vendor' => $vendor, 'total' => $internal + $vendor];
    }

    /** Records today's count, keeping the day's peak. */
    public static function snapshot(string $tenantId): array
    {
        $now = self::used($tenantId);
        DB::statement(
            'INSERT INTO seat_snapshots (tenant_id, day, internal, vendor, total, updated_at) VALUES (?, ?, ?, ?, ?, now())
             ON CONFLICT (tenant_id, day) DO UPDATE SET internal = GREATEST(seat_snapshots.internal, EXCLUDED.internal), vendor = GREATEST(seat_snapshots.vendor, EXCLUDED.vendor),
             total = GREATEST(seat_snapshots.total, EXCLUDED.total), updated_at = now()',
            [$tenantId, now()->toDateString(), $now['internal'], $now['vendor'], $now['total']],
        );

        return $now;
    }

    /** @return array<string, mixed> what a tenant sees: used now, the month's peak, what the subscription includes and what is above it */
    public function summary(string $tenantId): array
    {
        $used = self::snapshot($tenantId);
        $peak = (int) DB::table('seat_snapshots')->where('tenant_id', $tenantId)->where('day', '>=', now()->startOfMonth()->toDateString())->max('total');
        $included = $this->included($tenantId);

        return $used + [
            'peak_this_month' => max($peak, $used['total']),
            'included' => $included,
            'above_included' => $included === null ? null : max(0, max($peak, $used['total']) - $included),
        ];
    }

    /** The seats the subscription includes, or null when OptiNexus is not the identity provider or cannot be asked. */
    public function included(string $tenantId): ?int
    {
        $nexusTenant = DB::table('tenants')->where('id', $tenantId)->value('optinexus_tenant_id');
        if (! OptinexusSettings::active() || ! $nexusTenant) {
            return null;
        }

        return Cache::remember('optideli.seats.included.'.$tenantId, 300, function () use ($nexusTenant) {
            try {
                $api = app(NexusApi::class);
                $limit = $api->data($api->send('GET', '/tenants/'.$nexusTenant.'/commercial-context', repeatable: true, scope: NexusApi::SCOPE_COMMERCIAL))['resource_limits']['user_limit'] ?? null;
            } catch (IdentityProviderUnavailable) {
                return null;
            }

            return is_numeric($limit) && (int) $limit >= 0 ? (int) $limit : null;
        });
    }

    /**
     * Reports each finished month, once, to OptiNexus as usage of the `user_seat` meter (the month's peak). A month that failed is tried
     * again on the next run. @return array{sent: int, failed: int}
     */
    public function report(): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        if (! OptinexusSettings::active()) {
            return $result;
        }
        $thisMonth = now()->format('Y-m');
        foreach (DB::table('tenants')->whereNotNull('optinexus_tenant_id')->get(['id', 'optinexus_tenant_id']) as $tenant) {
            $periods = DB::table('seat_snapshots')->where('tenant_id', $tenant->id)->selectRaw("to_char(day, 'YYYY-MM') as period, max(total) as peak")->groupBy('period')->get()
                ->filter(fn ($p) => $p->period < $thisMonth);
            foreach ($periods as $p) {
                $done = DB::table('seat_reports')->where('tenant_id', $tenant->id)->where('period', $p->period)->first();
                if ($done && $done->status === 'SENT') {
                    continue;
                }
                $this->send($tenant, $p->period, (int) $p->peak, $result);
            }
        }

        return $result;
    }

    private function send(object $tenant, string $period, int $peak, array &$result): void
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();
        $error = null;
        try {
            $api = app(NexusApi::class);
            $response = $api->send('POST', '/usage-events', [
                'tenant_id' => $tenant->optinexus_tenant_id, 'application_code' => OptinexusSettings::applicationCode(), 'meter_key' => self::METER, 'quantity' => $peak,
                'usage_timestamp' => $start->endOfMonth()->subHours(12)->toIso8601String(), 'period_start' => $start->toIso8601String(), 'period_end' => $start->endOfMonth()->toIso8601String(),
                'idempotency_key' => 'optideli-seats-'.$period, 'external_reference' => 'optideli-seats-'.$period, 'metadata' => ['source' => 'optideli', 'basis' => 'monthly peak of people who can sign in'],
            ], scope: NexusApi::SCOPE_USAGE);
            if (! $response->successful()) {
                $error = 'OptiNexus menjawab '.$response->status();
            }
        } catch (IdentityProviderUnavailable $e) {
            $error = mb_substr($e->getMessage(), 0, 280);
        }
        $row = ['quantity' => $peak, 'status' => $error ? 'FAILED' : 'SENT', 'last_error' => $error, 'reported_at' => $error ? null : now(), 'updated_at' => now()];
        $existing = DB::table('seat_reports')->where('tenant_id', $tenant->id)->where('period', $period)->first();
        if ($existing) {
            DB::table('seat_reports')->where('tenant_id', $tenant->id)->where('period', $period)->update($row + ['attempts' => $existing->attempts + 1]);
        } else {
            DB::table('seat_reports')->insert($row + ['tenant_id' => $tenant->id, 'period' => $period, 'attempts' => 1, 'created_at' => now()]);
        }
        $result[$error ? 'failed' : 'sent']++;
    }
}

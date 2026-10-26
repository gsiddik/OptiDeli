<?php

namespace App\Domain\Customer;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Notifications to a customer's own systems. An event is written as a delivery row in the same transaction as the change it reports,
 * so nothing is announced that did not happen and nothing that happened is lost. A dispatcher then POSTs the delivery, signed with the
 * subscription secret, and retries with a growing delay until it is accepted or given up (DEAD, which can be retried by hand).
 *
 * Signature: X-OptiDeli-Signature: sha256=HMAC_SHA256(secret, "{timestamp}.{body}"), timestamp in X-OptiDeli-Timestamp.
 */
final class Webhooks
{
    public const EVENTS = ['spm.status_changed', 'invoice.issued'];

    /** Seconds to wait after the 1st, 2nd, ... failed attempt. After the last one the delivery is DEAD. */
    public const BACKOFF = [60, 300, 1800, 7200, 21600, 43200, 86400];

    /** Writes one delivery per active subscription of the customer that listens to the event. Call inside the originating transaction. */
    public static function publish(string $tenantId, ?string $customerId, string $event, string $subjectId, array $data): void
    {
        if (! $customerId) {
            return;
        }
        $subscriptions = DB::table('webhook_subscriptions')->where('tenant_id', $tenantId)->where('customer_id', $customerId)->where('active', true)->get();
        foreach ($subscriptions as $sub) {
            if (! in_array($event, json_decode($sub->events, true), true)) {
                continue;
            }
            $id = (string) Str::uuid();
            DB::table('webhook_deliveries')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'subscription_id' => $sub->id, 'event_type' => $event, 'subject_id' => $subjectId,
                'payload' => json_encode(['id' => $id, 'type' => $event, 'occurred_at' => now()->toIso8601String(), 'data' => $data], JSON_THROW_ON_ERROR),
                'status' => 'PENDING', 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @return array{id: string, secret: string} the secret is shown once */
    public function subscribe(string $tenantId, string $customerId, string $name, string $url, array $events, int $createdBy): array
    {
        self::assertUrl($url);
        $id = (string) Str::uuid();
        $secret = 'whsec_'.Str::random(40);
        DB::table('webhook_subscriptions')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'customer_id' => $customerId, 'name' => $name, 'url' => $url, 'secret' => Crypt::encryptString($secret),
            'events' => json_encode(array_values(array_unique($events))), 'active' => true, 'created_by' => $createdBy, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'secret' => $secret];
    }

    public function rotateSecret(string $tenantId, string $id): ?string
    {
        $secret = 'whsec_'.Str::random(40);

        return DB::table('webhook_subscriptions')->where('tenant_id', $tenantId)->where('id', $id)->update(['secret' => Crypt::encryptString($secret), 'updated_at' => now()]) === 1 ? $secret : null;
    }

    /** Only https to a public address, unless the deployment explicitly allows private targets (a closed network, tests). */
    public static function assertUrl(string $url): void
    {
        $parts = parse_url($url);
        $allowPrivate = (bool) config('optideli.webhooks.allow_private');
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if ($host === '' || ! in_array($scheme, $allowPrivate ? ['http', 'https'] : ['https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            abort(422, 'URL webhook harus https dan tanpa kredensial.');
        }
        if ($allowPrivate) {
            return;
        }
        $ips = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) ? [trim($host, '[]')] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            abort(422, 'Alamat webhook tidak dapat di-resolve.');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                abort(422, 'Alamat webhook tidak boleh menuju jaringan internal.');
            }
        }
    }

    /** Sends what is due. @return array{sent: int, failed: int, dead: int} */
    public function dispatchDue(int $limit = 50): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'dead' => 0];
        $ids = DB::table('webhook_deliveries')->where('status', 'PENDING')->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $outcome = DB::transaction(function () use ($id) {
                $d = DB::table('webhook_deliveries')->where('id', $id)->where('status', 'PENDING')->where('next_attempt_at', '<=', now())->lockForUpdate()->first();

                return $d ? $this->attempt($d) : null;
            });
            if ($outcome) {
                $result[$outcome]++;
            }
        }

        return $result;
    }

    /** Puts a dead delivery back in the queue. */
    public function retry(string $tenantId, string $id): bool
    {
        return DB::table('webhook_deliveries')->where('tenant_id', $tenantId)->where('id', $id)->where('status', 'DEAD')
            ->update(['status' => 'PENDING', 'attempts' => 0, 'next_attempt_at' => now(), 'updated_at' => now()]) === 1;
    }

    /** @return 'sent'|'failed'|'dead' */
    private function attempt(object $d): string
    {
        $sub = DB::table('webhook_subscriptions')->where('id', $d->subscription_id)->first();
        $code = null;
        $error = null;
        try {
            if (! $sub || ! $sub->active) {
                throw new \RuntimeException('Langganan nonaktif.');
            }
            self::assertUrl($sub->url);
            $timestamp = (string) now()->timestamp;
            $signature = hash_hmac('sha256', $timestamp.'.'.$d->payload, Crypt::decryptString($sub->secret));
            $response = Http::timeout(10)->withoutRedirecting()->withHeaders([
                'Content-Type' => 'application/json', 'User-Agent' => 'OptiDeli-Webhooks/1', 'X-OptiDeli-Event' => $d->event_type, 'X-OptiDeli-Delivery' => $d->id,
                'X-OptiDeli-Timestamp' => $timestamp, 'X-OptiDeli-Signature' => 'sha256='.$signature,
            ])->withBody($d->payload, 'application/json')->post($sub->url);
            $code = $response->status();
            if ($response->successful()) {
                DB::table('webhook_deliveries')->where('id', $d->id)->update(['status' => 'SENT', 'attempts' => $d->attempts + 1, 'last_status_code' => $code, 'last_error' => null, 'delivered_at' => now(), 'updated_at' => now()]);

                return 'sent';
            }
            $error = 'Balasan '.$code;
        } catch (\Throwable $e) {
            $error = Str::limit($e->getMessage(), 280, '');
        }
        $attempts = $d->attempts + 1;
        if ($attempts > count(self::BACKOFF)) {
            DB::table('webhook_deliveries')->where('id', $d->id)->update(['status' => 'DEAD', 'attempts' => $attempts, 'last_status_code' => $code, 'last_error' => $error, 'updated_at' => now()]);

            return 'dead';
        }
        DB::table('webhook_deliveries')->where('id', $d->id)->update(['attempts' => $attempts, 'last_status_code' => $code, 'last_error' => $error, 'next_attempt_at' => now()->addSeconds(self::BACKOFF[$attempts - 1]), 'updated_at' => now()]);

        return 'failed';
    }
}

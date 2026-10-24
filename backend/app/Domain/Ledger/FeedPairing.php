<?php

namespace App\Domain\Ledger;

use App\Domain\Integration\Optinexus\IdentityProviderUnavailable;
use App\Domain\Integration\Optinexus\NexusApi;
use App\Domain\Integration\Optinexus\OptinexusSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Feed pairing (owner decision 2026-10-24): while OptiNexus is in use, a valid credential is not enough. The tenant must still be linked
 * to the OptiNexus tenant the credential was issued for, and OptiNexus must say that the tenant is active, holds a live subscription
 * and is entitled to this application and to the consumer application. Only reading the feed is gated; recording documents never is.
 */
final class FeedPairing
{
    public function __construct(private readonly NexusApi $nexus) {}

    public function required(): bool
    {
        return match (config('optideli.optinexus.feed.pairing')) {
            'required' => true,
            'off' => false,
            default => OptinexusSettings::active(),
        };
    }

    /**
     * @return array{0: int, 1: string, 2: string}|null [HTTP status, error code, message] when the read must be refused, null when allowed
     */
    public function refusal(object $credential): ?array
    {
        if (! $this->required()) {
            return null;
        }
        if ($credential->nexus_tenant_id === null) {
            return [403, 'FEED_CREDENTIAL_UNPAIRED', 'Kredensial diterbitkan sebelum tenant ditautkan ke OptiNexus: terbitkan ulang.'];
        }
        if (DB::table('tenants')->where('id', $credential->tenant_id)->value('optinexus_tenant_id') !== $credential->nexus_tenant_id) {
            return [403, 'FEED_PAIRING_CHANGED', 'Tautan tenant ke OptiNexus berubah: terbitkan kredensial baru.'];
        }

        try {
            $context = Cache::remember('optideli.feed.pairing.'.$credential->nexus_tenant_id, (int) config('optideli.optinexus.feed.cache_seconds'), fn () => $this->commercialContext($credential->nexus_tenant_id));
        } catch (IdentityProviderUnavailable) {
            return [503, 'FEED_GOVERNANCE_UNAVAILABLE', 'OptiNexus tidak dapat dihubungi; simpan kursor dan coba lagi.'];
        }

        if ($context['tenant_status'] !== 'ACTIVE') {
            return [403, 'FEED_TENANT_NOT_ACTIVE', 'Tenant tidak aktif di OptiNexus.'];
        }
        if ($context['live_subscriptions'] < 1) {
            return [403, 'FEED_SUBSCRIPTION_INACTIVE', 'Tidak ada langganan aktif.'];
        }
        if (! in_array(OptinexusSettings::applicationCode(), $context['applications'], true)) {
            return [403, 'FEED_PRODUCER_NOT_ENTITLED', 'Tenant tidak berhak atas OptiDeli.'];
        }
        if (! in_array((string) config('optideli.optinexus.feed.consumer_application'), $context['applications'], true)) {
            return [403, 'FEED_CONSUMER_NOT_ENTITLED', 'Tenant tidak berhak atas aplikasi akuntansi.'];
        }

        return null;
    }

    /** @return array{tenant_status: ?string, applications: list<string>, live_subscriptions: int} */
    private function commercialContext(string $nexusTenantId): array
    {
        $response = $this->nexus->send('GET', '/tenants/'.$nexusTenantId.'/commercial-context', repeatable: true, scope: NexusApi::SCOPE_COMMERCIAL);
        $data = $this->nexus->data($response);
        if (($data['tenant_id'] ?? null) !== $nexusTenantId) {
            throw new IdentityProviderUnavailable('Commercial context is not for the requested tenant.', transient: false);
        }
        $live = (array) config('optideli.optinexus.feed.live_subscription_statuses');

        return [
            'tenant_status' => is_string($data['tenant_status'] ?? null) ? $data['tenant_status'] : null,
            'applications' => array_values(array_filter((array) ($data['applications_enabled'] ?? []), 'is_string')),
            'live_subscriptions' => count(array_filter((array) ($data['subscriptions'] ?? []), fn ($s) => is_array($s) && in_array($s['status'] ?? null, $live, true))),
        ];
    }
}

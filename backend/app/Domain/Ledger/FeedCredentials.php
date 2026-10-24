<?php

namespace App\Domain\Ledger;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Machine credentials for the Opti Integration Feed: one token reads one tenant's feed, nothing else. Only a SHA-256 hash is stored. */
final class FeedCredentials
{
    public const SCOPE = 'feed.read';

    /** @return array{id: string, token: string} the plaintext token is shown once and cannot be recovered */
    public function issue(string $tenantId, string $consumer, string $name, ?string $issuedBy, ?string $expiresAt = null): array
    {
        $token = 'odfd_'.Str::random(48);
        $id = (string) Str::uuid();
        DB::table('integration_feed_credentials')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'nexus_tenant_id' => DB::table('tenants')->where('id', $tenantId)->value('optinexus_tenant_id'),
            'consumer_application' => $consumer, 'name' => $name, 'token_prefix' => substr($token, 0, 9), 'token_hash' => hash('sha256', $token),
            'scope' => self::SCOPE, 'issued_by' => $issuedBy, 'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'token' => $token];
    }

    public function revoke(string $id, string $reason): bool
    {
        return DB::table('integration_feed_credentials')->where('id', $id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason, 'updated_at' => now()]) === 1;
    }

    public function resolve(string $token): ?object
    {
        if (! str_starts_with($token, 'odfd_')) {
            return null;
        }
        $row = DB::table('integration_feed_credentials')->where('token_hash', hash('sha256', $token))->first();
        if (! $row || $row->revoked_at !== null || ($row->expires_at !== null && now()->greaterThan($row->expires_at)) || $row->scope !== self::SCOPE) {
            return null;
        }

        return $row;
    }
}

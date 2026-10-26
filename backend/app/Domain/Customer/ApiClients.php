<?php

namespace App\Domain\Customer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tokens a customer's own systems use to read its shipments and invoices. A token belongs to one customer of one tenant, carries
 * only the scopes it was given, expires, can be rotated (the old token stops at once) or revoked, and records when it was last used.
 * Only a SHA-256 hash is stored; the plaintext is shown once.
 */
final class ApiClients
{
    public const SCOPES = ['spm.read', 'invoice.read'];

    /** @return array{id: string, token: string} */
    public function issue(string $tenantId, string $customerId, string $name, array $scopes, int $createdBy, ?string $expiresAt): array
    {
        $id = (string) Str::uuid();
        $token = $this->newToken();
        DB::table('api_clients')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'customer_id' => $customerId, 'name' => $name, 'token_prefix' => substr($token, 0, 10), 'token_hash' => hash('sha256', $token),
            'scopes' => json_encode(array_values(array_unique($scopes))), 'expires_at' => $expiresAt, 'created_by' => $createdBy, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'token' => $token];
    }

    /** A new token replaces the old one at once. */
    public function rotate(string $tenantId, string $id): ?string
    {
        $token = $this->newToken();
        $changed = DB::table('api_clients')->where('tenant_id', $tenantId)->where('id', $id)->whereNull('revoked_at')
            ->update(['token_prefix' => substr($token, 0, 10), 'token_hash' => hash('sha256', $token), 'rotated_at' => now(), 'updated_at' => now()]);

        return $changed === 1 ? $token : null;
    }

    public function revoke(string $tenantId, string $id): bool
    {
        return DB::table('api_clients')->where('tenant_id', $tenantId)->where('id', $id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]) === 1;
    }

    public function resolve(string $token): ?object
    {
        if (! str_starts_with($token, 'odcl_')) {
            return null;
        }
        $row = DB::table('api_clients')->where('token_hash', hash('sha256', $token))->first();
        if (! $row || $row->revoked_at !== null || ($row->expires_at !== null && now()->greaterThan($row->expires_at))) {
            return null;
        }
        if (! DB::table('customers')->where('tenant_id', $row->tenant_id)->where('id', $row->customer_id)->where('active', true)->exists()) {
            return null;
        }
        DB::table('api_clients')->where('id', $row->id)->update(['last_used_at' => now()]);
        $row->scopes = json_decode($row->scopes, true);

        return $row;
    }

    private function newToken(): string
    {
        return 'odcl_'.Str::random(48);
    }
}

<?php

namespace App\Domain\Tracking;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Machine credentials for the systems that send positions and geofence events (a GPS provider, OptiRadar): one token writes the
 * stop times of one tenant and nothing else. What it records is attributed to the user it acts as. Only a SHA-256 hash is stored.
 */
final class TrackingCredentials
{
    /** @return array{id: string, token: string} the plaintext token is shown once and cannot be recovered */
    public function issue(string $tenantId, string $name, int $actsAs, ?string $expiresAt = null): array
    {
        $token = 'odtk_'.Str::random(48);
        $id = (string) Str::uuid();
        DB::table('tracking_credentials')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'name' => $name, 'token_prefix' => substr($token, 0, 9), 'token_hash' => hash('sha256', $token),
            'acts_as' => $actsAs, 'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'token' => $token];
    }

    public function revoke(string $id): bool
    {
        return DB::table('tracking_credentials')->where('id', $id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]) === 1;
    }

    public function resolve(string $token): ?object
    {
        if (! str_starts_with($token, 'odtk_')) {
            return null;
        }
        $row = DB::table('tracking_credentials')->where('token_hash', hash('sha256', $token))->first();
        if (! $row || $row->revoked_at !== null || ($row->expires_at !== null && now()->greaterThan($row->expires_at))) {
            return null;
        }
        DB::table('tracking_credentials')->where('id', $row->id)->update(['last_used_at' => now()]);

        return $row;
    }
}

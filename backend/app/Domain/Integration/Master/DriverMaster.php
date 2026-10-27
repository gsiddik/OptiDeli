<?php

namespace App\Domain\Integration\Master;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Takes the drivers OptiRadar knows (docs/integration/MASTER_DATA_SYNC.md, stage 4). OptiDeli owns the driver (person, licence,
 * eligibility); OptiRadar owns only the identification tag. A driver OptiDeli does not know becomes a draft that cannot be allocated
 * until its licence validity and eligibility are completed here; a known one only receives the tag. Nothing is published back.
 */
final class DriverMaster
{
    public const RECEIVED = 'optiradar.driver.upserted';

    /** @return 'applied'|'created'|'stale'|'ignored' */
    public function apply(array $event): string
    {
        $payload = $event['data']['payload'] ?? null;
        if (($event['event_type'] ?? null) !== self::RECEIVED || ($event['source_system'] ?? null) !== 'optiradar' || ! is_array($payload)) {
            return 'ignored';
        }
        $license = trim((string) ($payload['license_number'] ?? ''));
        $key = self::key($license);
        $version = $payload['version'] ?? null;
        $telematics = $payload['telematics'] ?? null;
        $tenant = DB::table('tenants')->where('optinexus_tenant_id', (string) ($event['tenant_id'] ?? ''))->first();
        if ($key === '' || ! is_int($version) || ! is_array($telematics) || ! $tenant) {
            return 'ignored';
        }
        $name = $this->text($payload['name'] ?? null, 150);
        $tag = $this->text($telematics['tag_id'] ?? null, 120);

        return DB::transaction(function () use ($tenant, $key, $license, $version, $name, $tag) {
            DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->firstOrFail();
            $versionKey = 'D:'.$key;
            $seen = DB::table('master_versions')->where(['tenant_id' => $tenant->id, 'registration_key' => $versionKey, 'source' => 'OPTIRADAR'])->value('version');
            if ($seen !== null && $seen >= $version) {
                return 'stale';
            }
            DB::table('master_versions')->upsert([['tenant_id' => $tenant->id, 'registration_key' => $versionKey, 'source' => 'OPTIRADAR', 'version' => $version]], ['tenant_id', 'registration_key', 'source'], ['version']);

            $existing = DB::table('drivers')->where('tenant_id', $tenant->id)
                ->whereRaw("regexp_replace(upper(license_number), '[^A-Z0-9]', '', 'g') = ?", [$key])->first();
            if ($existing) {
                if ($existing->vendor_id !== null) {
                    return 'ignored'; // a vendor's driver is not the tenant's person
                }
                DB::table('drivers')->where('id', $existing->id)->update(array_filter(['radar_tag' => $tag, 'updated_at' => now()], fn ($v) => $v !== null));

                return 'applied';
            }
            if ($name === null) {
                return 'ignored';
            }

            DB::table('drivers')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => $name, 'license_number' => $license, 'active' => true,
                'source' => 'OPTIRADAR', 'profile_complete' => false, 'radar_tag' => $tag, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return 'created';
        });
    }

    /** Licence numbers are compared without case, spaces or dashes. */
    public static function key(string $licenseNumber): string
    {
        return preg_replace('/[^A-Z0-9]/', '', Str::upper($licenseNumber)) ?? '';
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), $max, '') : null;
    }
}

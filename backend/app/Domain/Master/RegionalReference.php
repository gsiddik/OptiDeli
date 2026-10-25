<?php

namespace App\Domain\Master;

use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loads the official regional reference (Kemendagri codes: 38 provinces and 514 regencies/cities, see database/reference/README.md)
 * into a tenant's regions and cities. Additive and repeatable: a code that already exists is left exactly as it is, so a
 * tenant's renames and deactivations survive, and a newer file only adds what is new.
 */
final class RegionalReference
{
    public const FILE = 'database/reference/wilayah_kemendagri.csv';

    /** @return list<array{0: string, 1: string}> code, name */
    public static function rows(): array
    {
        $rows = [];
        $handle = fopen(base_path(self::FILE), 'rb');
        fgetcsv($handle, null, ',', '"', '');
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if (isset($row[1]) && $row[0] !== '') {
                $rows[] = [$row[0], $row[1]];
            }
        }
        fclose($handle);

        return $rows;
    }

    /** @return array{regions_created: int, regions_existing: int, cities_created: int, cities_existing: int} */
    public static function load(TenantStore $store): array
    {
        return DB::transaction(function () use ($store) {
            DB::table('tenants')->where('id', $store->tenantId)->lockForUpdate()->firstOrFail();
            $now = now();
            $result = ['regions_created' => 0, 'regions_existing' => 0, 'cities_created' => 0, 'cities_existing' => 0];
            $rows = self::rows();

            $regionIds = [];
            foreach (array_filter($rows, fn ($r) => ! str_contains($r[0], '.')) as [$code, $name]) {
                $existing = $store->query('regions')->where('code', $code)->value('id');
                if ($existing) {
                    $regionIds[$code] = $existing;
                    $result['regions_existing']++;

                    continue;
                }
                $id = (string) Str::uuid();
                DB::table('regions')->insert(['id' => $id, 'tenant_id' => $store->tenantId, 'code' => $code, 'name' => $name, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
                $regionIds[$code] = $id;
                $result['regions_created']++;
            }
            foreach (array_filter($rows, fn ($r) => str_contains($r[0], '.')) as [$code, $name]) {
                if ($store->query('cities')->where('code', $code)->exists()) {
                    $result['cities_existing']++;

                    continue;
                }
                $region = $regionIds[explode('.', $code)[0]] ?? null;
                if ($region === null) {
                    continue;
                }
                DB::table('cities')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $store->tenantId, 'code' => $code, 'name' => $name, 'active' => true, 'region_id' => $region, 'created_at' => $now, 'updated_at' => $now]);
                $result['cities_created']++;
            }
            $store->event('master.regional_reference.loaded', $store->tenantId, $result);

            return $result;
        }, 3);
    }
}

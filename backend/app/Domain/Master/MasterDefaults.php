<?php

namespace App\Domain\Master;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Starter master data every tenant begins with, so the first order and the first tariff do not start from empty lists:
 * common units, cargo categories, the three service levels and the charge codes of a transport operation. Codes already
 * present are left exactly as they are (a tenant may have renamed or deactivated them), so this is safe to call again.
 */
final class MasterDefaults
{
    private const UNITS = ['PCS' => 'Pcs', 'KG' => 'Kilogram', 'TON' => 'Ton', 'PALLET' => 'Pallet', 'CBM' => 'Meter kubik', 'BOX' => 'Karton', 'DRUM' => 'Drum'];

    private const CATEGORIES = ['GENERAL' => 'Barang umum', 'FMCG' => 'FMCG', 'BULK' => 'Curah', 'HAZMAT' => 'Bahan berbahaya (B3)', 'COLD' => 'Rantai dingin'];

    private const SERVICES = ['NORMAL' => ['Normal', 72], 'EXPRESS' => ['Express', 24], 'SAMEDAY' => ['Same day', 12]];

    /** code => [name, side, nature, fact key] */
    private const CHARGES = [
        'FREIGHT' => ['Ongkos angkut', 'BOTH', 'FREIGHT', 'FREIGHT'],
        'LOADING' => ['Uang muat', 'BOTH', 'ACCESSORIAL', 'LOADING'],
        'UNLOADING' => ['Uang bongkar', 'BOTH', 'ACCESSORIAL', 'UNLOADING'],
        'OVERNIGHT' => ['Menginap (overnight)', 'BOTH', 'ACCESSORIAL', 'OVERNIGHT'],
        'DETENTION' => ['Waktu tunggu (detention)', 'BOTH', 'ACCESSORIAL', 'DETENTION'],
        'MARKET_ENTRY' => ['Masuk pasar', 'BUY', 'ACCESSORIAL', 'MARKET_ENTRY'],
        'TOLL' => ['Tol', 'BUY', 'ALLOWANCE', 'TOLL'],
        'PARKING' => ['Parkir', 'BUY', 'ALLOWANCE', 'PARKING'],
        'DRIVER_ADVANCE' => ['Uang jalan driver', 'BUY', 'ALLOWANCE', 'DRIVER_ADVANCE'],
    ];

    /** code => [name, grouping, split Plan/Unplan, columns] */
    private const TEMPLATES = [
        'STD-SPM' => ['Satu invoice per SPM', 'PER_SPM', false, 'spm,route,description,amount'],
        'SPM-PLAN-UNPLAN' => ['Per SPM, Plan dan Unplan terpisah', 'PER_SPM', true, 'spm,route,description,amount'],
        'PROYEK' => ['Satu invoice per proyek', 'PER_PROJECT', false, 'date,spm,route,description,amount'],
        'PERIODE' => ['Satu invoice per periode', 'PER_PERIOD', false, 'date,spm,route,description,amount'],
    ];

    public static function seed(string $tenantId): void
    {
        $now = now();
        $row = fn (string $code, string $name, array $extra = []) => ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'active' => true, 'created_at' => $now, 'updated_at' => $now] + $extra;

        DB::table('units')->insertOrIgnore(array_map(fn ($code, $name) => $row($code, $name), array_keys(self::UNITS), self::UNITS));
        DB::table('category_types')->insertOrIgnore(array_map(fn ($code, $name) => $row($code, $name), array_keys(self::CATEGORIES), self::CATEGORIES));
        DB::table('services')->insertOrIgnore(array_map(fn ($code, $d) => $row($code, $d[0], ['sla_hours' => $d[1]]), array_keys(self::SERVICES), self::SERVICES));
        if (Schema::hasTable('invoice_templates')) {
            DB::table('invoice_templates')->insertOrIgnore(array_map(fn ($code, $d) => $row($code, $d[0], ['grouping' => $d[1], 'split_plan_unplan' => $d[2], 'columns' => $d[3]]), array_keys(self::TEMPLATES), self::TEMPLATES));
        }
        DB::table('charge_codes')->insertOrIgnore(array_map(fn ($code, $d) => $row($code, $d[0], ['side' => $d[1], 'nature' => $d[2], 'fact_key' => $d[3]]), array_keys(self::CHARGES), self::CHARGES));
    }
}

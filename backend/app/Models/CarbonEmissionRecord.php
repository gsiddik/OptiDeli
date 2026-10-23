<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CarbonEmissionRecord extends Model
{
    use HasUuids;

    protected $table = 'carbon_emission_records';

    protected $fillable = [
        'tenant_id',
        'trip_id',
        'vehicle_id',
        'fleet_ownership',
        'fuel_type',
        'distance_km',
        'cargo_weight_ton',
        'ton_km',
        'emission_factor_g_per_ton_km',
        'total_co2e_kg',
        'scope',
        'recorded_at',
    ];

    protected $casts = [
        'distance_km' => 'float',
        'cargo_weight_ton' => 'float',
        'ton_km' => 'float',
        'emission_factor_g_per_ton_km' => 'float',
        'total_co2e_kg' => 'float',
        'recorded_at' => 'datetime',
    ];
}

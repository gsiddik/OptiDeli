<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TelemetryLog extends Model
{
    use HasUuids;

    protected $table = 'telemetry_logs';

    protected $fillable = [
        'tenant_id',
        'trip_id',
        'vehicle_id',
        'latitude',
        'longitude',
        'speed_kmh',
        'fuel_level_pct',
        'reefer_temp_celsius',
        'is_door_open',
        'recorded_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'speed_kmh' => 'float',
        'fuel_level_pct' => 'float',
        'reefer_temp_celsius' => 'float',
        'is_door_open' => 'boolean',
        'recorded_at' => 'datetime',
    ];
}

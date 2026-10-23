<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TelemetryAlert extends Model
{
    use HasUuids;

    protected $table = 'telemetry_alerts';

    protected $fillable = [
        'tenant_id',
        'trip_id',
        'vehicle_id',
        'alert_type',
        'severity',
        'message',
        'metric_value',
        'threshold_value',
        'is_resolved',
        'triggered_at',
    ];

    protected $casts = [
        'metric_value' => 'float',
        'threshold_value' => 'float',
        'is_resolved' => 'boolean',
        'triggered_at' => 'datetime',
    ];
}

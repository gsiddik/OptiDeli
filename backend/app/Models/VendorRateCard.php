<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VendorRateCard extends Model
{
    use HasUuids;

    protected $table = 'vendor_rate_cards';

    protected $fillable = [
        'tenant_id',
        'vendor_id',
        'vehicle_type',
        'zone_origin',
        'zone_destination',
        'base_rate_minor_units',
        'rate_per_km_minor_units',
        'min_distance_km',
        'is_active',
        'effective_from',
        'effective_to',
    ];

    protected $casts = [
        'base_rate_minor_units' => 'integer',
        'rate_per_km_minor_units' => 'integer',
        'min_distance_km' => 'integer',
        'is_active' => 'boolean',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];
}

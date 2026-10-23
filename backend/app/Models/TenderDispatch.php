<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenderDispatch extends Model
{
    use HasUuids;

    protected $table = 'tender_dispatches';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'tendering_mode',
        'status',
        'current_sequence',
        'timeout_minutes_per_vendor',
        'accepted_vendor_id',
        'winning_cost_minor_units',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'current_sequence' => 'integer',
        'timeout_minutes_per_vendor' => 'integer',
        'winning_cost_minor_units' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function offers(): HasMany
    {
        return $this->hasMany(TenderOffer::class, 'tender_dispatch_id')->orderBy('sequence');
    }
}

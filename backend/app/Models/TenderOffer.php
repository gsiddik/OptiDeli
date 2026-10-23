<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderOffer extends Model
{
    use HasUuids;

    protected $table = 'tender_offers';

    protected $fillable = [
        'tenant_id',
        'tender_dispatch_id',
        'vendor_id',
        'sequence',
        'offered_cost_minor_units',
        'status',
        'offered_at',
        'expires_at',
        'responded_at',
        'rejection_reason',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'offered_cost_minor_units' => 'integer',
        'offered_at' => 'datetime',
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(TenderDispatch::class, 'tender_dispatch_id');
    }
}

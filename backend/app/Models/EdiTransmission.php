<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EdiTransmission extends Model
{
    use HasUuids;

    protected $table = 'edi_transmissions';

    protected $fillable = [
        'tenant_id',
        'transaction_set',
        'direction',
        'reference_id',
        'raw_content',
        'parsed_payload',
        'status',
        'error_message',
        'transmitted_at',
    ];

    protected $casts = [
        'parsed_payload' => 'array',
        'transmitted_at' => 'datetime',
    ];
}

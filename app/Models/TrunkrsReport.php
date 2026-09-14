<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TrunkrsReport extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['shipments', 'message_hash', 'content_hash'];

    protected function casts(): array
    {
        return [
            'shipments' => 'encrypted:array',
            'received_at' => 'immutable_datetime',
            'report_date' => 'immutable_date',
            'shipment_count' => 'integer',
        ];
    }
}

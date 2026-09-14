<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrunkrsConnection extends Model
{
    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['refresh_token', 'configuration_hash'];

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'last_started_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
            'retry_at' => 'immutable_datetime',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrunkrsSetting extends Model
{
    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['payload', 'pending'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'pending' => 'encrypted:array', 'scheduler_seen_at' => 'immutable_datetime'];
    }
}

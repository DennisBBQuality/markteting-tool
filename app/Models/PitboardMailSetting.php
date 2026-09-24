<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PitboardMailSetting extends Model
{
    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['payload', 'refresh_token', 'pending'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'refresh_token' => 'encrypted', 'pending' => 'encrypted:array'];
    }
}

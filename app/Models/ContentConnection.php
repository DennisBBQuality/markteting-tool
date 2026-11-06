<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentConnection extends Model
{
    protected $guarded = [];

    protected $hidden = ['client_secret', 'access_token', 'refresh_token', 'accounts'];

    protected function casts(): array
    {
        return ['client_secret' => 'encrypted', 'access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'accounts' => 'encrypted:array', 'enabled' => 'boolean', 'verified_at' => 'datetime', 'expires_at' => 'datetime', 'scheduler_seen_at' => 'datetime'];
    }
}

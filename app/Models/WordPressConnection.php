<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WordPressConnection extends Model
{
    use HasUuids;

    protected $table = 'wordpress_connections';

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'enabled' => 'boolean', 'tested_at' => 'datetime'];
    }
}

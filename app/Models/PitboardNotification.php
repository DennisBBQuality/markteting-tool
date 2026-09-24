<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PitboardNotification extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'deadline' => 'date', 'email_attempted_at' => 'datetime', 'email_accepted_at' => 'datetime'];
    }
}

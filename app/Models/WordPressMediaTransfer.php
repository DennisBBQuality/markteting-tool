<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WordPressMediaTransfer extends Model
{
    protected $table = 'wordpress_media_transfers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'claimed_at' => 'datetime'];
    }
}

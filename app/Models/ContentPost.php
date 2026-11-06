<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ContentPost extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function deliveries()
    {
        return $this->hasMany(ContentDelivery::class, 'post_id');
    }

    public function activities()
    {
        return $this->hasMany(ContentActivity::class, 'post_id')->orderBy('created_at');
    }
}

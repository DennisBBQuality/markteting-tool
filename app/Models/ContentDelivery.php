<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ContentDelivery extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['upload_session_url'];

    protected function casts(): array
    {
        return ['upload_session_url' => 'encrypted', 'account_snapshot' => 'array', 'media_ids' => 'array', 'options' => 'array', 'scheduled_at' => 'immutable_datetime', 'submitted_at' => 'datetime', 'checked_at' => 'datetime'];
    }

    public function post()
    {
        return $this->belongsTo(ContentPost::class, 'post_id');
    }
}

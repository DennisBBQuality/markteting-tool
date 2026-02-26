<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id', 'task_id', 'calendar_item_id', 'note_id',
        'bestandsnaam', 'originele_naam', 'mimetype', 'grootte', 'geupload_door',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'geupload_door');
    }
}

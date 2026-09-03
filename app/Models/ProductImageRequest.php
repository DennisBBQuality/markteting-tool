<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductImageRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'status',
        'progress',
        'progress_step',
        'source_path',
        'source_references',
        'prompt',
        'generation_context',
        'results',
        'error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'results' => 'array',
            'source_references' => 'array',
            'generation_context' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}

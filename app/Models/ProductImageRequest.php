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
        'source_path',
        'prompt',
        'results',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'results' => 'array',
        ];
    }
}

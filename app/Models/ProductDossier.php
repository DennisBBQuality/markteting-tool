<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductDossier extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'status',
        'product_type',
        'product_name',
        'data',
        'label_images',
        'label_analysis',
        'analysis_status',
        'analysis_error',
        'wordpress_status',
        'generation',
        'expert_assets',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'label_images' => 'array',
            'label_analysis' => 'array',
            'generation' => 'array',
            'expert_assets' => 'array',
        ];
    }
}

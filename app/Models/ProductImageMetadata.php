<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImageMetadata extends Model
{
    protected $table = 'product_image_metadata';

    protected $guarded = ['id'];

    protected $attributes = ['revision' => 0, 'source' => 'none', 'status' => 'idle'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'image_version' => 'integer', 'revision' => 'integer'];
    }
}

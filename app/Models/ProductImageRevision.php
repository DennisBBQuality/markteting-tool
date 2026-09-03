<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImageRevision extends Model
{
    protected $fillable = [
        'product_image_asset_id',
        'version',
        'instruction',
        'mime_type',
        'contents_base64',
    ];
}

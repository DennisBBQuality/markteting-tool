<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImageAsset extends Model
{
    protected $fillable = [
        'product_image_request_id',
        'filename',
        'mime_type',
        'contents_base64',
    ];
}

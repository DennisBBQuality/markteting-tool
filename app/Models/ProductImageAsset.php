<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImageAsset extends Model
{
    protected $fillable = [
        'product_image_request_id',
        'filename',
        'style_id',
        'version',
        'refinement_status',
        'refinement_error',
        'last_instruction',
        'mime_type',
        'contents_base64',
    ];

    public function revisions()
    {
        return $this->hasMany(ProductImageRevision::class);
    }
}

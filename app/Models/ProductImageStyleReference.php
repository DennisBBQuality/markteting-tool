<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductImageStyleReference extends Model
{
    use HasUuids;

    protected $fillable = [
        'product_name',
        'product_key',
        'product_type',
        'status',
        'style_id',
        'source_asset_id',
        'source_version',
        'created_by',
        'mime_type',
        'contents_base64',
    ];

    public static function productKey(string $productName): string
    {
        return Str::limit(Str::slug(trim($productName)), 160, '');
    }

    public function sourceAsset()
    {
        return $this->belongsTo(ProductImageAsset::class, 'source_asset_id');
    }
}

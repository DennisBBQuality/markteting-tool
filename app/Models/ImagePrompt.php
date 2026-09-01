<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImagePrompt extends Model
{
    use HasFactory, HasUuids;

    public const PRODUCT_PHOTO = 'productfoto';

    public const DEFAULT_PRODUCT_PHOTO_PROMPT = <<<'PROMPT'
Create a premium, photorealistic e-commerce product photograph based on the supplied reference photo. Identify and preserve the exact type and cut of meat, its shape, proportions, texture, marbling and characteristic details. Remove the plastic packaging, tray, absorbent pad, labels, stickers and price tags completely. Show one clean product as the clear subject, centered in a professional studio composition with soft natural lighting, realistic shadows and a neutral background. Do not add text, logos, hands, people, packaging or unrelated products. Keep the result anatomically plausible, appetising and suitable for a high-end online butcher shop.
PROMPT;

    protected $fillable = [
        'naam',
        'prompt',
        'bijgewerkt_door',
    ];

    public static function productPhoto(): self
    {
        return self::firstOrCreate(
            ['naam' => self::PRODUCT_PHOTO],
            ['prompt' => self::DEFAULT_PRODUCT_PHOTO_PROMPT],
        );
    }
}

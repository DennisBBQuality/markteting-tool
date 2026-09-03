<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImagePrompt extends Model
{
    use HasFactory, HasUuids;

    public const PRODUCT_PHOTO = 'productfoto';

    private const LEGACY_PRODUCT_PHOTO_PROMPT = 'Create a premium, photorealistic e-commerce product photograph based on the supplied reference photo. Identify and preserve the exact type and cut of meat, its shape, proportions, texture, marbling and characteristic details. Remove the plastic packaging, tray, absorbent pad, labels, stickers and price tags completely. Show one clean product as the clear subject, centered in a professional studio composition with soft natural lighting, realistic shadows and a neutral background. Do not add text, logos, hands, people, packaging or unrelated products. Keep the result anatomically plausible, appetising and suitable for a high-end online butcher shop.';

    public const DEFAULT_PRODUCT_PHOTO_PROMPT = <<<'PROMPT'
Maak een hoogwaardige, fotorealistische productfoto voor de webshop van BBQuality. Gebruik alle aangeleverde foto's uitsluitend als feitelijke referentie voor het product. Behoud het exacte producttype, de vorm, verhoudingen, structuur, kleur, vetverdeling, marmering en herkenbare kenmerken. Het resultaat moet natuurlijk, geloofwaardig, smakelijk en professioneel gefotografeerd zijn, met realistisch licht, echte schaduwen en voldoende detail. Verzin nooit producten, aantallen, verpakkingen, logo's, etiketteksten of ingrediënten. Volg de productspecifieke opdracht en stijl die automatisch aan deze basisinstructie worden toegevoegd.
PROMPT;

    protected $fillable = [
        'naam',
        'prompt',
        'bijgewerkt_door',
    ];

    public static function productPhoto(): self
    {
        $prompt = self::firstOrCreate(
            ['naam' => self::PRODUCT_PHOTO],
            ['prompt' => self::DEFAULT_PRODUCT_PHOTO_PROMPT],
        );

        if ($prompt->prompt === self::LEGACY_PRODUCT_PHOTO_PROMPT) {
            $prompt->update(['prompt' => self::DEFAULT_PRODUCT_PHOTO_PROMPT]);
        }

        return $prompt;
    }
}

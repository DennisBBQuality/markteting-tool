<?php

namespace App\Services;

use App\Models\ProductImageAsset;
use RuntimeException;

class ProductImageDelivery
{
    public const WEBP_QUALITY = 85;

    // Include the export policy in the cache key; never reuse older lossless exports.
    public const WEBP_PROFILE = 'web-v2-q85';

    public function metadata(array $context, array $result, int $version, ?ProductImageAsset $asset = null): array
    {
        $name = trim((string) ($context['product_name'] ?? 'Product')) ?: 'Product';
        $state = match ($result['status'] ?? '') {
            'rauw' => 'rauw', 'bereid' => 'bereid', default => 'productfoto',
        };
        $fallback = [
            // A photo-specific, reserved name must come from SEO, not a numbered fallback.
            'filename' => '',
            'title' => $name.' – '.$state,
            'alt' => $name.($state === 'productfoto' ? '' : ', '.$state),
            'caption' => $name.' | BBQuality',
            'description' => 'Productfoto van '.$name.($state === 'productfoto' ? '' : ' ('.$state.')').'.',
            'mime_type' => 'image/webp',
            'review_note' => 'Controleer de zichtbare inhoud. Zet alt-tekst en bijschrift in de mediavelden van de website; alleen bestandsmetadata is niet voldoende.',
        ];
        $stored = $asset ? app(ProductImageSeo::class)->record($asset) : null;
        $fallback = ProductImagePreparationSeo::complete($fallback, $context, $result);

        return [...$fallback, ...($stored?->fields ?? []),
            'review_note' => $stored?->fields
                ? 'Controleer de zichtbare inhoud vóór publicatie. Bijgerechten zijn serveersuggesties. Vul deze teksten ook in de mediavelden van de website in.'
                : 'Nog geen beeldspecifieke SEO. Dit zijn basisvelden; maak SEO of vul de velden handmatig in.',
        ];
    }

    /** Web delivery: compress a copy, keeping dimensions and transparency. */
    public function webp(string $contents): string
    {
        $image = @imagecreatefromstring($contents);
        if (! $image) {
            throw new RuntimeException('De afbeelding kan niet naar WebP worden omgezet. Het origineel is bewaard.');
        }
        try {
            imagepalettetotruecolor($image);
            imagesavealpha($image, true);
            ob_start();
            $ok = imagewebp($image, null, self::WEBP_QUALITY);
            $webp = ob_get_clean();
            if (! $ok || ! is_string($webp) || substr($webp, 0, 4) !== 'RIFF' || substr($webp, 8, 4) !== 'WEBP') {
                throw new RuntimeException('De WebP-export is niet gelukt. Het origineel is bewaard.');
            }

            return $webp;
        } finally {
            imagedestroy($image);
        }
    }
}

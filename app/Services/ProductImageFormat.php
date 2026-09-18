<?php

namespace App\Services;

class ProductImageFormat
{
    public const WIDTH = 1536;

    public const HEIGHT = 1152;

    public const SIZE = '1536x1152';

    public const INSTRUCTION = 'VERPLICHT WEBSITEFORMAAT: lever één liggende 4:3-afbeelding van 1536 × 1152 pixels, geen vierkant. Componeer direct voor dit volledige liggende beeld. Het hele hoofdproduct, inclusief flesdop of productuiteinden, blijft zichtbaar met ademruimte boven, onder en aan weerszijden. Niet achteraf croppen, uitrekken of opvullen met randen. Deze formaatregel gaat voor op een oudere vierkante instructie of vierkante referentiefoto.';

    public static function validate(string $contents): string
    {
        $size = @getimagesizefromstring($contents);
        if (! $size || $size[0] !== self::WIDTH || $size[1] !== self::HEIGHT) {
            throw new ProductImageGenerationException('De beeldservice leverde niet het vereiste liggende websiteformaat (1536 × 1152). Er is niet automatisch bijgesneden of opnieuw gegenereerd.');
        }

        return $contents;
    }

    public static function placeholder(): string
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefill($image, 0, 0, imagecolorallocate($image, 245, 243, 239));
        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        return $contents;
    }
}

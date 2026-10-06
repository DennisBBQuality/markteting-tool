<?php

namespace Tests\Unit;

use App\Services\ProductImageDelivery;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProductImageDeliveryTest extends TestCase
{
    public function test_web_export_compresses_detailed_pixels_at_quality_85_without_resizing(): void
    {
        $image = imagecreatetruecolor(320, 240);
        $noise = 42;
        for ($y = 0; $y < 240; $y++) {
            for ($x = 0; $x < 320; $x++) {
                $noise = ($noise * 1103515245 + 12345) & 0x7FFFFFFF;
                $detail = ($noise >> 8) % 24;
                imagesetpixel($image, $x, $y, imagecolorallocate($image, 70 + $detail, 40 + $detail, 20 + $detail));
            }
        }
        imagestring($image, 5, 15, 30, 'TEST ETIKET', imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        ob_start();
        imagewebp($image, null, IMG_WEBP_LOSSLESS);
        $lossless = ob_get_clean();
        ob_start();
        imagewebp($image, null, 85);
        $expected = ob_get_clean();
        imagedestroy($image);

        $webp = (new ProductImageDelivery)->webp($png);
        $this->assertSame($expected, $webp);
        $this->assertLessThan(strlen($lossless), strlen($webp));
        $this->assertSame([320, 240, IMAGETYPE_WEBP], array_slice(getimagesizefromstring($webp), 0, 3));
        $this->assertSame('RIFF', substr($webp, 0, 4));
        $this->assertSame('WEBP', substr($webp, 8, 4));
    }

    public function test_transparency_is_retained(): void
    {
        $image = imagecreatetruecolor(40, 40);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 10, 10, 30, 30, imagecolorallocatealpha($image, 200, 80, 20, 0));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $export = imagecreatefromstring((new ProductImageDelivery)->webp($png));
        $this->assertSame(127, imagecolorsforindex($export, imagecolorat($export, 0, 0))['alpha']);
        $this->assertSame(0, imagecolorsforindex($export, imagecolorat($export, 20, 20))['alpha']);
        imagedestroy($export);
    }

    #[WithoutErrorHandler]
    public function test_invalid_source_is_rejected_instead_of_returning_a_broken_download(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Het origineel is bewaard');
        (new ProductImageDelivery)->webp('not an image');
    }
}

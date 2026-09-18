<?php

namespace Tests\Unit;

use App\Services\FakeProductImageGenerator;
use App\Services\ProductImageDelivery;
use App\Services\ProductImageFormat;
use App\Services\ProductImageGenerationException;
use App\Services\ProductImageSeo;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductImageFormatTest extends TestCase
{
    public function test_all_product_types_and_webp_keep_native_landscape_pixels(): void
    {
        foreach (['meat', 'fish', 'sauce', 'bundle'] as $type) {
            $results = (new FakeProductImageGenerator)->generateForProduct([], '', ['product_type' => $type, 'product_name' => 'Testproduct']);
            foreach ($results as $result) {
                $png = ProductImageFormat::validate($result['contents']);
                $webp = (new ProductImageDelivery)->webp($png);
                $this->assertSame([1536, 1152], array_slice(getimagesizefromstring($webp), 0, 2));
                $original = imagecreatefromstring($png);
                $export = imagecreatefromstring($webp);
                $this->assertSame(imagecolorat($original, 100, 100), imagecolorat($export, 100, 100));
                imagedestroy($original);
                imagedestroy($export);
            }
        }
    }

    public function test_wrong_format_is_rejected_instead_of_cropped_or_stretched(): void
    {
        $this->expectException(ProductImageGenerationException::class);
        $this->expectExceptionMessage('niet automatisch bijgesneden');
        $square = UploadedFile::fake()->image('square.png', 64, 64);
        ProductImageFormat::validate(file_get_contents($square->getRealPath()));
    }

    public function test_digits_are_rejected_even_beyond_the_slug_length_limit(): void
    {
        foreach (['product-op-tafel-2.webp', 'product-².webp', str_repeat('product-', 30).'2.webp'] as $name) {
            try {
                ProductImageSeo::normalize(['filename' => $name, 'alt' => 'Foto', 'title' => 'Foto', 'caption' => 'Foto', 'description' => 'Foto']);
                $this->fail('A numeric filename must never pass validation.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('filename', $e->errors());
            }
        }
    }
}

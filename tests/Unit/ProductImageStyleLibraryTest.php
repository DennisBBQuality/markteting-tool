<?php

namespace Tests\Unit;

use App\Services\ProductImageStyleLibrary;
use Tests\TestCase;

class ProductImageStyleLibraryTest extends TestCase
{
    public function test_every_approved_style_reference_is_available_as_a_valid_image(): void
    {
        $library = new ProductImageStyleLibrary;

        $this->assertCount(9, $library->ids());

        foreach ($library->ids() as $id) {
            $reference = $library->reference($id);
            $metadata = getimagesize($reference['path']);

            $this->assertFileExists($reference['path']);
            $this->assertStringStartsWith('style-', $reference['filename']);
            $this->assertSame('image/png', $metadata['mime']);
        }

        $this->assertNull($library->reference('onbekende_stijl'));
        $this->assertNull($library->reference(null));
    }
}

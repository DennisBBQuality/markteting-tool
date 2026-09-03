<?php

namespace Tests\Unit;

use App\Models\ProductImageStyleReference;
use App\Services\ProductImageStyleLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductImageStyleLibraryTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_latest_approved_photo_is_found_only_for_the_same_product_status_and_style(): void
    {
        ProductImageStyleReference::create([
            'product_name' => 'Black Angus brisket',
            'product_key' => ProductImageStyleReference::productKey('Black Angus brisket'),
            'product_type' => 'meat',
            'status' => 'bereid',
            'style_id' => 'bbq_buiten_brisket',
            'source_version' => 1,
            'mime_type' => 'image/png',
            'contents_base64' => base64_encode('goedgekeurde-foto'),
        ]);

        $reference = (new ProductImageStyleLibrary)->approvedReference([
            'product_name' => 'black-angus BRISKET',
        ], [
            'status' => 'bereid',
            'style_id' => 'bbq_buiten_brisket',
        ]);

        $this->assertNotNull($reference);
        $this->assertSame(base64_encode('goedgekeurde-foto'), $reference['contents_base64']);
        $this->assertStringStartsWith('approved-', $reference['filename']);

        $this->assertNull((new ProductImageStyleLibrary)->approvedReference([
            'product_name' => 'Picanha',
        ], [
            'status' => 'bereid',
            'style_id' => 'bbq_buiten_brisket',
        ]));
        $this->assertNull((new ProductImageStyleLibrary)->approvedReference([
            'product_name' => 'Black Angus brisket',
        ], [
            'status' => 'bereid',
            'style_id' => 'serveerbeeld_brisket',
        ]));
    }
}

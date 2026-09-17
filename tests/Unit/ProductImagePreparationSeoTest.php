<?php

namespace Tests\Unit;

use App\Services\ProductImageDelivery;
use App\Services\ProductImagePreparationSeo;
use App\Services\ProductImagePromptBuilder;
use App\Services\ProductImageSeo;
use Tests\TestCase;

class ProductImagePreparationSeoTest extends TestCase
{
    private function fields(): array
    {
        return ['filename' => 'product-op-bord.webp', 'alt' => 'Product op een bord', 'title' => 'Product op bord',
            'caption' => 'Serveersuggestie: product op een bord.', 'description' => 'Product op een bord met een airfryer op de achtergrond.'];
    }

    public function test_all_meat_and_fish_variants_include_their_method_in_each_field_without_duplicates(): void
    {
        foreach (['meat', 'fish'] as $type) {
            foreach (['raw', 'bbq', 'pan', 'oven', 'airfryer'] as $group) {
                $context = ['product_type' => $type, 'variant_groups' => [$group]];
                foreach (app(ProductImagePromptBuilder::class)->plans($context) as $plan) {
                    $fields = ProductImageSeo::normalizeForImage($this->fields(), $context, $plan);
                    if ($group === 'raw') {
                        $this->assertSame($this->fields(), $fields);

                        continue;
                    }
                    $this->assertStringEndsWith('-'.$group.'.webp', $fields['filename']);
                    foreach (['alt', 'title', 'caption', 'description'] as $field) {
                        $this->assertStringContainsString(ProductImagePreparationSeo::phrase($group), $fields[$field]);
                    }
                    $this->assertSame($fields, ProductImageSeo::normalizeForImage($fields, $context, $plan));
                    $fallback = app(ProductImageDelivery::class)->metadata($context, $plan, 1);
                    $this->assertStringContainsString(ProductImagePreparationSeo::phrase($group), $fallback['alt']);
                }
            }
        }
    }

    public function test_background_or_partial_word_does_not_count_but_real_preparation_does(): void
    {
        $result = ['status' => 'bereid', 'style_id' => 'keuken_pan_steak'];
        $fields = [...$this->fields(), 'filename' => 'pancetta.webp', 'alt' => 'Pancetta met een pan op de achtergrond'];
        $completed = ProductImageSeo::normalizeForImage($fields, [], $result);
        $this->assertSame('pancetta-pan.webp', $completed['filename']);
        $this->assertStringContainsString('bereid in de pan', $completed['alt']);
        $fields['alt'] = 'Pancetta gebakken in de pan';
        $this->assertSame($fields['alt'], ProductImageSeo::normalizeForImage($fields, [], $result)['alt']);
        $fields['filename'] = str_repeat('product-', 40).'webp';
        $completed = ProductImageSeo::normalizeForImage($fields, [], $result);
        $this->assertLessThanOrEqual(185, strlen($completed['filename']));
        $this->assertStringEndsWith('-pan.webp', $completed['filename']);
    }

    public function test_unknown_legacy_kitchens_raw_and_non_food_products_do_not_get_a_method(): void
    {
        foreach ([
            [[], ['status' => 'bereid', 'style_id' => 'keuken_licht_steak']],
            [[], ['status' => 'bereid']],
            [['product_name' => 'Airfryer oven BBQ pan'], ['status' => 'rauw', 'style_id' => 'keuken_pan_steak']],
            [['product_type' => 'sauce'], ['status' => 'bereid', 'style_id' => 'bbq_buiten_steak']],
            [['product_type' => 'bundle'], ['status' => 'bereid', 'style_id' => 'keuken_oven_steak']],
        ] as [$context, $result]) {
            $this->assertNull(ProductImagePreparationSeo::method($context, $result));
            $this->assertSame($this->fields(), ProductImageSeo::normalizeForImage($this->fields(), $context, $result));
        }
    }
}

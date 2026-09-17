<?php

namespace Tests\Unit;

use App\Models\ImagePrompt;
use App\Services\ProductImagePromptBuilder;
use PHPUnit\Framework\TestCase;

class KitchenProductImageTest extends TestCase
{
    public function test_fifth_photo_is_a_light_home_kitchen_without_dark_or_bbq_reference(): void
    {
        $builder = new ProductImagePromptBuilder;
        foreach (['meat', 'fish'] as $type) {
            $context = ['product_type' => $type, 'product_name' => 'Testproduct', 'quantity' => 2];
            $plans = $builder->plans($context);
            $this->assertCount(5, $plans);
            $this->assertCount(3, array_filter($plans, fn ($plan) => $plan['status'] === 'bereid'));
            $this->assertCount(2, array_filter($plans, fn ($plan) => $plan['status'] === 'rauw'));
            $this->assertSame('woonkeuken_licht', $plans[4]['scene_family']);
            $this->assertNull($plans[4]['style_reference_id']);
            $prompt = $builder->prompt(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $context, $plans[4]);
            $this->assertStringContainsString('lichte moderne woonkeuken', $prompt);
            $this->assertStringContainsString('Geen barbecue, kamado, smoker', $prompt);
            $this->assertStringContainsString('exact 2', $prompt);
            $this->assertStringNotContainsString('De allerlaatste afbeelding', $prompt);
            if ($type === 'fish') {
                $this->assertStringContainsString('natuurlijk en stabiel plat', $prompt);
                $this->assertStringContainsString('Geen standaard steakplakken', $prompt);
            }
        }
        foreach (['sauce', 'bundle'] as $type) {
            $this->assertCount(2, $builder->plans(['product_type' => $type]));
        }
    }

    public function test_cheeks_and_sucade_are_stews_in_the_kitchen(): void
    {
        $builder = new ProductImagePromptBuilder;
        foreach (['Varkens wangen ontvliesd', 'Varkenswangen', 'Kalfssucade'] as $name) {
            $context = ['product_type' => 'meat', 'product_name' => $name];
            $plan = $builder->plans($context)[4];
            $this->assertSame('keuken_licht_stoof', $plan['style_id']);
            $prompt = $builder->prompt(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $context, $plan);
            $this->assertStringContainsString('GEEN SNIJPLAKKEN', $prompt);
            $this->assertStringContainsString('BRONBEHOUD BIJ STOVEN', $prompt);
        }
    }
}

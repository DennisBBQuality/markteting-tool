<?php

namespace Tests\Unit;

use App\Services\ProductImagePromptBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductImagePromptBuilderTest extends TestCase
{
    public function test_brisket_variants_use_two_distinct_approved_scene_families(): void
    {
        $plans = (new ProductImagePromptBuilder)->plans([
            'product_type' => 'meat',
            'product_name' => 'Black Angus brisket',
        ]);

        $this->assertSame(['buiten_bbq', 'serveermoment'], array_column(array_slice($plans, 0, 2), 'scene_family'));
        $this->assertSame(['bbq_outdoor_kamado', 'serveer_brisket_plank'], array_column(array_slice($plans, 0, 2), 'style_reference_id'));
        $this->assertNotSame($plans[0]['style_reference_id'], $plans[1]['style_reference_id']);
        $this->assertStringContainsString('smoker', $plans[0]['instruction']);
        $this->assertStringContainsString('mahonie', $plans[1]['instruction']);
        $this->assertStringContainsString('exacte buitencontour, lengte-breedte-dikteverhouding', $plans[2]['instruction']);
        $this->assertSame('rauw_bbquality_vast', $plans[2]['style_reference_id']);
        $this->assertStringContainsString('volledig egale diepzwarte achterwand', $plans[2]['style']);
        $this->assertStringContainsString('één grote, hele en ongetrimde brisket', $plans[2]['instruction']);
        $this->assertStringContainsString('nooit een losse stapel', $plans[2]['instruction']);
        $this->assertStringContainsString('dezelfde buitenomtrek', $plans[3]['instruction']);
        $this->assertNull($plans[3]['style_reference_id']);
        $this->assertStringContainsString('wasachtig', $plans[3]['instruction']);
    }

    public function test_fixed_raw_background_reference_is_never_used_for_cooked_variants(): void
    {
        $plans = (new ProductImagePromptBuilder)->plans([
            'product_type' => 'meat',
            'product_name' => 'Black Angus brisket',
        ]);

        $this->assertNotSame('rauw_bbquality_vast', $plans[0]['style_reference_id']);
        $this->assertNotSame('rauw_bbquality_vast', $plans[1]['style_reference_id']);
        $this->assertSame('rauw_bbquality_vast', $plans[2]['style_reference_id']);

        $prompt = (new ProductImagePromptBuilder)->prompt('Maak een betrouwbare productfoto.', [
            'product_name' => 'Black Angus brisket',
            'quantity' => 1,
            'product_reference_count' => 2,
        ], $plans[2]);

        $this->assertStringContainsString('LEGE VASTE BBQUALITY-ACHTERGRONDREFERENTIE', $prompt);
        $this->assertStringContainsString('zo exact mogelijk over', $prompt);
        $this->assertStringContainsString('de enige bron voor de vorm', $prompt);
        $this->assertStringContainsString('Leid nooit productvorm', $prompt);
    }

    public function test_beef_steak_has_one_uncut_and_one_sliced_medium_variant(): void
    {
        $plans = (new ProductImagePromptBuilder)->plans([
            'product_type' => 'meat',
            'product_name' => 'Picanha steak',
        ]);

        $this->assertStringContainsString('medium', $plans[0]['instruction']);
        $this->assertStringContainsString('volledig ongesneden', $plans[0]['instruction']);
        $this->assertStringContainsString('medium', $plans[1]['instruction']);
        $this->assertStringContainsString('snijd uitsluitend deze variant open', $plans[1]['instruction']);
    }

    #[DataProvider('productFamilyProvider')]
    public function test_meat_products_receive_the_expected_product_specific_plan(string $name, string $family): void
    {
        $plans = (new ProductImagePromptBuilder)->plans([
            'product_type' => 'meat',
            'product_name' => $name,
        ]);

        $this->assertSame('bbq_buiten_'.$family, $plans[0]['style_id']);
        $this->assertSame('serveerbeeld_'.$family, $plans[1]['style_id']);
        $this->assertSame('buiten_bbq', $plans[0]['scene_family']);
        $this->assertSame('serveermoment', $plans[1]['scene_family']);
    }

    public static function productFamilyProvider(): array
    {
        return [
            ['Black Angus brisket', 'brisket'],
            ['MOINK balls', 'moink'],
            ['Hamburger', 'burger'],
            ['Spareribs', 'ribs'],
            ['Picanha steak', 'steak'],
        ];
    }

    public function test_prompt_keeps_product_and_style_references_strictly_separate(): void
    {
        $builder = new ProductImagePromptBuilder;
        $plan = $builder->plans([
            'product_type' => 'meat',
            'product_name' => 'Black Angus brisket',
        ])[0];

        $prompt = $builder->prompt('Maak een betrouwbare productfoto.', [
            'product_name' => 'Black Angus brisket',
            'quantity' => 1,
            'product_reference_count' => 2,
        ], $plan);

        $this->assertStringContainsString('afbeelding 1 t/m 2', $prompt);
        $this->assertStringContainsString('De allerlaatste afbeelding', $prompt);
        $this->assertStringContainsString('uitsluitend een goedgekeurd BBQuality-STIJLVOORBEELD', $prompt);
        $this->assertStringContainsString('Kopieer nooit het vlees, gerecht, aantal', $prompt);
        $this->assertStringContainsString('Sneden of plakken blijven aantoonbaar delen', $prompt);
        $this->assertStringContainsString('Bij conflict winnen de productreferenties altijd', $prompt);
    }
}

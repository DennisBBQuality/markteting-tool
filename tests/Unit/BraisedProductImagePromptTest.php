<?php

namespace Tests\Unit;

use App\Models\ImagePrompt;
use App\Services\ProductImagePromptBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BraisedProductImagePromptTest extends TestCase
{
    #[DataProvider('braisedProductNames')]
    public function test_sucade_and_explicit_stew_names_generate_stew_dishes_without_board_or_slicing_instructions(string $name): void
    {
        $builder = new ProductImagePromptBuilder;
        $context = ['product_type' => 'meat', 'product_name' => $name, 'quantity' => 2, 'product_reference_count' => 2];
        $plans = $builder->plans($context);

        $this->assertSame(['bereid', 'bereid', 'rauw', 'rauw'], array_column($plans, 'status'));
        $this->assertSame(['bbq_buiten_stoof', 'serveerbeeld_stoof'], array_column(array_slice($plans, 0, 2), 'style_id'));
        $this->assertSame(['buiten_bbq', 'serveermoment'], array_column(array_slice($plans, 0, 2), 'scene_family'));
        $this->assertStringContainsString('rustiek keramisch bord buiten', $plans[0]['style']);
        $this->assertStringContainsString('ondiepe stoofpan', $plans[1]['style']);

        foreach (array_slice($plans, 0, 2) as $plan) {
            $this->assertSame('stoof', $plan['preparation']);
            $this->assertNull($plan['style_reference_id']);
            $prompt = $builder->prompt(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $context, $plan);

            foreach (['stoof exact 2 oorspronkelijk(e)', 'GEEN SNIJPLAKKEN', 'met een vork losgemaakt', 'BRONBEHOUD BIJ STOVEN', 'De rauwe buitencontour hoeft dus niet star intact te blijven', 'uitsluitend serveersuggesties', 'volledig gaar', 'zichtbare bord- of panrand', 'Geen aangezette microcontrasten'] as $expected) {
                $this->assertStringContainsString($expected, $prompt);
            }
            foreach (['BRONBEHOUD BIJ BEREIDING', 'Afgesneden plakken komen', 'Sneden of plakken blijven', 'alle bijbehorende plakken', 'zichtbare plankrand', 'op een ambachtelijke houten serveerplank', 'maak een natuurlijke aangebraden korst', 'medium', 'De allerlaatste afbeelding', 'LEGE VASTE BBQUALITY'] as $conflicting) {
                $this->assertStringNotContainsString($conflicting, $prompt);
            }
        }
        $this->assertSame('rauw_bbquality_vast', $plans[2]['style_reference_id']);
        $this->assertNull($plans[3]['style_reference_id']);
    }

    public static function braisedProductNames(): array
    {
        return array_map(fn ($name) => [$name], [
            'Kalfssucade', 'kalfssukade', 'Kalfs sucade', 'Rundersucade',
            'Sukadelappen', 'Stoofvlees', 'Suddervlees', 'Stooflappen', 'Sudderlappen',
        ]);
    }

    public function test_explicit_steak_name_is_not_overridden_by_sucade_default(): void
    {
        $builder = new ProductImagePromptBuilder;
        $plans = $builder->plans(['product_type' => 'meat', 'product_name' => 'Sucade steak']);

        $this->assertSame('bbq_buiten_steak', $plans[0]['style_id']);
        $this->assertStringContainsString('medium', $plans[0]['instruction']);
        $this->assertStringContainsString('snijd uitsluitend deze variant open', $plans[1]['instruction']);
        $this->assertArrayNotHasKey('preparation', $plans[0]);
    }

    public function test_approved_stew_reference_has_its_own_role_and_employee_input_is_preserved(): void
    {
        $builder = new ProductImagePromptBuilder;
        $context = ['product_type' => 'meat', 'product_name' => 'Kalfssucade', 'quantity' => 1, 'product_reference_count' => 2, 'notes' => 'Geen wortel als bijgerecht.'];
        $plan = $builder->plans($context)[0];
        $plan['approved_reference_added'] = true;
        $plan['bundled_reference_added'] = false;
        $customBase = 'Onze eigen basis: geen doek in beeld.';
        $prompt = $builder->prompt($customBase, $context, $plan);

        $this->assertStringStartsWith($customBase, $prompt);
        $this->assertStringContainsString('Afbeelding 3 is een door BBQuality goedgekeurd stoofvoorbeeld', $prompt);
        $this->assertStringContainsString('voor de stoofbereiding en bord- of panpresentatie', $prompt);
        $this->assertStringContainsString('EXTRA INFORMATIE VAN DE MEDEWERKER: Geen wortel als bijgerecht.', $prompt);
        $this->assertStringNotContainsString('De allerlaatste afbeelding', $prompt);
    }

    public function test_raw_sucade_and_existing_cooked_families_remain_identical_to_before_this_change(): void
    {
        // Captured before adding the stew profile, including each plan and its complete prompt.
        $baselines = [
            'Kalfssucade' => [2 => '04bba966d896c33e1d853d93f49c873815c837cf4947b318de6107c11292795e', 3 => 'c1fa4898c80e7dc8b6efe2cc2318cb837cbd3dea01cc6f0ed4a0464cfad54a36'],
            'Bizon ribeye Canada' => ['922d31cbe2143f0d4cb986c0bc7893d06f4ec6a3790afd7802a4befdc18202ea', '9a075901bc91048ef8835e057c6bca83ec4694da96b636b39b2c73d2fe7cfd3a'],
            'Black Angus brisket' => ['fc31d14c8bbebadd8063f2f25aa4f65600cb09a6f2740dd6413f219b5e1904b1', 'caeb493c1061a79cb2c4c5287cc454ff25dd418444b9ecc16c5f7519c2fb87be'],
            'MOINK balls' => ['9b1e7414fc94abbe2ffea8a079ccc6a24861a0db531474c568dc3b28842ecf2d', 'aa6618e030b9582572b04135517d73d9e6184e980ee9df6fdd49c7378da5aaaa'],
            'Hamburger' => ['a154bd48b29bcfcefd7c06c403aa113965f3416a964116d18b7fcebd76aacc13', '1c82ae3ec3645ef2f113a61e69a3e33b7819cfa2bc00affbbed0e5d27d4ba059'],
            'Spareribs' => ['168aaab372b3a9c71dd8018ad84e0cde911ee1fae861a50aa5e6aea1955eea53', '1a4795e937b6d738099cd67e115412c6fd9206e3b87fcf3495ed83fa896657a8'],
            'Lamsbout' => ['96eb210e6621791d4ac39c734de7a33847ae254e32b54b58c7b66c607702b542', 'a67277f69c013058573a251215c859eb6c85fc25b65eb2a60743d44379db65a6'],
        ];
        $builder = new ProductImagePromptBuilder;
        foreach ($baselines as $name => $hashes) {
            $context = ['product_type' => 'meat', 'product_name' => $name, 'quantity' => 2, 'product_reference_count' => 2];
            $plans = $builder->plans($context);
            foreach ($hashes as $index => $hash) {
                $plan = $plans[$index];
                $this->assertSame($hash, hash('sha256', json_encode($plan).$builder->prompt(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $context, $plan)), $name.' variant '.$index);
            }
        }
    }
}

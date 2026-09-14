<?php

namespace Tests\Unit;

use App\Models\ImagePrompt;
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
        $this->assertStringContainsString('samen reconstrueren ze het bereide volume en silhouet', $plans[0]['instruction']);
        $this->assertStringContainsString('nooit een hap, wig, hoek of zijstuk', $plans[0]['instruction']);
        $this->assertStringContainsString('mahonie', $plans[1]['instruction']);
        $this->assertStringContainsString('smoke ring is dun, subtiel en plaatselijk onderbroken', $plans[1]['instruction']);
        $this->assertStringContainsString('zonder plastic glans, herhaalde patronen, identieke plakken', $plans[1]['instruction']);
        $this->assertStringContainsString('geen beeldvullende macro-opname', $plans[1]['instruction']);
        $this->assertStringContainsString('exacte buitencontour, lengte-breedte-dikteverhouding', $plans[2]['instruction']);
        $this->assertSame('rauw_bbquality_vast', $plans[2]['style_reference_id']);
        $this->assertStringContainsString('volledig egale diepzwarte achterwand', $plans[2]['style']);
        $this->assertStringContainsString('één grote, hele en ongetrimde brisket', $plans[2]['instruction']);
        $this->assertStringContainsString('altijd natuurlijk en stabiel plat op de breedste zijde', $plans[2]['instruction']);
        $this->assertStringContainsString('mag nooit staan, rechtop worden gezet', $plans[2]['instruction']);
        $this->assertStringContainsString('lange as overwegend horizontaal', $plans[3]['instruction']);
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
            ['Bizon ribeye Canada', 'steak'],
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

    public function test_prompt_explains_how_an_approved_product_photo_may_be_reused(): void
    {
        $builder = new ProductImagePromptBuilder;
        $plan = $builder->plans([
            'product_type' => 'meat',
            'product_name' => 'Black Angus brisket',
        ])[0];
        $plan['approved_reference_added'] = true;
        $plan['bundled_reference_added'] = false;

        $prompt = $builder->prompt('Maak een betrouwbare productfoto.', [
            'product_name' => 'Black Angus brisket',
            'quantity' => 1,
            'product_reference_count' => 2,
        ], $plan);

        $this->assertStringContainsString('door BBQuality goedgekeurde eerdere foto', $prompt);
        $this->assertStringContainsString('kwaliteitsanker', $prompt);
        $this->assertStringContainsString('actuele echte productreferenties', $prompt);
        $this->assertStringContainsString('Kopieer nooit het aantal', $prompt);
        $this->assertStringContainsString('Afbeelding 3', $prompt);
        $this->assertStringContainsString('niet om extra korrels, glans of verscherping te kopiëren', $prompt);
        $this->assertStringNotContainsString('De allerlaatste afbeelding', $prompt);
    }

    #[DataProvider('productFamilyProvider')]
    public function test_cooked_photography_preserves_identity_but_allows_cooking_changes(string $name, string $family): void
    {
        $builder = new ProductImagePromptBuilder;
        $context = ['product_type' => 'meat', 'product_name' => $name, 'quantity' => 3, 'product_reference_count' => 2];

        foreach (array_slice($builder->plans($context), 0, 2) as $plan) {
            $prompt = $builder->prompt(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $context, $plan);
            $this->assertStringStartsWith('Maak een fotorealistische BBQuality-sfeerfoto', $prompt);
            $this->assertStringContainsString('bereid exact 3 oorspronkelijk(e)', $prompt);
            $this->assertStringContainsString('natuurlijke krimp, garing, bruining en het smelten van vet', $prompt);
            $this->assertStringContainsString('Behoud dus niet letterlijk de rauwe kleur', $prompt);
            $this->assertStringContainsString('FOTOGRAFIE BEREID VLEES: zacht diffuus zijlicht', $prompt);
            $this->assertStringContainsString('subtiele vezeltekening', $prompt);
            $this->assertStringContainsString('Vocht en gesmolten vet glanzen plaatselijk en zacht', $prompt);
            $this->assertStringContainsString('de aangebraden vleesdelen eromheen zijn minder reflecterend', $prompt);
            $this->assertStringContainsString('glaze alleen waar de productspecifieke bereiding die vraagt', $prompt);
            $this->assertStringContainsString('Geen aangezette microcontrasten', $prompt);
            $this->assertStringContainsString('niet glad of wazig', $prompt);
            $this->assertStringContainsString('alle bijbehorende plakken binnen het kader', $prompt);
            $this->assertStringContainsString('geen beeldvullende macro-opname', $prompt);
            $this->assertStringNotContainsString('Voeg nooit een tweede hoofdproduct toe', $prompt);
            $this->assertStringNotContainsString('LEGE VASTE BBQUALITY', $prompt);
        }
    }

    public function test_cooked_scene_references_do_not_direct_meat_texture_or_harsh_lighting(): void
    {
        $builder = new ProductImagePromptBuilder;
        $context = ['product_type' => 'meat', 'product_name' => 'Black Angus brisket', 'quantity' => 1];
        $plans = $builder->plans($context);

        foreach (array_slice($plans, 0, 2) as $plan) {
            $prompt = $builder->prompt(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $context, $plan);
            $this->assertStringContainsString('geen referentie voor korst, vleesvezels, vocht, glaze, kruiding of scherpte', $prompt);
            $this->assertStringContainsString('ook wanneer het stijlvoorbeeld harder licht', $prompt);
        }
        $this->assertStringContainsString('zonder hard direct zonlicht', $plans[0]['style']);
        $this->assertStringContainsString('hoogstens één of twee kleine', $plans[1]['style']);
        $this->assertStringContainsString('niet standaard met losse peper- of zoutkorrels', $plans[1]['style']);
        $this->assertStringContainsString('specerijen zijn klein en ingebed', $plans[0]['instruction']);
        $this->assertStringNotContainsString('dezelfde ene brisket', $plans[1]['instruction']);
    }

    #[DataProvider('steakProductProvider')]
    public function test_cooked_steaks_keep_source_shape_and_fat_instead_of_an_idealised_species_template(string $name): void
    {
        $builder = new ProductImagePromptBuilder;
        $context = ['product_type' => 'meat', 'product_name' => $name, 'quantity' => 2, 'product_reference_count' => 2];
        $plans = $builder->plans($context);

        foreach (array_slice($plans, 0, 2) as $plan) {
            // An approved result must not override the actual steak's proportions or fat seams.
            $plan['approved_reference_added'] = true;
            $plan['bundled_reference_added'] = false;
            $prompt = $builder->prompt(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $context, $plan);

            $this->assertStringContainsString('herkenbare lengte-breedte-dikteverhouding', $prompt);
            $this->assertStringContainsString('alleen over waar ze in de productfoto zichtbaar zijn; verzin ze niet', $prompt);
            $this->assertStringContainsString('Maak een langgerekt stuk niet ronder, hoger of compacter', $prompt);
            $this->assertStringContainsString('zichtbare spierindeling en ligging van vetnaden blijven herkenbaar', $prompt);
            $this->assertStringContainsString('niet magerder of vetter op basis van algemene aannames over de diersoort', $prompt);
            $this->assertStringContainsString('vet mag slinken en bruinen, maar aanwezige vetstroken worden niet weggepoetst', $prompt);
            $this->assertStringContainsString('De hoofdfoto bepaalt het te bereiden exemplaar', $prompt);
            $this->assertStringContainsString('niet een gemiddelde of ideale productvorm', $prompt);
            $this->assertStringContainsString('voor identiteit, vorm, vetverdeling en hoeveelheid', $prompt);
            $this->assertStringContainsString('bereid exact 2 oorspronkelijk(e)', $prompt);
            $this->assertStringContainsString('fijne, spaarzame kruiding', $prompt);
            $this->assertStringContainsString('medium', $plan['instruction']);
            $this->assertStringNotContainsString('rundvlees', $plan['instruction']);
        }

        $this->assertStringContainsString('volledig ongesneden', $plans[0]['instruction']);
        $this->assertStringContainsString('snijd uitsluitend deze variant open', $plans[1]['instruction']);
        $this->assertStringContainsString('snijvlakken en vetnaden sluiten logisch aan', $plans[1]['instruction']);
    }

    public static function steakProductProvider(): array
    {
        return [
            'bison ribeye' => ['Bizon ribeye Canada'],
            'beef ribeye' => ['Black Angus ribeye'],
            'different outline' => ['Picanha steak'],
        ];
    }

    public function test_outdoor_steak_uses_neutral_daylight_without_scattered_seasoning(): void
    {
        $builder = new ProductImagePromptBuilder;
        $plans = $builder->plans(['product_type' => 'meat', 'product_name' => 'Bizon ribeye Canada']);

        $this->assertStringContainsString('neutrale daglichtwitbalans', $plans[0]['style']);
        $this->assertStringContainsString('Het hout mag van zichzelf warm zijn', $plans[0]['style']);
        $this->assertStringContainsString('geen oranje of goudgele kleurzweem', $plans[0]['style']);
        $this->assertStringContainsString('zonder standaard uitgestrooide peper, zout of kruiden', $plans[0]['style']);
        $this->assertSame('bbq_outdoor_kamado', $plans[0]['style_reference_id']);
        $this->assertSame('serveer_steak_rustiek', $plans[1]['style_reference_id']);
        $this->assertStringContainsString('zacht raamlicht', $plans[1]['style']);
    }

    public function test_local_fat_highlights_do_not_remove_the_intended_moink_glaze(): void
    {
        $builder = new ProductImagePromptBuilder;
        $context = ['product_type' => 'meat', 'product_name' => 'MOINK balls'];
        $plans = $builder->plans($context);

        foreach (array_slice($plans, 0, 2) as $plan) {
            $prompt = $builder->prompt(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $context, $plan);
            $this->assertStringContainsString('een glaze alleen waar de productspecifieke bereiding die vraagt', $prompt);
            $this->assertStringNotContainsString('fijne, spaarzame kruiding', $prompt);
        }
        $this->assertStringContainsString('de glaze glanzend maar niet plasticachtig', $plans[0]['instruction']);
        $this->assertStringContainsString('geglaceerde MOINK balls', $plans[1]['instruction']);
    }

    public function test_cooked_prompt_keeps_custom_base_and_employee_notes(): void
    {
        $builder = new ProductImagePromptBuilder;
        $context = ['product_type' => 'meat', 'product_name' => 'Test steak', 'quantity' => 2, 'notes' => 'Gebruik onze ovale plank.'];
        $custom = 'Onze eigen basisinstructie: gebruik geen doek.';
        $prompt = $builder->prompt($custom, $context, $builder->plans($context)[0]);

        $this->assertStringStartsWith($custom, $prompt);
        $this->assertStringContainsString('EXTRA INFORMATIE VAN DE MEDEWERKER: Gebruik onze ovale plank.', $prompt);
        $this->assertStringContainsString('FOTOGRAFIE BEREID VLEES', $prompt);
    }

    public function test_cooked_prompt_without_style_input_does_not_invent_a_reference(): void
    {
        $builder = new ProductImagePromptBuilder;
        $context = ['product_type' => 'meat', 'product_name' => 'Test steak', 'product_reference_count' => 5];
        $plan = $builder->plans($context)[0];
        $plan['approved_reference_added'] = false;
        $plan['bundled_reference_added'] = false;
        $prompt = $builder->prompt(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $context, $plan);

        $this->assertStringContainsString('afbeelding 1 t/m 5', $prompt);
        $this->assertStringNotContainsString('De allerlaatste afbeelding', $prompt);
        $this->assertStringNotContainsString('kwaliteitsanker', $prompt);
    }

    public function test_non_cooked_plans_and_prompts_remain_byte_identical_to_the_baseline(): void
    {
        // Captured before the cooked-only revision; not generated from the implementation under test.
        $baseline = [
            'meat.2' => 'bf560efad3c7cba8fe5882f67bff5a4290e969b32b2a03b05ab246a8a80391b7',
            'meat.2.approved' => 'a37d9986f826f8f78d900604d69265c76644d0622a1c2d1727b4b73cc9450101',
            'meat.3' => '0d3cb1c074acbe512ca516d60835ed88e67e586779ac3c598233e36d38de810d',
            'meat.3.approved' => '78200a0817022cc1db6080aef277319ca9ff2847b146fc830d0c5ba85a5537c6',
            'sauce.0' => 'e18ed81a4089908a901377411f90fea422241fc845e3e93887d4b187202bb6a7',
            'sauce.1' => 'ca1854903f13a7d33463a0125ff7149ad47ad55b77540666d6c55d76ac886d9f',
            'bundle.0' => '5c99980d9d6e941b4d85f701246e33ec211efd5de0dabc8e494c25b7f7865a75',
            'bundle.1' => '85ec36b08a8ac216f8a40419ab8c932ee1efd7af00ded6af0460bfc9d8120870',
        ];
        $builder = new ProductImagePromptBuilder;
        foreach (['meat' => 'Black Angus brisket', 'sauce' => 'Test rub', 'bundle' => 'Test pakket'] as $type => $name) {
            $context = ['product_type' => $type, 'product_name' => $name, 'quantity' => 2, 'notes' => 'Testnotitie', 'components' => 'Testinhoud', 'product_reference_count' => 2];
            foreach ($builder->plans($context) as $index => $plan) {
                if ($plan['status'] === 'bereid') {
                    continue;
                }
                $this->assertSame($baseline[$type.'.'.$index], hash('sha256', json_encode($plan).$builder->prompt(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $context, $plan)));
                if ($type === 'meat') {
                    $plan['approved_reference_added'] = true;
                    $plan['bundled_reference_added'] = $index === 2;
                    $this->assertSame($baseline[$type.'.'.$index.'.approved'], hash('sha256', json_encode($plan).$builder->prompt(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $context, $plan)));
                }
            }
        }
    }
}

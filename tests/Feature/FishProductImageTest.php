<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductImages;
use App\Models\ImagePrompt;
use App\Models\ProductImageAsset;
use App\Models\ProductImageRequest;
use App\Models\ProductImageStyleReference;
use App\Services\OpenAiProductImageGenerator;
use App\Services\ProductImageFormat;
use App\Services\ProductImageGenerator;
use App\Services\ProductImagePromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FishProductImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_fish_plans_preserve_flat_shape_and_separate_the_two_raw_backgrounds(): void
    {
        $builder = new ProductImagePromptBuilder;
        foreach (['Zalmhaas zonder huid', 'Hele zeebaars', 'Kreeftenstaart'] as $name) {
            $context = ['product_type' => 'fish', 'product_name' => $name, 'quantity' => 2];
            $plans = $builder->plans($context);
            $this->assertSame(['bereid', 'bereid', 'rauw', 'rauw', 'bereid'], array_column($plans, 'status'));
            $this->assertSame(['vis_buiten_bbq', 'vis_serveermoment', 'vis_rauw_zwart', 'vis_rauw_hout', 'keuken_licht_vis'], array_column($plans, 'style_id'));
            $this->assertSame('vis_rauw_zwart', $plans[2]['style_reference_id']);
            $this->assertNull($plans[3]['style_reference_id']);
            foreach ($plans as $plan) {
                $prompt = $builder->prompt(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $context, $plan);
                $this->assertStringContainsString('natuurlijk en stabiel plat', $prompt);
                $this->assertStringContainsString('Nooit rechtop zetten', $prompt);
                $this->assertStringContainsString('exact 2 exemplaar', $prompt);
                $this->assertStringNotContainsString('FOTOGRAFIE BEREID VLEES:', $prompt);
                $this->assertStringNotContainsString('dieprood spierweefsel', $prompt);
                $this->assertNotSame('rauw_bbquality_vast', $plan['style_reference_id']);
            }
            $black = $builder->prompt(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $context, $plans[2]);
            $wood = $builder->prompt(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $context, $plans[3]);
            $this->assertStringContainsString('ZWARTE ACHTERGROND EN ZWARTE ONDERGROND', $black);
            $this->assertStringContainsString('kopieer die vis nooit', $black);
            $this->assertStringContainsString('Hout is in deze tweede rauwe variant juist toegestaan', $wood);
            $this->assertStringNotContainsString('De allerlaatste afbeelding', $wood);
        }
    }

    public function test_cooked_fish_shares_meat_scenes_but_not_meat_material_and_custom_prompts_are_preserved(): void
    {
        $builder = new ProductImagePromptBuilder;
        $fish = $builder->plans(['product_type' => 'fish']);
        $meat = $builder->plans(['product_type' => 'meat']);
        foreach ([0, 1] as $index) {
            $this->assertSame($meat[$index]['scene_family'], $fish[$index]['scene_family']);
            $this->assertSame($meat[$index]['style_reference_id'], $fish[$index]['style_reference_id']);
            $plan = [...$fish[$index], 'approved_reference_added' => true, 'bundled_reference_added' => false];
            $prompt = $builder->prompt('Onze eigen basisinstructie.', ['product_type' => 'fish', 'notes' => 'Geen garnering.', 'product_reference_count' => 2], $plan);
            $this->assertStringStartsWith('Onze eigen basisinstructie.', $prompt);
            $this->assertStringContainsString('Geen garnering.', $prompt);
            $this->assertStringContainsString('Afbeelding 3 is een goedgekeurde', $prompt);
            $this->assertStringNotContainsString('De allerlaatste afbeelding', $prompt);
            $this->assertStringContainsString('Geen standaard steakplakken', $prompt);
        }
    }

    public function test_member_can_generate_five_fish_photos_and_download_webp(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsUser(['rol' => 'lid']);
        $queued = $this->post('/api/images/generate', [
            'fotos' => [UploadedFile::fake()->image('testvis.png', 120, 80)],
            'product_type' => 'fish', 'product_name' => 'Testvis', 'quantity' => 1,
        ], ['Accept' => 'application/json'])->assertAccepted();
        $request = ProductImageRequest::findOrFail($queued->json('request_id'));
        $this->assertSame('fish', $request->generation_context['product_type']);
        (new GenerateProductImages($request->id))->handle(app(ProductImageGenerator::class));
        $results = $this->getJson('/api/images/requests/'.$request->id)
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonCount(5, 'results')->json('results');
        $this->assertSame(['Vis bereid', 'Vis bereid', 'Vis rauw', 'Vis rauw', 'Vis bereid · Keuken'], array_column($results, 'label'));
        $this->assertSame([1, 2, 1, 2, 3], array_column($results, 'variant'));
        foreach ($results as $result) {
            $this->getJson($result['download_url'])->assertUnprocessable(); // Wait for image-specific SEO; no numbered fallback.
        }
        $this->assertSame(5, ProductImageAsset::where('product_image_request_id', $request->id)->count());
    }

    public function test_provider_receives_only_the_correct_references_and_keeps_model_quality(): void
    {
        config(['services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-key']);
        $photo = UploadedFile::fake()->image('testvis.png', 40, 40);
        $encoded = base64_encode(ProductImageFormat::placeholder());
        // Even an identically named product saved under meat must never leak into fish.
        ProductImageStyleReference::create([
            'product_name' => 'Testvis', 'product_key' => 'testvis', 'product_type' => 'meat',
            'status' => 'rauw', 'style_id' => 'vis_rauw_zwart', 'source_version' => 1,
            'mime_type' => 'image/png', 'contents_base64' => $encoded,
        ]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => [['b64_json' => $encoded]]])]);
        $results = app(OpenAiProductImageGenerator::class)->generateForProduct([$photo], ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, [
            'product_type' => 'fish', 'product_name' => 'Testvis', 'quantity' => 1, 'image_model' => 'gpt-image-2.5-sunburst',
        ]);
        $this->assertCount(5, $results);
        Http::assertSentCount(5);
        foreach (Http::recorded() as $index => [$request]) {
            $fields = collect($request->data());
            $files = $fields->filter(fn ($field) => isset($field['filename']))->pluck('filename')->all();
            $this->assertNotContains('style-rauw-bbquality-vast.png', $files);
            $expectedStyle = ['style-bbq-outdoor-kamado.png', 'style-serveer-brisket-plank.png', 'style-vis-rauw-zwart.png', null, null][$index];
            $this->assertCount($index >= 3 ? 1 : 2, $files);
            if ($expectedStyle) {
                $this->assertContains($expectedStyle, $files);
            }
            $keyed = $fields->keyBy('name');
            $this->assertSame('high', $keyed['quality']['contents']);
            $this->assertSame('png', $keyed['output_format']['contents']);
            $this->assertSame('gpt-image-2.5-sunburst', $keyed['model']['contents']);
        }
    }
}

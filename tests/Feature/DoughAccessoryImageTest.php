<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductImages;
use App\Jobs\GenerateProductImageSeo;
use App\Models\ImagePrompt;
use App\Models\ProductImageAsset;
use App\Models\ProductImageRequest;
use App\Models\ProductImageStyleReference;
use App\Services\FakeProductImageGenerator;
use App\Services\OpenAiProductImageGenerator;
use App\Services\ProductImageFormat;
use App\Services\ProductImagePromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DoughAccessoryImageTest extends TestCase
{
    use RefreshDatabase;

    public static function products(): array
    {
        return [
            [['product_type' => 'dough', 'dough_kind' => 'bread', 'product_name' => 'Testbrood'], 'brood'],
            [['product_type' => 'dough', 'dough_kind' => 'pizza_balls', 'product_name' => 'Testdeegbol'], 'pizzabollen'],
            [['product_type' => 'accessory', 'product_name' => 'Testtang'], 'accessoire'],
        ];
    }

    #[DataProvider('products')]
    public function test_member_can_generate_exactly_three_ordered_photos_with_seo(array $context, string $family): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAsUser(['rol' => 'lid']);
        $response = $this->post('/api/images/generate', [...$context, 'quantity' => 2,
            'foto' => UploadedFile::fake()->image('testproduct.png', 30, 30)], ['Accept' => 'application/json'])
            ->assertAccepted()->assertJsonPath('expected_count', 3);
        $request = ProductImageRequest::findOrFail($response->json('request_id'));
        foreach ($context as $key => $value) {
            $this->assertSame($value, $request->generation_context[$key]);
        }
        (new GenerateProductImages($request->id))->handle(app(FakeProductImageGenerator::class));
        $data = $this->getJson('/api/images/requests/'.$request->id)->assertOk()
            ->assertJsonPath('image_status', 'completed')->assertJsonPath('status', 'processing_seo')
            ->assertJsonCount(3, 'results')->json();
        $this->assertSame([$family.'_zwart_hout', $family.'_huiselijk', $family.'_bbq'], array_column($data['results'], 'style_id'));
        $this->assertSame(3, ProductImageAsset::where('product_image_request_id', $request->id)->count());
        Queue::assertPushed(GenerateProductImageSeo::class, 3);
        $this->actingAsUser(['rol' => 'lid']);
        $this->getJson('/api/images/requests/'.$request->id)->assertNotFound();
    }

    #[DataProvider('products')]
    public function test_prompts_use_only_product_specific_rules_and_preserve_custom_prompts(array $context, string $family): void
    {
        $builder = new ProductImagePromptBuilder;
        $context += ['quantity' => 2, 'product_reference_count' => 2, 'notes' => 'Behoud de kleur.'];
        $plans = $builder->plans($context);
        $this->assertCount(3, $plans);
        $this->assertSame(['rauw_bbquality_vast', 'keuken_oven', 'bbq_outdoor_kamado'], array_column($plans, 'style_reference_id'));
        foreach ($plans as $plan) {
            $prompt = $builder->prompt(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $context, $plan);
            $this->assertStringContainsString('exact 2 oorspronkelijke exemplaren', $prompt);
            $this->assertStringContainsString('afbeelding 1 t/m 2', $prompt);
            $this->assertStringContainsString('Kopieer NOOIT', $prompt);
            $this->assertStringContainsString('Behoud de kleur.', $prompt);
            $this->assertStringNotContainsString('FOTOGRAFIE BEREID VLEES', $prompt);
            $this->assertStringNotContainsString('dieprood spierweefsel', $prompt);
            $custom = $builder->prompt('Eigen teaminstructie.', $context, [...$plan,
                'approved_reference_added' => true, 'bundled_reference_added' => false]);
            $this->assertStringStartsWith('Eigen teaminstructie.', $custom);
            $this->assertStringContainsString('Afbeelding 3 is een goedgekeurde', $custom);
            $this->assertStringNotContainsString('De allerlaatste afbeelding', $custom);
        }
        $this->assertStringContainsString('houten planken onderin', $plans[0]['style']);
        $this->assertStringContainsString('iets minder licht', $plans[1]['style']);
        if ($family === 'pizzabollen') {
            $this->assertStringContainsString('elektrische pizzaoven', $plans[1]['instruction']);
            $this->assertStringContainsString('pizzasteen in een open kamado', $plans[2]['instruction']);
            $this->assertStringContainsString('geen extra pizza', $plans[1]['instruction']);
        } elseif ($family === 'brood') {
            $this->assertStringContainsString('huishoudelijke oven', $plans[1]['instruction']);
            $this->assertStringContainsString('Geen pizza', $plans[2]['instruction']);
        } else {
            foreach ($plans as $plan) {
                $this->assertSame('product', $plan['status']);
                $this->assertStringContainsString('Niet bakken', $plan['instruction']);
                $this->assertStringContainsString('hittebestendigheid', $plan['instruction']);
            }
        }
    }

    public function test_invalid_dough_selection_and_variant_groups_do_not_start_generation(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAsUser();
        foreach ([null, '', 'unknown', ['bread']] as $kind) {
            $this->actingAsUser();
            $this->post('/api/images/generate', ['product_type' => 'dough', 'dough_kind' => $kind,
                'foto' => UploadedFile::fake()->image('test.png')], ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('dough_kind');
        }
        foreach (['dough', 'accessory'] as $type) {
            $this->actingAsUser();
            $this->post('/api/images/generate', ['product_type' => $type, 'dough_kind' => 'bread', 'variant_groups' => ['raw'],
                'foto' => UploadedFile::fake()->image('test.png')], ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('variant_groups');
        }
        Queue::assertNothingPushed();
        $this->assertSame(0, ProductImageRequest::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[DataProvider('products')]
    public function test_provider_gets_three_high_quality_images_and_keeps_fixed_background_with_approved_reference(array $context, string $family): void
    {
        config(['services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-key']);
        $encoded = base64_encode(ProductImageFormat::placeholder());
        $values = ['product_name' => $context['product_name'], 'product_key' => strtolower($context['product_name']),
            'status' => 'product', 'style_id' => $family.'_zwart_hout', 'source_version' => 1,
            'mime_type' => 'image/png', 'contents_base64' => $encoded];
        $other = ProductImageStyleReference::create([...$values, 'product_type' => 'meat']);
        $approved = ProductImageStyleReference::create([...$values, 'product_type' => $context['product_type']]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => [['b64_json' => $encoded]]])]);
        $results = app(OpenAiProductImageGenerator::class)->generateForProduct(
            [UploadedFile::fake()->image('test.png', 20, 20)], ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT,
            [...$context, 'quantity' => 1, 'image_model' => 'gpt-image-2.5-sunburst']);
        $this->assertCount(3, $results);
        Http::assertSentCount(3);
        foreach (Http::recorded() as $index => [$request]) {
            $fields = collect($request->data());
            $files = $fields->filter(fn ($field) => isset($field['filename']))->pluck('filename')->all();
            $this->assertNotContains('approved-'.$other->id.'.png', $files);
            $this->assertSame(['style-rauw-bbquality-vast.png', 'style-keuken-oven.png', 'style-bbq-outdoor-kamado.png'][$index], end($files));
            $this->assertCount($index === 0 ? 3 : 2, $files);
            if ($index === 0) {
                $this->assertContains('approved-'.$approved->id.'.png', $files);
            }
            $keyed = $fields->keyBy('name');
            $this->assertSame('high', $keyed['quality']['contents']);
            $this->assertSame('png', $keyed['output_format']['contents']);
            $this->assertSame('1536x1152', $keyed['size']['contents']);
        }
    }
}

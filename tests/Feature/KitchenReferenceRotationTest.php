<?php

namespace Tests\Feature;

use App\Models\ProductImageRequest;
use App\Models\ProductImageStyleReference;
use App\Services\KitchenProductImageStyles;
use App\Services\OpenAiProductImageGenerator;
use App\Services\ProductImageFormat;
use App\Services\ProductImagePromptBuilder;
use App\Services\ProductImageStyleLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KitchenReferenceRotationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_backgrounds_cycle_with_safe_category_specific_ids(): void
    {
        $library = new ProductImageStyleLibrary;
        foreach (['pan' => 5, 'oven' => 5, 'airfryer' => 5] as $group => $count) {
            $ids = $library->kitchenIds($group);
            $this->assertCount($count, $ids);
            $previous = null;
            foreach ($ids as $id) {
                $this->assertSame($id, $library->nextKitchenId($group, $previous));
                foreach (['meat', 'fish'] as $type) {
                    $context = ['product_type' => $type, 'product_name' => 'Voorbeeldproduct', 'variant_groups' => [$group], 'kitchen_references' => [$group => $id]];
                    $plan = (new ProductImagePromptBuilder)->plans($context)[0];
                    $this->assertSame($id, $plan['style_reference_id']);
                    $prompt = (new ProductImagePromptBuilder)->prompt('', $context, $plan);
                    $this->assertStringContainsString('Neem geen apparaatmerken', $prompt);
                    $this->assertStringContainsString('nooit dubbel', $prompt);
                }
                $previous = $id;
            }
            $this->assertSame($ids[0], $library->nextKitchenId($group, $previous));
            foreach (['../../.env', 'vis_rauw_zwart', [], null] as $invalid) {
                $this->assertSame('keuken_'.$group, $library->kitchenId($group, $invalid));
            }
        }
        $this->assertNull($library->nextKitchenId('raw', null));
        $this->assertSame('keuken_pan', $library->kitchenId('pan', 'keuken_oven_02'));
    }

    public function test_requests_persist_rotation_per_user_and_group_without_changing_old_requests(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = $this->actingAsUser();
        $send = function (array $groups) {
            $response = $this->post('/api/images/generate', [
                'foto' => UploadedFile::fake()->image('test.png'), 'product_type' => 'fish',
                'variant_groups' => $groups, 'kitchen_references' => ['pan' => '../../.env'],
            ], ['Accept' => 'application/json'])->assertAccepted();

            return ProductImageRequest::findOrFail($response->json('request_id'));
        };
        $first = $send(['pan', 'oven', 'airfryer']);
        $this->travel(2)->minutes();
        $send(['raw']);
        $this->travel(2)->minutes();
        $second = $send(['pan']);
        $this->assertSame(['pan' => 'keuken_pan_warm'], $second->generation_context['kitchen_references']);
        $this->assertSame(2, $second->generation_context['kitchen_style_version']);
        $this->travel(2)->minutes();
        $third = $send(['oven', 'airfryer']);
        $this->assertSame(['oven' => 'keuken_oven_warm', 'airfryer' => 'keuken_airfryer_warm'], $third->generation_context['kitchen_references']);
        $this->assertSame('keuken_pan', $first->fresh()->generation_context['kitchen_references']['pan']);
        $this->actingAsUser();
        $this->travel(2)->minutes();
        $other = $send(['pan']);
        $this->assertNotSame($user->id, $other->user_id);
        $this->assertSame('keuken_pan', $other->generation_context['kitchen_references']['pan']);
    }

    public function test_new_styles_have_distinct_prompts_complete_assets_and_legacy_compatibility(): void
    {
        $library = new ProductImageStyleLibrary;
        foreach (['pan', 'oven', 'airfryer'] as $group) {
            $environments = [];
            foreach ($library->kitchenIds($group) as $id) {
                $reference = $library->reference($id);
                $this->assertFileExists($reference['path']);
                $dimensions = getimagesize($reference['path']);
                $this->assertSame(IMAGETYPE_PNG, $dimensions[2]);
                $environments[] = KitchenProductImageStyles::environment($id);
                if ($id !== 'keuken_'.$group) {
                    $this->assertSame([1448, 1086], array_slice($dimensions, 0, 2));
                }
                $context = ['product_type' => 'meat', 'product_name' => 'TEST varkenswangen',
                    'variant_groups' => [$group], 'kitchen_references' => [$group => $id]];
                $plan = (new ProductImagePromptBuilder)->plans($context)[0];
                $this->assertSame('keuken_'.$group.'_stoof', $plan['style_id']);
                $this->assertStringContainsString(KitchenProductImageStyles::label($id), $plan['label']);
                $this->assertStringContainsString('zachte gestoofde structuur en jus', $plan['instruction']);
                if (str_ends_with($id, '_donker')) {
                    $this->assertStringContainsString('diepblauwe matte kastjes', $plan['style']);
                    $this->assertStringNotContainsString('Een lichte moderne woonkeuken', $plan['style']);
                }
                if (str_ends_with($id, '_mediterraan')) {
                    $this->assertStringContainsString('licht keramisch bord', $plan['style']);
                    $this->assertStringContainsString('Geen tweede portie', $plan['style']);
                }
            }
            $this->assertCount(5, array_unique($environments));
            foreach (range(2, $group === 'oven' ? 5 : 4) as $number) {
                $legacy = 'keuken_'.$group.'_0'.$number;
                $this->assertSame($legacy, $library->kitchenId($group, $legacy));
                $this->assertFileExists($library->reference($legacy)['path']);
                $this->assertSame('keuken_'.$group.'_warm', $library->nextKitchenId($group, $legacy));
            }
        }
    }

    public function test_all_new_reference_files_reach_the_image_provider_for_meat_and_fish(): void
    {
        config(['services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-key']);
        Http::preventStrayRequests();
        $source = UploadedFile::fake()->image('test.png', 20, 20);
        $encoded = base64_encode(ProductImageFormat::placeholder());
        Http::fake(['*' => Http::response(['data' => [['b64_json' => $encoded]]])]);
        foreach (['meat', 'fish'] as $type) {
            foreach (['pan', 'oven', 'airfryer'] as $group) {
                foreach (array_slice((new ProductImageStyleLibrary)->kitchenIds($group), 1) as $id) {
                    app(OpenAiProductImageGenerator::class)->generateForProduct([$source], '', [
                        'product_type' => $type, 'product_name' => 'TEST product', 'quantity' => 1,
                        'variant_groups' => [$group], 'kitchen_references' => [$group => $id],
                    ]);
                    $sent = Http::recorded()->last()[0];
                    $parts = collect($sent->data());
                    $files = $parts->filter(fn ($part) => isset($part['filename']))->pluck('filename')->all();
                    $this->assertSame(['product-reference-1.png', 'style-'.str_replace('_', '-', $id).'.png'], $files);
                    $this->assertStringContainsString(KitchenProductImageStyles::environment($id), $parts->firstWhere('name', 'prompt')['contents']);
                }
            }
        }
        Http::assertSentCount(24);
    }

    public function test_rotated_reference_is_sent_even_with_an_approved_product_anchor(): void
    {
        config(['services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-key']);
        $source = UploadedFile::fake()->image('product.png', 20, 20);
        $encoded = base64_encode(ProductImageFormat::placeholder());
        $anchor = ProductImageStyleReference::create([
            'product_name' => 'Voorbeeldvis', 'product_key' => ProductImageStyleReference::productKey('Voorbeeldvis'),
            'product_type' => 'fish', 'status' => 'bereid', 'style_id' => 'keuken_airfryer_vis',
            'source_version' => 1, 'mime_type' => 'image/png', 'contents_base64' => $encoded,
        ]);
        Http::fake(['*' => Http::response(['data' => [['b64_json' => $encoded]]])]);
        $context = ['product_type' => 'fish', 'product_name' => 'Voorbeeldvis', 'variant_groups' => ['airfryer'],
            'kitchen_references' => ['airfryer' => 'keuken_airfryer_04'], 'quantity' => 1];
        app(OpenAiProductImageGenerator::class)->generateForProduct([$source], '', $context);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($anchor) {
            $parts = collect($request->data());
            $prompt = $parts->firstWhere('name', 'prompt')['contents'];
            $files = $parts->filter(fn ($part) => isset($part['filename']))->pluck('filename')->all();

            return $files === ['product-reference-1.png', 'approved-'.$anchor->id.'.png', 'style-keuken-airfryer-04.png']
                && str_contains($prompt, 'geopende airfryermand')
                && str_contains($prompt, 'Neem de achtergrond daarvan niet over');
        });
    }
}

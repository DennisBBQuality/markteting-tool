<?php

namespace Tests\Feature;

use App\Models\ProductImageRequest;
use App\Models\ProductImageStyleReference;
use App\Services\OpenAiProductImageGenerator;
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
        foreach (['pan' => 4, 'oven' => 5, 'airfryer' => 4] as $group => $count) {
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
        $this->assertSame(['pan' => 'keuken_pan_02'], $second->generation_context['kitchen_references']);
        $this->travel(2)->minutes();
        $third = $send(['oven', 'airfryer']);
        $this->assertSame(['oven' => 'keuken_oven_02', 'airfryer' => 'keuken_airfryer_02'], $third->generation_context['kitchen_references']);
        $this->assertSame('keuken_pan', $first->fresh()->generation_context['kitchen_references']['pan']);
        $this->actingAsUser();
        $this->travel(2)->minutes();
        $other = $send(['pan']);
        $this->assertNotSame($user->id, $other->user_id);
        $this->assertSame('keuken_pan', $other->generation_context['kitchen_references']['pan']);
    }

    public function test_rotated_reference_is_sent_even_with_an_approved_product_anchor(): void
    {
        config(['services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-key']);
        $source = UploadedFile::fake()->image('product.png', 20, 20);
        $encoded = base64_encode(file_get_contents($source->getRealPath()));
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

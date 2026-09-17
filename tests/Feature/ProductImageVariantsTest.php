<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductImages;
use App\Jobs\GenerateProductImageSeo;
use App\Models\ProductImageRequest;
use App\Services\FakeProductImageGenerator;
use App\Services\OpenAiProductImageGenerator;
use App\Services\ProductImagePromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductImageVariantsTest extends TestCase
{
    use RefreshDatabase;

    public static function selections(): array
    {
        return [
            [['pan'], 1], [['raw'], 2], [['pan', 'oven', 'airfryer'], 3],
            [['raw', 'bbq'], 4], [['raw', 'bbq', 'pan'], 5],
            [['raw', 'bbq', 'oven', 'airfryer'], 6], [['raw', 'bbq', 'pan', 'oven', 'airfryer'], 7],
        ];
    }

    #[DataProvider('selections')]
    public function test_selected_count_is_saved_generated_and_gets_seo(array $groups, int $count): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAsUser(['rol' => 'lid']);
        $response = $this->post('/api/images/generate', ['foto' => UploadedFile::fake()->image('product.png'),
            'product_type' => 'fish', 'product_name' => 'Testvis', 'variant_groups' => $groups], ['Accept' => 'application/json']);
        $id = $response->assertAccepted()->assertJsonPath('expected_count', $count)->json('request_id');
        $this->assertSame($groups, ProductImageRequest::find($id)->generation_context['variant_groups']);
        (new GenerateProductImages($id))->handle(new FakeProductImageGenerator);
        $this->getJson('/api/images/requests/'.$id)->assertOk()->assertJsonPath('status', 'completed')->assertJsonCount($count, 'results');
        Queue::assertPushed(GenerateProductImageSeo::class, $count);
    }

    public static function invalidSelections(): array
    {
        return [[[]], [['raw', 'raw']], [['unknown']], ['pan'], [null], [[1]], [['pan'], 'sauce'], [['bbq'], 'bundle']];
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_selection_never_queues_or_stores_files(mixed $groups, string $type = 'meat'): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAsUser();
        $this->post('/api/images/generate', ['foto' => UploadedFile::fake()->image('product.png'),
            'product_type' => $type, 'variant_groups' => $groups], ['Accept' => 'application/json'])->assertUnprocessable();
        Queue::assertNothingPushed();
        $this->assertSame(0, ProductImageRequest::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_all_combinations_preserve_counts_existing_scenes_and_product_rules(): void
    {
        $builder = new ProductImagePromptBuilder;
        $keys = ['raw', 'bbq', 'pan', 'oven', 'airfryer'];
        foreach (['meat', 'fish'] as $type) {
            $base = ['product_type' => $type, 'product_name' => 'Testproduct', 'quantity' => 2];
            $legacy = $builder->plans($base);
            for ($mask = 1; $mask < 32; $mask++) {
                $selected = array_values(array_filter($keys, fn ($key) => $mask & (1 << array_search($key, $keys))));
                $context = [...$base, 'variant_groups' => $selected];
                $plans = $builder->plans($context);
                $count = array_sum(array_map(fn ($key) => in_array($key, ['raw', 'bbq']) ? 2 : 1, $selected));
                $this->assertCount($count, $plans);
                $this->assertCount(in_array('raw', $selected) ? 2 : 0, array_filter($plans, fn ($p) => $p['status'] === 'rauw'));
                foreach ($plans as $plan) {
                    if (! isset($plan['kitchen_variant'])) {
                        $this->assertContains($plan, $legacy);
                    } else {
                        $prompt = $builder->prompt('', $context, $plan);
                        $this->assertStringContainsString('KEUKENSTIJLVOORBEELD', $prompt);
                        $this->assertStringContainsString('Neem nooit het voorbeeldgerecht', $prompt);
                        $this->assertSame('keuken_'.$plan['kitchen_variant'], $plan['style_reference_id']);
                        if ($type === 'fish') {
                            $this->assertStringContainsString('natuurlijk en stabiel plat', $prompt);
                        }
                    }
                }
            }
        }
        foreach (['pan', 'oven', 'airfryer'] as $group) {
            $context = ['product_type' => 'meat', 'product_name' => 'Kalfssucade', 'variant_groups' => [$group]];
            $plan = $builder->plans($context)[0];
            $this->assertSame('stoof', $plan['preparation']);
            $this->assertStringContainsString('GEEN SNIJPLAKKEN', $builder->prompt('', $context, $plan));
        }
    }

    public function test_only_requested_kitchen_reference_is_sent_to_provider(): void
    {
        config(['services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-key']);
        $source = UploadedFile::fake()->image('vis.png', 20, 20);
        $encoded = base64_encode(file_get_contents($source->getRealPath()));
        Http::fake(['*' => Http::response(['data' => [['b64_json' => $encoded]]])]);
        $results = app(OpenAiProductImageGenerator::class)->generateForProduct([$source], '', [
            'product_type' => 'fish', 'product_name' => 'Testvis', 'variant_groups' => ['airfryer'], 'quantity' => 1,
        ]);
        $this->assertCount(1, $results);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $parts = collect($request->data());
            $prompt = $parts->firstWhere('name', 'prompt')['contents'];
            $files = $parts->filter(fn ($part) => isset($part['filename']))->pluck('filename')->all();

            return $files === ['product-reference-1.png', 'style-keuken-airfryer.png']
                && str_contains($prompt, 'KEUKENSTIJLVOORBEELD') && ! str_contains($prompt, 'bestaande BBQuality-sfeervoorbeeld');
        });
    }

    public function test_seven_photos_are_not_marked_stalled_after_twelve_minutes(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = $this->actingAsUser();
        $job = ProductImageRequest::create(['user_id' => $user->id, 'status' => 'processing', 'source_path' => 'test.png',
            'prompt' => 'test', 'generation_context' => ['product_type' => 'meat', 'variant_groups' => ['raw', 'bbq', 'pan', 'oven', 'airfryer'], 'photo_count' => 7]]);
        ProductImageRequest::whereKey($job->id)->update(['updated_at' => now()->subMinutes(16)]);
        $this->getJson('/api/images/requests/'.$job->id)->assertJsonPath('status', 'processing');
        ProductImageRequest::whereKey($job->id)->update(['updated_at' => now()->subMinutes(22)]);
        $this->getJson('/api/images/requests/'.$job->id)->assertJsonPath('status', 'failed');
        ProductImageRequest::whereKey($job->id)->update(['status' => 'queued', 'created_at' => now()->subMinutes(25), 'updated_at' => now()]);
        $this->getJson('/api/images/requests/'.$job->id)->assertJsonPath('status', 'queued');
        ProductImageRequest::whereKey($job->id)->update(['created_at' => now()->subMinutes(31)]);
        $this->getJson('/api/images/requests/'.$job->id)->assertJsonPath('status', 'failed');
    }
}

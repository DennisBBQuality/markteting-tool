<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductImages;
use App\Jobs\RefineProductImage;
use App\Models\ImagePrompt;
use App\Models\ProductImageAsset;
use App\Models\ProductImageRequest;
use App\Services\ProductImageGenerationException;
use App\Services\ProductImageGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_image_endpoints_require_authentication(): void
    {
        $this->getJson('/api/images/prompt')->assertUnauthorized();
        $this->putJson('/api/images/prompt', ['prompt' => str_repeat('a', 20)])->assertUnauthorized();
        $this->postJson('/api/images/generate')->assertUnauthorized();
    }

    public function test_prompt_can_be_read_and_updated(): void
    {
        $user = $this->actingAsUser();

        $this->getJson('/api/images/prompt')
            ->assertOk()
            ->assertJsonPath('prompt', ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT)
            ->assertJsonPath('voorbeeldmodus', true);

        $newPrompt = 'Maak een rustige, luxe productfoto met realistische structuren en neutraal licht.';

        $this->putJson('/api/images/prompt', ['prompt' => $newPrompt])
            ->assertOk()
            ->assertJsonPath('prompt', $newPrompt);

        $this->assertDatabaseHas('image_prompts', [
            'naam' => ImagePrompt::PRODUCT_PHOTO,
            'prompt' => $newPrompt,
            'bijgewerkt_door' => $user->id,
        ]);
    }

    public function test_prompt_validation_rejects_an_empty_or_excessive_prompt(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/images/prompt', ['prompt' => 'te kort'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prompt');

        $this->putJson('/api/images/prompt', ['prompt' => str_repeat('a', 6001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prompt');
    }

    public function test_generation_accepts_only_safe_supported_images(): void
    {
        Storage::fake('local');
        $this->actingAsUser();

        $this->post('/api/images/generate', [
            'foto' => UploadedFile::fake()->createWithContent('product.svg', '<svg><script>alert(1)</script></svg>'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('foto');

        Storage::disk('local')->assertDirectoryEmpty('product-images');
    }

    public function test_generation_is_queued_instead_of_waiting_for_openai(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsUser();

        $response = $this->post('/api/images/generate', [
            'foto' => UploadedFile::fake()->image('ribeye-in-verpakking.jpg', 1200, 800),
        ], ['Accept' => 'application/json']);

        $response
            ->assertAccepted()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('progress', 5)
            ->assertJsonPath('progress_step', 'queued')
            ->assertJsonPath('progress_label', 'Opdracht ontvangen')
            ->assertJsonCount(0, 'results');

        $imageRequest = ProductImageRequest::findOrFail($response->json('request_id'));
        Storage::disk('local')->assertExists($imageRequest->source_path);
        Queue::assertPushed(GenerateProductImages::class, fn ($job) => $job->requestId === $imageRequest->id
            && $job->connection === 'deferred'
            && $job->queue === 'images'
        );
    }

    public function test_background_job_returns_exactly_two_prepared_and_two_raw_private_images(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsUser();

        $queued = $this->post('/api/images/generate', [
            'foto' => UploadedFile::fake()->image('ribeye-in-verpakking.jpg', 1200, 800),
        ], ['Accept' => 'application/json'])->assertAccepted();

        $imageRequest = ProductImageRequest::findOrFail($queued->json('request_id'));
        (new GenerateProductImages($imageRequest->id))->handle(app(ProductImageGenerator::class));
        $response = $this->getJson('/api/images/requests/'.$imageRequest->id);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('progress', 100)
            ->assertJsonPath('progress_step', 'completed')
            ->assertJsonCount(4, 'results')
            ->assertJsonPath('results.0.label', 'Vlees bereid')
            ->assertJsonPath('results.1.label', 'Vlees bereid')
            ->assertJsonPath('results.2.label', 'Vlees rauw')
            ->assertJsonPath('results.3.label', 'Vlees rauw');

        Storage::disk('local')->assertMissing($imageRequest->source_path);

        foreach ($response->json('results') as $result) {
            $asset = basename(parse_url($result['url'], PHP_URL_PATH));
            $this->assertStringNotContainsString('.', $asset);
            $filename = $asset.'.png';
            $this->assertDatabaseHas('product_image_assets', [
                'product_image_request_id' => $imageRequest->id,
                'filename' => $filename,
                'mime_type' => 'image/png',
            ]);

            $this->get($result['url'])
                ->assertOk()
                ->assertHeader('Content-Type', 'image/png')
                ->assertHeader('X-Content-Type-Options', 'nosniff');

            $this->get($result['download_url'])
                ->assertOk()
                ->assertDownload($filename);
        }
    }

    public function test_generated_images_remain_available_when_local_result_storage_is_empty(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsUser();

        $queued = $this->post('/api/images/generate', [
            'foto' => UploadedFile::fake()->image('brisket.jpg', 1200, 800),
        ], ['Accept' => 'application/json'])->assertAccepted();

        $imageRequest = ProductImageRequest::findOrFail($queued->json('request_id'));
        (new GenerateProductImages($imageRequest->id))->handle(app(ProductImageGenerator::class));
        Storage::disk('local')->assertDirectoryEmpty('product-images');

        $result = $this->getJson('/api/images/requests/'.$imageRequest->id)
            ->assertOk()
            ->json('results.0');

        $this->get($result['url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $filename = basename(parse_url($result['url'], PHP_URL_PATH)).'.png';
        $this->assertNotEmpty(ProductImageAsset::where('filename', $filename)->value('contents_base64'));
    }

    public function test_multiple_references_and_sauce_workflow_return_two_distinct_styles(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsUser();

        $queued = $this->post('/api/images/generate', [
            'fotos' => [
                UploadedFile::fake()->image('voorkant.jpg', 800, 1000),
                UploadedFile::fake()->image('achterkant.jpg', 800, 1000),
            ],
            'main_index' => 1,
            'product_type' => 'sauce',
            'product_name' => 'BBQuality The Original',
            'quantity' => 1,
        ], ['Accept' => 'application/json'])->assertAccepted();

        $imageRequest = ProductImageRequest::findOrFail($queued->json('request_id'));
        $this->assertCount(2, $imageRequest->source_references);
        $this->assertSame('sauce', $imageRequest->generation_context['product_type']);
        $this->assertTrue($imageRequest->source_references[0]['is_main']);

        (new GenerateProductImages($imageRequest->id))->handle(app(ProductImageGenerator::class));
        $results = $this->getJson('/api/images/requests/'.$imageRequest->id)
            ->assertOk()
            ->assertJsonCount(2, 'results')
            ->assertJsonPath('results.0.needs_label_review', true)
            ->json('results');

        $this->assertSame(['bbquality_buiten', 'bbquality_donker'], array_column($results, 'style_id'));
        foreach ($imageRequest->source_references as $reference) {
            Storage::disk('local')->assertMissing($reference['path']);
        }
    }

    public function test_no_more_than_five_reference_photos_are_accepted(): void
    {
        Storage::fake('local');
        $this->actingAsUser();

        $this->post('/api/images/generate', [
            'fotos' => collect(range(1, 6))->map(fn ($number) => UploadedFile::fake()->image("foto-{$number}.jpg"))->all(),
            'product_type' => 'meat',
            'product_name' => 'Kogelbiefstuk',
            'quantity' => 1,
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fotos');
    }

    public function test_only_the_selected_image_is_refined_and_old_version_is_kept(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsUser();

        $queued = $this->post('/api/images/generate', [
            'foto' => UploadedFile::fake()->image('steak.jpg'),
            'product_type' => 'meat',
            'product_name' => 'Angus steak',
            'quantity' => 1,
        ], ['Accept' => 'application/json'])->assertAccepted();
        $imageRequest = ProductImageRequest::findOrFail($queued->json('request_id'));
        (new GenerateProductImages($imageRequest->id))->handle(app(ProductImageGenerator::class));

        $assets = ProductImageAsset::where('product_image_request_id', $imageRequest->id)->orderBy('id')->get();
        $selected = $assets[1];
        $untouched = $assets[0];

        $this->postJson("/api/images/requests/{$imageRequest->id}/assets/{$selected->id}/refine", [
            'instruction' => 'Maak de korst krokanter en de kern medium.',
        ])->assertAccepted();
        Queue::assertPushed(RefineProductImage::class, fn ($job) => $job->assetId === $selected->id);

        (new RefineProductImage($imageRequest->id, $selected->id, 'Maak de korst krokanter en de kern medium.'))
            ->handle(app(\App\Services\ProductImageRefiner::class));

        $this->assertSame(2, $selected->refresh()->version);
        $this->assertSame(1, $selected->revisions()->where('version', 1)->count());
        $this->assertSame(1, $untouched->refresh()->version);
        $this->assertSame(0, $untouched->revisions()->count());
    }

    public function test_incomplete_generator_output_is_rejected_without_storing_files(): void
    {
        Storage::fake('local');
        $user = $this->actingAsUser();
        $this->app->instance(ProductImageGenerator::class, new class implements ProductImageGenerator
        {
            public function generate(UploadedFile $source, string $basePrompt, ?callable $reportProgress = null): array
            {
                return [
                    ['status' => 'bereid', 'contents' => 'image', 'extension' => 'png'],
                ];
            }
        });

        $request = $this->storedRequest($user->id);
        $this->expectException(RuntimeException::class);

        try {
            (new GenerateProductImages($request->id))->handle(app(ProductImageGenerator::class));
        } finally {
            Storage::disk('local')->assertDirectoryEmpty('product-images');
        }
    }

    public function test_invalid_generated_binary_data_is_never_served_as_an_image(): void
    {
        Storage::fake('local');
        $user = $this->actingAsUser();
        $this->app->instance(ProductImageGenerator::class, new class implements ProductImageGenerator
        {
            public function generate(UploadedFile $source, string $basePrompt, ?callable $reportProgress = null): array
            {
                return [
                    ['status' => 'bereid', 'contents' => '<script>alert(1)</script>', 'extension' => 'png'],
                    ['status' => 'bereid', 'contents' => '<script>alert(2)</script>', 'extension' => 'png'],
                    ['status' => 'rauw', 'contents' => '<script>alert(3)</script>', 'extension' => 'png'],
                    ['status' => 'rauw', 'contents' => '<script>alert(4)</script>', 'extension' => 'png'],
                ];
            }
        });

        $request = $this->storedRequest($user->id);
        $this->expectException(RuntimeException::class);

        try {
            (new GenerateProductImages($request->id))->handle(app(ProductImageGenerator::class));
        } finally {
            Storage::disk('local')->assertDirectoryEmpty('product-images');
        }
    }

    public function test_generation_service_failures_do_not_leave_files_behind(): void
    {
        Storage::fake('local');
        $user = $this->actingAsUser();
        $this->app->instance(ProductImageGenerator::class, new class implements ProductImageGenerator
        {
            public function generate(UploadedFile $source, string $basePrompt, ?callable $reportProgress = null): array
            {
                throw new ProductImageGenerationException('De beeldservice is momenteel niet bereikbaar.');
            }
        });

        $request = $this->storedRequest($user->id);
        $job = new GenerateProductImages($request->id);

        try {
            $job->handle(app(ProductImageGenerator::class));
            $this->fail('De generatorfout had doorgegeven moeten worden aan de queue.');
        } catch (ProductImageGenerationException $exception) {
            $job->failed($exception);
        }

        $this->assertSame('failed', $request->refresh()->status);
        $this->assertSame('failed', $request->progress_step);
        $this->assertSame('De beeldservice is momenteel niet bereikbaar.', $request->error);
        Storage::disk('local')->assertMissing($request->source_path);
    }

    public function test_a_request_that_never_started_is_stopped_with_a_clear_retry_message(): void
    {
        Storage::fake('local');
        $user = $this->actingAsUser();
        $request = $this->storedRequest($user->id);
        $request->forceFill([
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ])->save();

        $this->getJson('/api/images/requests/'.$request->id)
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('progress_step', 'failed')
            ->assertJsonPath('progress_label', 'Opdracht gestopt')
            ->assertJsonPath('error', 'De achtergrondtaak kon niet starten. Probeer de opdracht opnieuw.');

        Storage::disk('local')->assertMissing($request->source_path);
    }

    private function storedRequest(string $userId): ProductImageRequest
    {
        $file = UploadedFile::fake()->image('product.jpg');
        $path = $file->storeAs('product-image-inputs', Str::uuid().'.jpg', 'local');

        return ProductImageRequest::create([
            'user_id' => $userId,
            'status' => 'queued',
            'source_path' => $path,
            'prompt' => ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT,
        ]);
    }
}

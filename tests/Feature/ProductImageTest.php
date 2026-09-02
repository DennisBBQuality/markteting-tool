<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductImages;
use App\Models\ImagePrompt;
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
            $filename = basename(parse_url($result['url'], PHP_URL_PATH));
            Storage::disk('local')->assertExists('product-images/'.$imageRequest->id.'/'.$filename);

            $this->get($result['url'])
                ->assertOk()
                ->assertHeader('Content-Type', 'image/png')
                ->assertHeader('X-Content-Type-Options', 'nosniff');

            $this->get($result['download_url'])
                ->assertOk()
                ->assertDownload($filename);
        }
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
        $this->assertNotNull($request->error);
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

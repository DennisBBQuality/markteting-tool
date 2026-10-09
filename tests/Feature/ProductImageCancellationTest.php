<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductImages;
use App\Jobs\GenerateProductImageSeo;
use App\Models\ProductImageAsset;
use App\Models\ProductImageMetadata;
use App\Models\ProductImageRequest;
use App\Services\FakeProductImageGenerator;
use App\Services\OpenAiProductImageGenerator;
use App\Services\ProductImageCancellation;
use App\Services\ProductImageFormat;
use App\Services\ProductImageSeo;
use App\Services\ProductImageSeoAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageCancellationTest extends TestCase
{
    use RefreshDatabase;

    private function request(): ProductImageRequest
    {
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        $this->actingAsUser(['rol' => 'lid']);
        $id = $this->post('/api/images/generate', [
            'foto' => UploadedFile::fake()->image('test.png', 30, 30),
            'product_type' => 'meat', 'product_name' => 'TEST product',
            'variant_groups' => ['raw', 'bbq', 'pan', 'oven', 'airfryer'],
        ], ['Accept' => 'application/json'])->assertAccepted()->json('request_id');

        return ProductImageRequest::findOrFail($id);
    }

    public function test_queued_stop_is_idempotent_and_never_starts_provider(): void
    {
        $request = $this->request();
        $url = "/api/images/requests/{$request->id}/cancel";
        $this->postJson($url)->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('can_cancel', false);
        $this->postJson($url)->assertOk()->assertJsonPath('status', 'cancelled');
        Storage::disk('local')->assertMissing($request->source_path);
        (new GenerateProductImages($request->id))->handle(app(OpenAiProductImageGenerator::class));
        Http::assertNothingSent();
        $this->assertDatabaseCount('product_image_assets', 0);
        $this->getJson("/api/images/requests/{$request->id}")->assertJsonPath('status', 'cancelled');
    }

    public function test_stop_during_first_provider_pair_keeps_two_photos_and_skips_next_five_and_seo(): void
    {
        $request = $this->request();
        config(['services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-only']);
        $png = ProductImageFormat::placeholder();
        $calls = 0;
        Http::fake(function () use ($request, $png, &$calls) {
            $calls++;
            if ($calls === 1) {
                $this->postJson("/api/images/requests/{$request->id}/cancel")->assertOk()->assertJsonPath('status', 'cancelling');
            }

            return Http::response(['data' => [['b64_json' => base64_encode($png)]]]);
        });
        (new GenerateProductImages($request->id))->handle(app(OpenAiProductImageGenerator::class));
        Http::assertSentCount(2);
        $this->getJson("/api/images/requests/{$request->id}")->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonCount(2, 'results');
        $this->assertDatabaseCount('product_image_assets', 2);
        $this->assertDatabaseCount('product_image_metadata', 0);
        Queue::assertNotPushed(GenerateProductImageSeo::class);
        $result = $request->fresh()->results[0];
        $this->get("/api/images/requests/{$request->id}/generated/".pathinfo($result['filename'], PATHINFO_FILENAME).'?download=1&format=webp')
            ->assertOk()->assertHeader('Content-Type', 'image/webp');
        (new GenerateProductImages($request->id))->failed(new \RuntimeException('late worker failure'));
        $this->assertSame('cancelled', $request->fresh()->status);
    }

    public function test_set_stop_invalidates_pending_seo_tokens_and_preserves_completed_fields(): void
    {
        $request = $this->request();
        (new GenerateProductImages($request->id))->handle(new FakeProductImageGenerator);
        $assets = ProductImageAsset::where('product_image_request_id', $request->id)->get();
        $complete = ProductImageMetadata::where('product_image_asset_id', $assets[0]->id)->firstOrFail();
        $complete->update(['fields' => ['alt' => 'TEST bewaarde tekst'], 'source' => 'manual', 'status' => 'completed', 'job_token' => null]);
        $row = ProductImageMetadata::where('product_image_asset_id', $assets[1]->id)->firstOrFail();
        $job = new GenerateProductImageSeo($assets[1]->id, 1, $row->job_token);
        $this->assertNotNull($job->prepare());
        $this->postJson("/api/images/requests/{$request->id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $job->complete(['filename' => 'late.webp']);
        $job->failed(null);
        $this->assertSame('cancelled', $row->fresh()->status);
        $this->assertNull($row->fresh()->job_token);
        $this->assertSame(['alt' => 'TEST bewaarde tekst'], $complete->fresh()->fields);
        $this->assertSame('completed', $complete->fresh()->status);
        $this->assertSame([], app(ProductImageSeo::class)->prepareAutomaticJobs($assets));
        Http::assertNothingSent();
    }

    public function test_individual_seo_stop_does_not_stop_other_photos_and_can_be_restarted_explicitly(): void
    {
        $request = $this->request();
        (new GenerateProductImages($request->id))->handle(new FakeProductImageGenerator);
        $assets = ProductImageAsset::where('product_image_request_id', $request->id)->get();
        $asset = $assets[0];
        $row = ProductImageMetadata::where('product_image_asset_id', $asset->id)->firstOrFail();
        $oldJob = new GenerateProductImageSeo($asset->id, 1, $row->job_token);
        $url = "/api/images/requests/{$request->id}/assets/{$asset->id}/seo";
        $this->postJson($url.'/cancel', ['image_version' => 2, 'revision' => 0])->assertConflict();
        $this->postJson($url.'/cancel', ['image_version' => 1, 'revision' => 9])->assertConflict();
        $this->postJson($url.'/cancel', ['image_version' => 1, 'revision' => 0])->assertOk()->assertJsonPath('seo.status', 'cancelled')->assertJsonPath('seo.revision', 1);
        $this->postJson($url.'/cancel', ['image_version' => 1, 'revision' => 0])->assertOk();
        $this->assertSame('queued', ProductImageMetadata::where('product_image_asset_id', $assets[1]->id)->firstOrFail()->status);
        $oldJob->handle(app(ProductImageSeoAnalyzer::class));
        Http::assertNothingSent();
        $this->postJson($url.'/generate', ['image_version' => 1, 'revision' => 1])->assertAccepted()->assertJsonPath('seo.status', 'queued');
        $newToken = $row->fresh()->job_token;
        $oldJob->complete(['filename' => 'late.webp']);
        $this->assertSame($newToken, $row->fresh()->job_token);
    }

    public function test_only_owner_can_stop_and_completed_work_is_not_cancelled(): void
    {
        $request = $this->request();
        (new GenerateProductImages($request->id))->handle(new FakeProductImageGenerator);
        $asset = ProductImageAsset::where('product_image_request_id', $request->id)->firstOrFail();
        $this->actingAsUser();
        $this->postJson("/api/images/requests/{$request->id}/cancel")->assertNotFound();
        $this->postJson("/api/images/requests/{$request->id}/assets/{$asset->id}/seo/cancel", ['image_version' => 1, 'revision' => 0])->assertNotFound();
        $this->flushSession();
        $this->postJson("/api/images/requests/{$request->id}/cancel")->assertUnauthorized();
        ProductImageMetadata::query()->update(['status' => 'completed', 'job_token' => null]);
        app(ProductImageCancellation::class)->stop($request);
        $this->assertSame('completed', $request->fresh()->status);
    }

    public function test_stopping_first_seo_wave_prevents_later_waves_and_late_answers(): void
    {
        $request = $this->request();
        (new GenerateProductImages($request->id))->handle(new FakeProductImageGenerator);
        $jobs = ProductImageMetadata::all()->mapWithKeys(fn ($row) => [$row->product_image_asset_id => new GenerateProductImageSeo($row->product_image_asset_id, 1, $row->job_token)])->all();
        config(['services.product_images.queue_connection' => 'deferred', 'services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-only']);
        $calls = 0;
        Http::fake(function () use ($request, &$calls) {
            if (++$calls === 1) {
                app(ProductImageCancellation::class)->stop($request);
            }

            return Http::response(['output_text' => json_encode(['filename' => 'test-product.webp', 'alt' => 'TEST', 'title' => 'TEST', 'caption' => 'TEST', 'description' => 'TEST'])]);
        });
        app(ProductImageSeo::class)->runAutomaticJobs($jobs);
        Http::assertSentCount(3);
        $this->assertSame(7, ProductImageMetadata::where('status', 'cancelled')->whereNull('job_token')->count());
        $this->assertDatabaseCount('product_image_assets', 7);
    }
}

<?php

namespace Tests\Feature;

use App\Models\ProductImageAsset;
use App\Models\ProductImageRequest;
use App\Services\ProductImageDelivery;
use App\Services\ProductImageFormat;
use App\Services\ProductImageSeo;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function photo(): array
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $user = $this->actingAsUser();
        $request = ProductImageRequest::create([
            'user_id' => $user->id, 'status' => 'completed', 'progress' => 100,
            'source_path' => 'test/source.png', 'prompt' => 'TEST',
            'generation_context' => ['product_type' => 'accessory', 'product_name' => 'Testproduct'],
            'results' => [['filename' => 'test.png', 'status' => 'rauw', 'label' => 'Test', 'variant' => 1]],
        ]);
        $asset = ProductImageAsset::create([
            'product_image_request_id' => $request->id, 'filename' => 'test.png',
            'version' => 1, 'refinement_status' => 'idle', 'mime_type' => 'image/png',
            'contents_base64' => base64_encode(ProductImageFormat::placeholder()),
        ]);

        return [$request, $asset];
    }

    private function saveSeo(ProductImageAsset $asset): void
    {
        app(ProductImageSeo::class)->save($asset, [
            'filename' => 'testproduct-op-tafel.webp', 'alt' => 'Testproduct op tafel',
            'title' => 'Testproduct', 'caption' => '', 'description' => 'Testproduct op tafel.',
        ], 0);
    }

    public function test_existing_images_ignore_old_lossless_cache_and_keep_source_and_seo(): void
    {
        [$request, $asset] = $this->photo();
        $this->saveSeo($asset);
        $source = base64_decode($asset->contents_base64);
        $oldPath = 'product-images/'.$request->id.'/webp/'.hash('sha256', $source).'.webp';
        Storage::disk('local')->put($oldPath, 'OLD LOSSLESS CACHE');
        $payload = $this->getJson('/api/images/requests/'.$request->id)->assertOk()->json('results.0');
        $download = $this->get($payload['download_url'])->assertOk()
            ->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('testproduct-op-tafel.webp')->streamedContent();
        $this->assertSame(app(ProductImageDelivery::class)->webp($source), $download);
        $this->assertSame([1536, 1152], array_slice(getimagesizefromstring($download), 0, 2));
        $this->assertSame($download, $this->get($payload['download_url'])->streamedContent());
        $this->assertSame('OLD LOSSLESS CACHE', Storage::disk('local')->get($oldPath));
        $this->assertSame($source, $this->get($payload['original_download_url'])->assertOk()
            ->assertHeader('Content-Type', 'image/png')->assertDownload('test.png')->getContent());
        $this->assertSame($source, $this->get($payload['url'])->getContent());
        $this->assertSame(base64_encode($source), $asset->fresh()->contents_base64);
        $this->assertSame('testproduct-op-tafel.webp', app(ProductImageSeo::class)->record($asset)->fields['filename']);
    }

    public function test_new_source_version_uses_new_cache_and_a_recovery_name_until_its_own_seo_is_ready(): void
    {
        [$request, $asset] = $this->photo();
        $this->saveSeo($asset);
        $payload = $this->getJson('/api/images/requests/'.$request->id)->json('results.0');
        $first = $this->get($payload['download_url'])->assertOk()->streamedContent();
        $image = imagecreatefromstring(base64_decode($asset->contents_base64));
        imagefilledrectangle($image, 0, 0, 100, 100, imagecolorallocate($image, 200, 0, 0));
        ob_start();
        imagepng($image);
        $updated = ob_get_clean();
        imagedestroy($image);
        $asset->update(['version' => 2, 'contents_base64' => base64_encode($updated)]);
        $payload = $this->getJson('/api/images/requests/'.$request->id)->json('results.0');
        $this->get($payload['download_url'])->assertOk()->assertDownload('foto-zonder-seo-'.$asset->id.'.webp')->assertHeader('X-Image-SEO-Ready', 'false');
        $this->assertNull(app(ProductImageSeo::class)->record($asset));
        $this->saveSeo($asset);
        $second = $this->get($payload['download_url'])->assertOk()->streamedContent();
        $this->assertNotSame($first, $second);
        $this->assertSame(app(ProductImageDelivery::class)->webp($updated), $second);
        $this->assertSame($updated, $this->get($payload['original_download_url'])->getContent());
    }

    public function test_both_downloads_stay_private_but_do_not_require_seo(): void
    {
        [$request] = $this->photo();
        $payload = $this->getJson('/api/images/requests/'.$request->id)->json('results.0');
        $this->get($payload['download_url'])->assertOk()->assertHeader('X-Image-SEO-Ready', 'false');
        $this->get($payload['original_download_url'])->assertOk();
        $this->actingAsUser();
        foreach (['download_url', 'original_download_url'] as $key) {
            $this->getJson($payload[$key])->assertNotFound();
        }
        $this->flushSession();
        foreach (['download_url', 'original_download_url'] as $key) {
            $this->getJson($payload[$key])->assertUnauthorized();
        }
    }

    public function test_cache_write_failure_does_not_return_a_successful_download_or_change_source(): void
    {
        [$request, $asset] = $this->photo();
        $this->saveSeo($asset);
        $source = $asset->contents_base64;
        $payload = $this->getJson('/api/images/requests/'.$request->id)->json('results.0');
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->andReturn(false);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->twice()->andReturn($disk);
        $this->getJson($payload['download_url'])->assertStatus(503)
            ->assertJsonPath('message', 'De WEBP-download kon niet worden opgeslagen. Het origineel is bewaard. Probeer opnieuw.');
        $this->assertSame($source, $asset->fresh()->contents_base64);
    }
}

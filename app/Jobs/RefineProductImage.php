<?php

namespace App\Jobs;

use App\Models\ProductImageAsset;
use App\Models\ProductImageRequest;
use App\Services\ProductImageGenerationException;
use App\Services\ProductImageRefiner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RefineProductImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 600;
    public bool $failOnTimeout = true;

    public function __construct(public string $requestId, public int $assetId, public string $instruction)
    {
        $this->onQueue('images');
    }

    public function handle(ProductImageRefiner $refiner): void
    {
        $request = ProductImageRequest::findOrFail($this->requestId);
        $asset = ProductImageAsset::where('product_image_request_id', $request->id)->findOrFail($this->assetId);
        $asset->update(['refinement_status' => 'processing', 'refinement_error' => null]);

        $current = base64_decode($asset->contents_base64, true);
        if (! is_string($current) || $current === '') {
            throw new RuntimeException('De gekozen productfoto kon niet worden gelezen.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'pitboard-refine-');
        if (! is_string($temporary) || file_put_contents($temporary, $current) === false) {
            throw new RuntimeException('De gekozen productfoto kon niet worden voorbereid.');
        }

        try {
            $source = new UploadedFile($temporary, 'selected.png', 'image/png', null, true);
            $contents = $refiner->refine($source, $this->instruction, $request->generation_context ?? []);
        } finally {
            @unlink($temporary);
        }

        $metadata = @getimagesizefromstring($contents);
        if (! is_array($metadata) || ($metadata['mime'] ?? null) !== 'image/png') {
            throw new ProductImageGenerationException('De aangepaste foto was ongeldig en is daarom niet opgeslagen.');
        }

        DB::transaction(function () use ($asset, $contents): void {
            $asset->revisions()->firstOrCreate(['version' => $asset->version], [
                'instruction' => $asset->last_instruction,
                'mime_type' => $asset->mime_type,
                'contents_base64' => $asset->contents_base64,
            ]);
            $asset->update([
                'contents_base64' => base64_encode($contents),
                'mime_type' => 'image/png',
                'version' => $asset->version + 1,
                'refinement_status' => 'idle',
                'refinement_error' => null,
            ]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        ProductImageAsset::whereKey($this->assetId)->update([
            'refinement_status' => 'idle',
            'refinement_error' => $exception instanceof ProductImageGenerationException
                ? $exception->getMessage()
                : 'Deze foto kon niet worden aangepast. Probeer het later opnieuw.',
        ]);
    }
}

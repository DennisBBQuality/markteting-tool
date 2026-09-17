<?php

namespace App\Jobs;

use App\Models\ProductImageAsset;
use App\Models\ProductImageMetadata;
use App\Models\ProductImageRequest;
use App\Services\ProductImageSeo;
use App\Services\ProductImageSeoAnalyzer;
use App\Services\ProductImageSeoException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateProductImageSeo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public bool $failOnTimeout = true;

    public function __construct(public int $assetId, public int $version, public string $token)
    {
        $this->onQueue('images');
    }

    public function handle(ProductImageSeoAnalyzer $analyzer): void
    {
        $claimed = ProductImageMetadata::where('job_token', $this->token)->where('status', 'queued')->update(['status' => 'processing']);
        if (! $claimed) {
            return;
        }
        try {
            $asset = ProductImageAsset::findOrFail($this->assetId);
            if ($asset->version !== $this->version) {
                $this->failed(null);

                return;
            }
            $request = ProductImageRequest::findOrFail($asset->product_image_request_id);
            $result = collect($request->results)->firstWhere('filename', $asset->filename) ?? [];
            $fields = $analyzer->analyze(base64_decode($asset->contents_base64), (array) $request->generation_context, $result);
            DB::transaction(function () use ($asset, $request, $fields) {
                ProductImageRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
                $current = ProductImageAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
                $row = ProductImageMetadata::where('job_token', $this->token)->lockForUpdate()->first();
                if (! $row) {
                    return; // A manual edit or explicit cancellation won the race.
                }
                if ($current->version !== $this->version || $current->refinement_status !== 'idle') {
                    $this->failed(null);

                    return;
                }
                $row->update(['fields' => app(ProductImageSeo::class)->uniqueFilename($current, ProductImageSeo::normalize($fields)),
                    'source' => 'ai', 'status' => 'completed', 'error' => null, 'job_token' => null, 'revision' => $row->revision + 1]);
            });
        } catch (Throwable $e) {
            $this->failed($e);
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Never persist provider payloads, credentials or untrusted exception text.
        $safe = $exception instanceof ProductImageSeoException
            ? $exception->getMessage() : 'De SEO-analyse is niet afgerond. De foto en eventuele vorige SEO zijn bewaard. Probeer opnieuw of vul de velden handmatig in.';
        ProductImageMetadata::where('job_token', $this->token)->update(['status' => 'failed', 'error' => $safe, 'job_token' => null]);
    }
}

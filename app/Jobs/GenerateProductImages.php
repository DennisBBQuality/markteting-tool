<?php

namespace App\Jobs;

use App\Models\ProductImageAsset;
use App\Models\ProductImageRequest;
use App\Services\ProductImageGenerationException;
use App\Services\ProductImageGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GenerateProductImages implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public string $requestId)
    {
        $this->onQueue('images');
    }

    public function backoff(): array
    {
        return [30];
    }

    public function handle(ProductImageGenerator $generator): void
    {
        $request = ProductImageRequest::findOrFail($this->requestId);
        if (in_array($request->status, ['completed', 'failed'], true)) {
            return;
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $request->update([
            'status' => 'processing',
            'progress' => 10,
            'progress_step' => 'starting',
            'error' => null,
            'started_at' => $request->started_at ?? now(),
        ]);

        $absolutePath = Storage::disk('local')->path($request->source_path);
        if (! is_file($absolutePath)) {
            throw new RuntimeException('De geüploade bronfoto ontbreekt.');
        }

        $source = new UploadedFile(
            $absolutePath,
            basename($request->source_path),
            mime_content_type($absolutePath) ?: null,
            null,
            true,
        );
        $generatedImages = $generator->generate(
            $source,
            $request->prompt,
            function (string $step, int $progress) use ($request): void {
                $request->update([
                    'progress' => min(90, max(10, $progress)),
                    'progress_step' => $step,
                ]);
            },
        );
        $request->update([
            'progress' => 90,
            'progress_step' => 'saving',
        ]);
        $results = $this->storeValidatedResults($request, $generatedImages);

        $request->update([
            'status' => 'completed',
            'progress' => 100,
            'progress_step' => 'completed',
            'results' => $results,
            'error' => null,
            'completed_at' => now(),
        ]);
        Storage::disk('local')->delete($request->source_path);
    }

    public function failed(?Throwable $exception): void
    {
        $request = ProductImageRequest::find($this->requestId);
        if (! $request) {
            return;
        }

        Storage::disk('local')->delete($request->source_path);
        $request->update([
            'status' => 'failed',
            'progress_step' => 'failed',
            'error' => $exception instanceof ProductImageGenerationException
                ? $exception->getMessage()
                : 'De productfoto\'s konden niet worden gemaakt. Probeer het later opnieuw.',
            'completed_at' => now(),
        ]);
    }

    /** @param mixed $images */
    private function storeValidatedResults(ProductImageRequest $request, $images): array
    {
        if (! is_array($images) || count($images) !== 4) {
            throw new RuntimeException('De beeldservice leverde niet exact vier afbeeldingen op.');
        }

        $counts = ['bereid' => 0, 'rauw' => 0];
        $results = [];
        $assets = [];

        foreach ($images as $image) {
            if (! $this->isValidGeneratedImage($image, $counts)) {
                throw new RuntimeException('De beeldservice leverde ongeldige afbeeldingen op.');
            }

            $status = $image['status'];
            $counts[$status]++;
            $filename = $status.'-'.Str::uuid().'.png';

            $assets[] = [
                'filename' => $filename,
                'contents_base64' => base64_encode($image['contents']),
            ];
            $results[] = [
                'status' => $status,
                'label' => $status === 'bereid' ? 'Vlees bereid' : 'Vlees rauw',
                'variant' => $counts[$status],
                'filename' => $filename,
            ];
        }

        if ($counts !== ['bereid' => 2, 'rauw' => 2]) {
            throw new RuntimeException('De beeldservice leverde niet twee bereide en twee rauwe afbeeldingen op.');
        }

        DB::transaction(function () use ($request, $assets): void {
            ProductImageAsset::where('product_image_request_id', $request->id)->delete();

            foreach ($assets as $asset) {
                ProductImageAsset::create([
                    'product_image_request_id' => $request->id,
                    'filename' => $asset['filename'],
                    'mime_type' => 'image/png',
                    'contents_base64' => $asset['contents_base64'],
                ]);
            }
        });

        return $results;
    }

    /** @param mixed $image */
    private function isValidGeneratedImage($image, array $counts): bool
    {
        if (
            ! is_array($image)
            || ! isset($image['status'], $image['contents'], $image['extension'])
            || ! array_key_exists($image['status'], $counts)
            || $image['extension'] !== 'png'
            || ! is_string($image['contents'])
            || $image['contents'] === ''
            || strlen($image['contents']) > (int) config('services.product_images.max_output_bytes')
        ) {
            return false;
        }

        $metadata = @getimagesizefromstring($image['contents']);

        return is_array($metadata) && ($metadata['mime'] ?? null) === 'image/png';
    }
}

<?php

namespace App\Jobs;

use App\Models\ProductImageAsset;
use App\Models\ProductImageRequest;
use App\Services\ProductImageGenerationException;
use App\Services\ProductImageGenerator;
use App\Services\ProductImageWorkflowGenerator;
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

        $sources = $this->uploadedSources($request);
        $progress = function (string $step, int $progress) use ($request): void {
                $request->update([
                    'progress' => min(90, max(10, $progress)),
                    'progress_step' => $step,
                ]);
            };
        $generatedImages = $generator instanceof ProductImageWorkflowGenerator && is_array($request->generation_context)
            ? $generator->generateForProduct($sources, $request->prompt, $request->generation_context, $progress)
            : $generator->generate($sources[0], $request->prompt, $progress);
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
        $this->deleteSources($request);
    }

    public function failed(?Throwable $exception): void
    {
        $request = ProductImageRequest::find($this->requestId);
        if (! $request) {
            return;
        }

        $this->deleteSources($request);
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
        $expected = ($request->generation_context['product_type'] ?? 'meat') === 'meat' ? 4 : 2;
        if (! is_array($images) || count($images) !== $expected) {
            throw new RuntimeException("De beeldservice leverde niet exact {$expected} afbeeldingen op.");
        }

        $counts = [];
        $results = [];
        $assets = [];

        foreach ($images as $image) {
            if (! $this->isValidGeneratedImage($image)) {
                throw new RuntimeException('De beeldservice leverde ongeldige afbeeldingen op.');
            }

            $status = $image['status'];
            $counts[$status] = $counts[$status] ?? 0;
            $counts[$status]++;
            $filename = $status.'-'.Str::uuid().'.png';

            $assets[] = [
                'filename' => $filename,
                'style_id' => $image['style_id'] ?? null,
                'contents_base64' => base64_encode($image['contents']),
            ];
            $results[] = [
                'status' => $status,
                'label' => $image['label'] ?? ($status === 'bereid' ? 'Vlees bereid' : 'Vlees rauw'),
                'variant' => $counts[$status],
                'filename' => $filename,
                'style_id' => $image['style_id'] ?? null,
            ];
        }

        if ($expected === 4 && (($counts['bereid'] ?? 0) !== 2 || ($counts['rauw'] ?? 0) !== 2)) {
            throw new RuntimeException('De beeldservice leverde niet twee bereide en twee rauwe afbeeldingen op.');
        }

        DB::transaction(function () use ($request, $assets): void {
            ProductImageAsset::where('product_image_request_id', $request->id)->delete();

            foreach ($assets as $asset) {
                ProductImageAsset::create([
                    'product_image_request_id' => $request->id,
                    'filename' => $asset['filename'],
                    'style_id' => $asset['style_id'],
                    'mime_type' => 'image/png',
                    'contents_base64' => $asset['contents_base64'],
                ]);
            }
        });

        return $results;
    }

    /** @param mixed $image */
    private function isValidGeneratedImage($image): bool
    {
        if (
            ! is_array($image)
            || ! isset($image['status'], $image['contents'], $image['extension'])
            || ! is_string($image['status'])
            || ! in_array($image['status'], ['bereid', 'rauw', 'product', 'totaal'], true)
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

    /** @return list<UploadedFile> */
    private function uploadedSources(ProductImageRequest $request): array
    {
        $references = $request->source_references ?: [['path' => $request->source_path]];
        $sources = [];
        foreach ($references as $reference) {
            $path = $reference['path'] ?? null;
            if (! is_string($path)) {
                continue;
            }
            $absolutePath = Storage::disk('local')->path($path);
            if (! is_file($absolutePath)) {
                throw new RuntimeException('Een geüploade referentiefoto ontbreekt.');
            }
            $sources[] = new UploadedFile(
                $absolutePath,
                basename($path),
                mime_content_type($absolutePath) ?: null,
                null,
                true,
            );
        }
        if ($sources === []) {
            throw new RuntimeException('De geüploade bronfoto ontbreekt.');
        }

        return $sources;
    }

    private function deleteSources(ProductImageRequest $request): void
    {
        $paths = collect($request->source_references ?: [])->pluck('path')->filter()->all();
        $paths[] = $request->source_path;
        Storage::disk('local')->delete(array_values(array_unique($paths)));
    }
}

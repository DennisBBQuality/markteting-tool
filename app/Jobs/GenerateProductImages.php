<?php

namespace App\Jobs;

use App\Models\ProductImageRequest;
use App\Services\ProductImageGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
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
        $request->update(['status' => 'processing', 'error' => null]);

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
        $generatedImages = $generator->generate($source, $request->prompt);
        $results = $this->storeValidatedResults($request, $generatedImages);

        $request->update([
            'status' => 'completed',
            'results' => $results,
            'error' => null,
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
            'error' => 'De productfoto\'s konden niet worden gemaakt. Probeer het later opnieuw.',
        ]);
    }

    /** @param mixed $images */
    private function storeValidatedResults(ProductImageRequest $request, $images): array
    {
        if (! is_array($images) || count($images) !== 4) {
            throw new RuntimeException('De beeldservice leverde niet exact vier afbeeldingen op.');
        }

        $counts = ['bereid' => 0, 'rauw' => 0];
        $storedPaths = [];
        $results = [];

        try {
            foreach ($images as $image) {
                if (! $this->isValidGeneratedImage($image, $counts)) {
                    throw new RuntimeException('De beeldservice leverde ongeldige afbeeldingen op.');
                }

                $status = $image['status'];
                $counts[$status]++;
                $filename = $status.'-'.Str::uuid().'.png';
                $path = 'product-images/'.$request->id.'/'.$filename;

                if (! Storage::disk('local')->put($path, $image['contents'])) {
                    throw new RuntimeException('De productfoto\'s konden niet veilig worden opgeslagen.');
                }

                $storedPaths[] = $path;
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
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);
            throw $exception;
        }

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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateProductImages;
use App\Models\ImagePrompt;
use App\Models\ProductImageRequest;
use App\Services\AiCredentialStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImageController extends Controller
{
    public function prompt(AiCredentialStore $credentials): JsonResponse
    {
        $prompt = ImagePrompt::productPhoto();

        return response()->json([
            'prompt' => $prompt->prompt,
            'bijgewerkt_op' => $prompt->updated_at?->toISOString(),
            'voorbeeldmodus' => ! $credentials->openAiIsActive(),
        ]);
    }

    public function updatePrompt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'min:20', 'max:6000'],
        ]);

        $prompt = ImagePrompt::productPhoto();
        $prompt->update([
            'prompt' => trim($validated['prompt']),
            'bijgewerkt_door' => $request->session()->get('userId'),
        ]);

        return response()->json([
            'prompt' => $prompt->prompt,
            'bijgewerkt_op' => $prompt->updated_at?->toISOString(),
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'foto' => [
                'required',
                'file',
                'max:10240',
                'extensions:jpg,jpeg,png,webp',
                'mimes:jpg,jpeg,png,webp',
                'dimensions:max_width=8000,max_height=8000',
            ],
        ]);

        $extension = strtolower($validated['foto']->guessExtension() ?: 'jpg');
        $sourcePath = $validated['foto']->storeAs(
            'product-image-inputs',
            Str::uuid().'.'.$extension,
            'local',
        );
        if (! is_string($sourcePath)) {
            return response()->json(['error' => 'De bronfoto kon niet veilig worden opgeslagen.'], 500);
        }

        $imageRequest = ProductImageRequest::create([
            'user_id' => $request->session()->get('userId'),
            'status' => 'queued',
            'progress' => 5,
            'progress_step' => 'queued',
            'source_path' => $sourcePath,
            'prompt' => ImagePrompt::productPhoto()->prompt,
        ]);
        GenerateProductImages::dispatch($imageRequest->id)
            ->onConnection((string) config('services.product_images.queue_connection', 'deferred'));

        return response()->json($this->requestPayload($imageRequest), 202);
    }

    public function status(Request $request, ProductImageRequest $imageRequest): JsonResponse
    {
        $this->ensureOwner($request, $imageRequest);
        $imageRequest = $this->failIfStalled($imageRequest->refresh());

        return response()->json($this->requestPayload($imageRequest));
    }

    public function show(Request $request, ProductImageRequest $imageRequest, string $filename)
    {
        $this->ensureOwner($request, $imageRequest);
        $safeFilename = basename($filename);
        $extension = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION));

        $knownFiles = collect($imageRequest->results ?? [])->pluck('filename');
        if ($safeFilename !== $filename || $extension !== 'png' || ! $knownFiles->contains($safeFilename)) {
            abort(404);
        }

        $path = 'product-images/'.$imageRequest->id.'/'.$safeFilename;

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $headers = [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($request->boolean('download')) {
            return Storage::disk('local')->download($path, $safeFilename, $headers);
        }

        return response()->file(Storage::disk('local')->path($path), $headers);
    }

    private function ensureOwner(Request $request, ProductImageRequest $imageRequest): void
    {
        if ($imageRequest->user_id !== $request->session()->get('userId')) {
            abort(404);
        }
    }

    private function requestPayload(ProductImageRequest $imageRequest): array
    {
        $results = collect($imageRequest->results ?? [])->map(function (array $result) use ($imageRequest) {
            $url = '/api/images/requests/'.$imageRequest->id.'/generated/'.rawurlencode($result['filename']);

            return [
                'status' => $result['status'],
                'label' => $result['label'],
                'variant' => $result['variant'],
                'url' => $url,
                'download_url' => $url.'?download=1',
            ];
        })->values();

        return [
            'request_id' => $imageRequest->id,
            'status' => $imageRequest->status,
            'progress' => max(0, min(100, (int) $imageRequest->progress)),
            'progress_step' => $imageRequest->progress_step,
            'progress_label' => $this->progressLabel($imageRequest->progress_step),
            'elapsed_seconds' => $imageRequest->created_at
                ? max(0, (int) $imageRequest->created_at->diffInSeconds($imageRequest->completed_at ?? now()))
                : 0,
            'results' => $results,
            'error' => $imageRequest->error,
        ];
    }

    private function failIfStalled(ProductImageRequest $imageRequest): ProductImageRequest
    {
        $queuedTooLong = $imageRequest->status === 'queued'
            && $imageRequest->created_at?->lt(now()->subMinutes(2));
        $processingTooLong = $imageRequest->status === 'processing'
            && $imageRequest->updated_at?->lt(now()->subMinutes(12));

        if (! $queuedTooLong && ! $processingTooLong) {
            return $imageRequest;
        }

        Storage::disk('local')->delete($imageRequest->source_path);
        $imageRequest->update([
            'status' => 'failed',
            'progress_step' => 'failed',
            'error' => $queuedTooLong
                ? 'De achtergrondtaak kon niet starten. Probeer de opdracht opnieuw.'
                : 'De beeldservice reageerde te lang niet. Probeer de opdracht opnieuw.',
            'completed_at' => now(),
        ]);

        return $imageRequest->refresh();
    }

    private function progressLabel(?string $step): string
    {
        return match ($step) {
            'queued' => 'Opdracht ontvangen',
            'starting' => 'Beeldgenerator starten',
            'preparing' => 'Bronfoto voorbereiden',
            'generating_prepared' => 'Twee bereide productfoto\'s maken',
            'generating_raw' => 'Twee rauwe productfoto\'s maken',
            'saving' => 'Afbeeldingen controleren en opslaan',
            'completed' => 'Vier productfoto\'s zijn klaar',
            'failed' => 'Opdracht gestopt',
            default => 'Voortgang wordt bijgewerkt',
        };
    }
}

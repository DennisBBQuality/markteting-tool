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
            'source_path' => $sourcePath,
            'prompt' => ImagePrompt::productPhoto()->prompt,
        ]);
        GenerateProductImages::dispatch($imageRequest->id)
            ->onConnection((string) config('services.product_images.queue_connection', 'background'));

        return response()->json($this->requestPayload($imageRequest), 202);
    }

    public function status(Request $request, ProductImageRequest $imageRequest): JsonResponse
    {
        $this->ensureOwner($request, $imageRequest);

        return response()->json($this->requestPayload($imageRequest->refresh()));
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
            'results' => $results,
            'error' => $imageRequest->error,
        ];
    }
}

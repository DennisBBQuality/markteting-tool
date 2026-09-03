<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateProductImages;
use App\Jobs\RefineProductImage;
use App\Models\ImagePrompt;
use App\Models\ProductImageAsset;
use App\Models\ProductImageRevision;
use App\Models\ProductImageRequest;
use App\Models\ProductImageStyleReference;
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
                'nullable',
                'required_without:fotos',
                'file',
                'max:10240',
                'extensions:jpg,jpeg,png,webp',
                'mimes:jpg,jpeg,png,webp',
                'dimensions:max_width=8000,max_height=8000',
            ],
            'fotos' => ['nullable', 'required_without:foto', 'array', 'min:1', 'max:5'],
            'fotos.*' => [
                'file', 'max:10240', 'extensions:jpg,jpeg,png,webp', 'mimes:jpg,jpeg,png,webp',
                'dimensions:max_width=8000,max_height=8000',
            ],
            'product_type' => ['nullable', 'in:meat,sauce,bundle'],
            'product_name' => ['nullable', 'string', 'max:160'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'components' => ['nullable', 'string', 'max:3000'],
            'main_index' => ['nullable', 'integer', 'min:0', 'max:4'],
            'reference_names' => ['nullable', 'array', 'max:5'],
            'reference_names.*' => ['nullable', 'string', 'max:160'],
        ]);

        $files = isset($validated['fotos']) ? array_values($validated['fotos']) : [$validated['foto']];
        $mainIndex = min((int) ($validated['main_index'] ?? 0), count($files) - 1);
        if ($mainIndex > 0) {
            [$files[0], $files[$mainIndex]] = [$files[$mainIndex], $files[0]];
        }
        $stored = [];
        foreach ($files as $index => $file) {
            $extension = strtolower($file->guessExtension() ?: 'jpg');
            $path = $file->storeAs('product-image-inputs', Str::uuid().'.'.$extension, 'local');
            if (! is_string($path)) {
                Storage::disk('local')->delete(array_column($stored, 'path'));
                return response()->json(['error' => 'De referentiefoto’s konden niet veilig worden opgeslagen.'], 500);
            }
            $stored[] = [
                'path' => $path,
                'name' => ($validated['reference_names'][$index] ?? null) ?: $file->getClientOriginalName(),
                'is_main' => $index === 0,
            ];
        }

        $context = [
            'product_type' => $validated['product_type'] ?? 'meat',
            'product_name' => trim((string) ($validated['product_name'] ?? 'Vleesproduct')),
            'quantity' => (int) ($validated['quantity'] ?? 1),
            'notes' => trim((string) ($validated['notes'] ?? '')),
            'components' => trim((string) ($validated['components'] ?? '')),
        ];

        $imageRequest = ProductImageRequest::create([
            'user_id' => $request->session()->get('userId'),
            'status' => 'queued',
            'progress' => 5,
            'progress_step' => 'queued',
            'source_path' => $stored[0]['path'],
            'source_references' => $stored,
            'prompt' => ImagePrompt::productPhoto()->prompt,
            'generation_context' => $context,
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

    public function show(Request $request, ProductImageRequest $imageRequest, string $asset)
    {
        $this->ensureOwner($request, $imageRequest);
        $safeAsset = basename($asset);
        $safeFilename = $safeAsset.'.png';

        $knownFiles = collect($imageRequest->results ?? [])->pluck('filename');
        if ($safeAsset !== $asset || ! $knownFiles->contains($safeFilename)) {
            abort(404);
        }

        $headers = [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $asset = ProductImageAsset::where('product_image_request_id', $imageRequest->id)
            ->where('filename', $safeFilename)
            ->first();
        if ($asset) {
            $contents = base64_decode($asset->contents_base64, true);
            if (! is_string($contents) || $contents === '') {
                abort(404);
            }

            if ($request->boolean('download')) {
                $headers['Content-Disposition'] = 'attachment; filename="'.$safeFilename.'"';
            }

            return response($contents, 200, $headers);
        }

        // Keep images from requests created before database-backed storage available.
        $path = 'product-images/'.$imageRequest->id.'/'.$safeFilename;
        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        if ($request->boolean('download')) {
            return Storage::disk('local')->download($path, $safeFilename, $headers);
        }

        return response()->file(Storage::disk('local')->path($path), $headers);
    }

    public function refine(Request $request, ProductImageRequest $imageRequest, ProductImageAsset $asset): JsonResponse
    {
        $this->ensureOwner($request, $imageRequest);
        $this->ensureAssetBelongsToRequest($imageRequest, $asset);
        $validated = $request->validate([
            'instruction' => ['required', 'string', 'min:5', 'max:1200'],
        ]);
        if ($asset->refinement_status !== 'idle') {
            return response()->json(['error' => 'Deze foto wordt al aangepast.'], 409);
        }

        $asset->update([
            'refinement_status' => 'queued',
            'refinement_error' => null,
            'last_instruction' => trim($validated['instruction']),
        ]);
        RefineProductImage::dispatch($imageRequest->id, $asset->id, trim($validated['instruction']))
            ->onConnection((string) config('services.product_images.queue_connection', 'deferred'));

        return response()->json(['status' => 'queued'], 202);
    }

    public function revisions(Request $request, ProductImageRequest $imageRequest, ProductImageAsset $asset): JsonResponse
    {
        $this->ensureOwner($request, $imageRequest);
        $this->ensureAssetBelongsToRequest($imageRequest, $asset);

        return response()->json([
            'current_version' => $asset->version,
            'revisions' => $asset->revisions()->orderByDesc('version')->get()->map(fn ($revision) => [
                'id' => $revision->id,
                'version' => $revision->version,
                'instruction' => $revision->instruction,
                'created_at' => $revision->created_at?->toISOString(),
            ]),
        ]);
    }

    public function addToStyleLibrary(Request $request, ProductImageRequest $imageRequest, ProductImageAsset $asset): JsonResponse
    {
        $this->ensureOwner($request, $imageRequest);
        $this->ensureAssetBelongsToRequest($imageRequest, $asset);
        $validated = $request->validate([
            'product_name' => ['required', 'string', 'min:2', 'max:160'],
        ]);

        $result = collect($imageRequest->results ?? [])->firstWhere('filename', $asset->filename);
        if (! is_array($result)) {
            abort(404);
        }

        $contents = base64_decode($asset->contents_base64, true);
        $metadata = is_string($contents) ? @getimagesizefromstring($contents) : false;
        if (! is_array($metadata) || ($metadata['mime'] ?? null) !== 'image/png') {
            return response()->json(['error' => 'Deze foto kon niet veilig aan de stijlbibliotheek worden toegevoegd.'], 422);
        }

        $productName = trim($validated['product_name']);
        $reference = ProductImageStyleReference::updateOrCreate(
            ['source_asset_id' => $asset->id],
            [
                'product_name' => $productName,
                'product_key' => ProductImageStyleReference::productKey($productName),
                'product_type' => (string) ($imageRequest->generation_context['product_type'] ?? 'meat'),
                'status' => (string) ($result['status'] ?? ''),
                'style_id' => $asset->style_id ?? ($result['style_id'] ?? null),
                'source_version' => $asset->version,
                'created_by' => $request->session()->get('userId'),
                'mime_type' => 'image/png',
                'contents_base64' => $asset->contents_base64,
            ],
        );

        return response()->json([
            'saved' => true,
            'reference_id' => $reference->id,
            'product_name' => $reference->product_name,
            'source_version' => $reference->source_version,
        ], $reference->wasRecentlyCreated ? 201 : 200);
    }

    public function restore(Request $request, ProductImageRequest $imageRequest, ProductImageAsset $asset, ProductImageRevision $revision): JsonResponse
    {
        $this->ensureOwner($request, $imageRequest);
        $this->ensureAssetBelongsToRequest($imageRequest, $asset);
        if ($revision->product_image_asset_id !== $asset->id) {
            abort(404);
        }
        $asset->revisions()->firstOrCreate(['version' => $asset->version], [
            'instruction' => $asset->last_instruction,
            'mime_type' => $asset->mime_type,
            'contents_base64' => $asset->contents_base64,
        ]);
        $asset->update([
            'contents_base64' => $revision->contents_base64,
            'mime_type' => $revision->mime_type,
            'version' => $asset->version + 1,
            'last_instruction' => 'Versie '.$revision->version.' hersteld',
            'refinement_error' => null,
        ]);

        return response()->json(['status' => 'restored', 'version' => $asset->fresh()->version]);
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
            $asset = pathinfo($result['filename'], PATHINFO_FILENAME);
            $url = '/api/images/requests/'.$imageRequest->id.'/generated/'.rawurlencode($asset);

            $storedAsset = ProductImageAsset::where('product_image_request_id', $imageRequest->id)
                ->where('filename', $result['filename'])
                ->first();

            return [
                'asset_id' => $storedAsset?->id,
                'status' => $result['status'],
                'label' => $result['label'],
                'variant' => $result['variant'],
                'style_id' => $result['style_id'] ?? $storedAsset?->style_id,
                'version' => $storedAsset?->version ?? 1,
                'refinement_status' => $storedAsset?->refinement_status ?? 'idle',
                'refinement_error' => $storedAsset?->refinement_error,
                'in_style_library' => $storedAsset
                    ? ProductImageStyleReference::where('source_asset_id', $storedAsset->id)
                        ->where('source_version', $storedAsset->version)
                        ->exists()
                    : false,
                'needs_label_review' => ($imageRequest->generation_context['product_type'] ?? null) === 'sauce',
                'url' => $url.'?v='.($storedAsset?->version ?? 1),
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
            'context' => $imageRequest->generation_context,
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

        $paths = collect($imageRequest->source_references ?: [])->pluck('path')->filter()->all();
        $paths[] = $imageRequest->source_path;
        Storage::disk('local')->delete(array_values(array_unique($paths)));
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
            'generating_product' => 'Verschillende productfoto’s maken',
            'saving' => 'Afbeeldingen controleren en opslaan',
            'completed' => 'Vier productfoto\'s zijn klaar',
            'failed' => 'Opdracht gestopt',
            default => 'Voortgang wordt bijgewerkt',
        };
    }

    private function ensureAssetBelongsToRequest(ProductImageRequest $imageRequest, ProductImageAsset $asset): void
    {
        if ($asset->product_image_request_id !== $imageRequest->id) {
            abort(404);
        }
    }
}

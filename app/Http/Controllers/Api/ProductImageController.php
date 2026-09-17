<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateProductImages;
use App\Jobs\RefineProductImage;
use App\Models\ImagePrompt;
use App\Models\ProductDossier;
use App\Models\ProductImageAsset;
use App\Models\ProductImageMetadata;
use App\Models\ProductImageRequest;
use App\Models\ProductImageRevision;
use App\Models\ProductImageStyleReference;
use App\Services\AiCredentialStore;
use App\Services\ProductImageDelivery;
use App\Services\ProductImageModelCatalog;
use App\Services\ProductImagePromptBuilder;
use App\Services\ProductImageSeo;
use App\Services\ProductImageStyleLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductImageController extends Controller
{
    public function models(ProductImageModelCatalog $models): JsonResponse
    {
        return response()->json($models->catalog());
    }

    public function refreshModels(ProductImageModelCatalog $models): JsonResponse
    {
        return response()->json($models->catalog(true));
    }

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

    public function generate(Request $request, ProductImageModelCatalog $models): JsonResponse
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
            'product_type' => ['nullable', 'in:meat,fish,sauce,bundle'],
            'product_name' => ['nullable', 'string', 'max:160'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'components' => ['nullable', 'string', 'max:3000'],
            'main_index' => ['nullable', 'integer', 'min:0', 'max:4'],
            'reference_names' => ['nullable', 'array', 'max:5'],
            'reference_names.*' => ['nullable', 'string', 'max:160'],
            'image_model' => ['nullable', 'string', 'max:120'],
            'accept_experimental' => ['sometimes', 'boolean'],
            'variant_groups' => ['sometimes', 'required', 'array', 'min:1', 'max:5'],
            'variant_groups.*' => ['required', 'string', 'distinct:strict', 'in:raw,bbq,pan,oven,airfryer'],
        ]);

        if (isset($validated['variant_groups']) && ! in_array($validated['product_type'] ?? 'meat', ['meat', 'fish'], true)) {
            throw ValidationException::withMessages(['variant_groups' => 'Deze varianten zijn alleen beschikbaar voor Vlees en Vis.']);
        }

        $model = isset($validated['image_model'])
            ? $models->validateSelection($validated['image_model'], (bool) ($validated['accept_experimental'] ?? false))
            : $models->selected();

        $files = isset($validated['fotos']) ? array_values($validated['fotos']) : [$validated['foto']];
        $mainIndex = min((int) ($validated['main_index'] ?? 0), count($files) - 1);
        if ($mainIndex > 0) {
            [$files[0], $files[$mainIndex]] = [$files[$mainIndex], $files[0]];
            $names = (array) ($validated['reference_names'] ?? []);
            [$names[0], $names[$mainIndex]] = [$names[$mainIndex] ?? null, $names[0] ?? null];
            $validated['reference_names'] = $names;
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
            'image_model' => $model,
            'product_type' => $validated['product_type'] ?? 'meat',
            'product_name' => trim((string) ($validated['product_name'] ?? 'Vleesproduct')),
            'quantity' => (int) ($validated['quantity'] ?? 1),
            'notes' => trim((string) ($validated['notes'] ?? '')),
            'components' => trim((string) ($validated['components'] ?? '')),
        ];
        if (isset($validated['variant_groups'])) {
            $context['variant_groups'] = array_values($validated['variant_groups']);
            $library = app(ProductImageStyleLibrary::class);
            foreach (array_intersect(['pan', 'oven', 'airfryer'], $context['variant_groups']) as $group) {
                $previous = ProductImageRequest::query()
                    ->where('user_id', $request->session()->get('userId'))
                    ->whereNotNull('generation_context->kitchen_references->'.$group)
                    ->latest('created_at')->latest('id')->first();
                $context['kitchen_references'][$group] = $library->nextKitchenId(
                    $group, $previous?->generation_context['kitchen_references'][$group] ?? null
                );
            }
        }
        $context['photo_count'] = count(app(ProductImagePromptBuilder::class)->plans($context));

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

    public function linkDossier(Request $request, ProductImageRequest $imageRequest): JsonResponse
    {
        $this->ensureOwner($request, $imageRequest);
        $id = $request->validate(['product_dossier_id' => ['required', 'uuid']])['product_dossier_id'];
        ProductDossier::where('user_id', $request->session()->get('userId'))->findOrFail($id);
        $imageRequest->update(['generation_context' => [...(array) $imageRequest->generation_context, 'product_dossier_id' => $id]]);

        return response()->json(['linked' => true]);
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

            if ($request->query('format') === 'webp') {
                return $this->webpResponse($imageRequest, $safeFilename, $contents, $asset->version ?? 1, $asset);
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

        if ($request->query('format') === 'webp') {
            return $this->webpResponse($imageRequest, $safeFilename, Storage::disk('local')->get($path), 1);
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
        $claimed = ProductImageAsset::whereKey($asset->id)->where('refinement_status', 'idle')->update([
            'refinement_status' => 'queued',
            'refinement_error' => null,
            'last_instruction' => trim($validated['instruction']),
        ]);
        if (! $claimed) {
            return response()->json(['error' => 'Deze foto wordt al aangepast.'], 409);
        }
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
        DB::transaction(function () use ($asset, $revision) {
            ProductImageRequest::whereKey($asset->product_image_request_id)->lockForUpdate()->firstOrFail();
            $asset = ProductImageAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            abort_if($asset->refinement_status !== 'idle', 409, 'Deze foto wordt al aangepast.');
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
            $previous = ProductImageMetadata::where('product_image_asset_id', $asset->id)->where('image_version', $revision->version)->first();
            if ($previous?->fields) {
                ProductImageMetadata::create(['product_image_asset_id' => $asset->id, 'image_version' => $asset->version,
                    'fields' => app(ProductImageSeo::class)->uniqueFilename($asset, $previous->fields), 'source' => $previous->source,
                    'status' => 'completed', 'revision' => 1]);
            }
        });
        $asset->refresh();
        if (! app(ProductImageSeo::class)->record($asset)?->fields) {
            app(ProductImageSeo::class)->queue($asset);
        }

        return response()->json(['status' => 'restored', 'version' => $asset->fresh()->version]);
    }

    private function ensureOwner(Request $request, ProductImageRequest $imageRequest): void
    {
        if ($imageRequest->user_id !== $request->session()->get('userId')) {
            abort(404);
        }
    }

    private function webpResponse(ProductImageRequest $request, string $filename, string $contents, int $version, ?ProductImageAsset $asset = null)
    {
        $delivery = app(ProductImageDelivery::class);
        $result = collect($request->results)->firstWhere('filename', $filename) ?? [];
        $metadata = $delivery->metadata((array) $request->generation_context, $result, $version, $asset);
        $path = 'product-images/'.$request->id.'/webp/'.hash('sha256', $contents).'.webp';
        if (! Storage::disk('local')->exists($path)) {
            Storage::disk('local')->put($path, $delivery->webp($contents));
        }

        return Storage::disk('local')->download($path, $metadata['filename'], [
            'Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
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
                'download_url' => $url.'?download=1&format=webp&v='.($storedAsset?->version ?? 1),
                'metadata' => app(ProductImageDelivery::class)->metadata((array) $imageRequest->generation_context, $result, $storedAsset?->version ?? 1, $storedAsset),
                'seo' => $storedAsset ? app(ProductImageSeo::class)->payload($storedAsset) : null,
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
            'expected_count' => $imageRequest->status === 'completed' ? count($imageRequest->results ?? []) : ($imageRequest->generation_context['photo_count'] ?? 4),
            'error' => $imageRequest->error,
        ];
    }

    private function failIfStalled(ProductImageRequest $imageRequest): ProductImageRequest
    {
        $queuedTooLong = $imageRequest->status === 'queued'
            && $imageRequest->created_at?->lt(now()->subMinutes(isset($imageRequest->generation_context['variant_groups']) ? 30 : 2));
        $processingTooLong = $imageRequest->status === 'processing'
            && $imageRequest->updated_at?->lt(now()->subMinutes(isset($imageRequest->generation_context['variant_groups']) ? 21 : 12));

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
            'generating_prepared' => 'Bereide productfoto\'s maken',
            'generating_raw' => 'Twee rauwe productfoto\'s maken',
            'generating_product' => 'Verschillende productfoto’s maken',
            'saving' => 'Afbeeldingen controleren en opslaan',
            'completed' => 'De productfoto\'s zijn klaar',
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

    public function seo(Request $request, ProductImageRequest $imageRequest, ProductImageAsset $asset, ProductImageSeo $seo): JsonResponse
    {
        $this->ensureOwner($request, $imageRequest);
        $this->ensureAssetBelongsToRequest($imageRequest, $asset);

        return response()->json($this->seoPayload($imageRequest, $asset, $seo));
    }

    public function saveSeo(Request $request, ProductImageRequest $imageRequest, ProductImageAsset $asset, ProductImageSeo $seo): JsonResponse
    {
        $this->ensureOwner($request, $imageRequest);
        $this->ensureAssetBelongsToRequest($imageRequest, $asset);
        $data = $request->validate(['image_version' => 'required|integer|min:1', 'revision' => 'required|integer|min:0', 'fields' => 'required|array']);
        abort_if($asset->version !== (int) $data['image_version'], 409, 'De foto is gewijzigd. Open de actuele versie.');
        $seo->save($asset, $data['fields'], $data['revision']);

        return response()->json($this->seoPayload($imageRequest, $asset, $seo));
    }

    public function generateSeo(Request $request, ProductImageRequest $imageRequest, ProductImageAsset $asset, ProductImageSeo $seo): JsonResponse
    {
        $this->ensureOwner($request, $imageRequest);
        $this->ensureAssetBelongsToRequest($imageRequest, $asset);
        $data = $request->validate(['image_version' => 'required|integer|min:1', 'revision' => 'required|integer|min:0', 'replace_manual' => 'sometimes|boolean']);
        abort_if($asset->version !== (int) $data['image_version'], 409, 'De foto is gewijzigd. Open de actuele versie.');
        $seo->payload($asset); // Expire abandoned jobs before an explicit retry.
        $seo->queue($asset, $data['revision'], $data['replace_manual'] ?? false);

        return response()->json($this->seoPayload($imageRequest, $asset, $seo), 202);
    }

    private function seoPayload(ProductImageRequest $request, ProductImageAsset $asset, ProductImageSeo $seo): array
    {
        $result = collect($request->results ?? [])->firstWhere('filename', $asset->filename) ?? [];

        return ['asset_id' => $asset->id, 'version' => $asset->version,
            'metadata' => app(ProductImageDelivery::class)->metadata((array) $request->generation_context, $result, $asset->version, $asset),
            'seo' => $seo->payload($asset)];
    }
}

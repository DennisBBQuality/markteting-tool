<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateProductPage;
use App\Models\ProductDossier;
use App\Models\ProductImageRequest;
use App\Services\AiCredentialStore;
use App\Services\ProductDossierAiService;
use App\Services\ProductDossierContent;
use App\Services\ProductDossierExport;
use App\Services\ProductDossierMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProductDossierController extends Controller
{
    public function aiStatus(AiCredentialStore $credentials): JsonResponse
    {
        return response()->json([
            'active' => $credentials->openAiContentIsActive(),
            'model' => (string) config('services.product_content.model'),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $dossiers = ProductDossier::query()
            ->where('user_id', $request->session()->get('userId'))
            ->latest('updated_at')
            ->limit(30)
            ->get()
            ->map(fn (ProductDossier $dossier) => $this->payload($dossier, false));

        return response()->json($dossiers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedData($request);
        $data = $this->sanitizeDossierData((array) ($validated['data'] ?? []));
        $productName = trim($validated['product_name']);

        $dossier = ProductDossier::create([
            'user_id' => $request->session()->get('userId'),
            'status' => $validated['status'] ?? 'concept',
            'product_type' => $this->cleanNullable($validated['product_type'] ?? null),
            'product_name' => $productName,
            'data' => $data,
            'analysis_status' => 'niet_gestart',
            'wordpress_status' => 'niet_gekoppeld',
        ]);

        return response()->json($this->payload($dossier), 201);
    }

    public function show(Request $request, ProductDossier $productDossier): JsonResponse
    {
        $this->ensureOwner($request, $productDossier);
        $generation = (array) $productDossier->generation;
        if (in_array($generation['status'] ?? '', ['queued', 'processing'], true)
            && Carbon::parse($generation['requested_at'])->lt(now()->subMinutes(12))) {
            $productDossier->update(['generation' => [...$generation, 'status' => 'failed', 'error' => 'De achtergrondtaak is niet op tijd afgerond. Je concept is bewaard. Probeer opnieuw; blijft dit gebeuren, controleer de Productstudio-worker.']]);
        }

        return response()->json($this->payload($productDossier));
    }

    public function update(Request $request, ProductDossier $productDossier): JsonResponse
    {
        $this->ensureOwner($request, $productDossier);
        $validated = $this->validatedData($request);

        return DB::transaction(function () use ($request, $productDossier, $validated) {
            $dossier = ProductDossier::lockForUpdate()->findOrFail($productDossier->id);
            $stale = $request->filled('expected_revision')
                ? $request->input('expected_revision') !== $this->revision($dossier)
                : ($request->filled('expected_updated_at') && $request->input('expected_updated_at') !== $dossier->updated_at?->toISOString());
            if ($stale) {
                return response()->json(['error' => 'Dit concept is intussen op de server bijgewerkt. Je invoer staat nog in deze browser. Open het concept opnieuw en controleer de wijzigingen voordat je opslaat.'], 409);
            }
            $data = $this->sanitizeDossierData((array) ($validated['data'] ?? $dossier->data ?? []));
            foreach (['ingredients', 'allergens'] as $field) {
                if (($data[$field] ?? null) === data_get($dossier->data, $field)) {
                    // Older open tabs may omit source metadata. Never erase it on save.
                    $data[$field.'_source_status'] = data_get($dossier->data, $field.'_source_status') ?? ($data[$field.'_source_status'] ?? 'onbekend');
                } else {
                    $data[$field.'_source_status'] = 'handmatig';
                    $data['reviewed'][$field] = false;
                    if ($field === 'ingredients') {
                        $data['reviewed']['allergens'] = false;
                    }
                }
            }
            $data['content_history'] = (array) data_get($dossier->data, 'content_history', []);
            $data['composition_warnings'] = (array) data_get($dossier->data, 'composition_warnings', []);
            $previous = data_get($dossier->data, 'content');
            if ($previous && isset($data['content']) && $data['content'] !== $previous) {
                $data['content_history'] = array_slice([...$data['content_history'], $previous], -10);
            }
            $dossier->update([
                'status' => $validated['status'] ?? $dossier->status,
                'product_type' => array_key_exists('product_type', $validated) ? $this->cleanNullable($validated['product_type']) : $dossier->product_type,
                'product_name' => trim($validated['product_name']),
                'data' => $data,
            ]);

            return response()->json($this->payload($dossier->fresh()));
        });
    }

    public function uploadLabels(Request $request, ProductDossier $productDossier): JsonResponse
    {
        $this->ensureOwner($request, $productDossier);
        $validated = $request->validate([
            'labels' => ['required', 'array', 'min:1', 'max:4'],
            'labels.*' => ['file', 'max:10240', 'extensions:jpg,jpeg,png,webp', 'mimes:jpg,jpeg,png,webp'],
            'append' => ['nullable', 'boolean'],
        ]);

        $oldImages = array_values((array) ($productDossier->label_images ?? []));
        $append = $request->boolean('append');
        if ($append && count($oldImages) + count($validated['labels']) > 4) {
            throw ValidationException::withMessages([
                'labels' => 'Je kunt maximaal vier etiketfoto’s gebruiken.',
            ]);
        }

        $newImages = [];
        foreach ($validated['labels'] as $file) {
            $newImages[] = ProductDossierMedia::store($productDossier->id, $file);
        }

        $images = $append ? [...$oldImages, ...$newImages] : $newImages;

        $productDossier->update([
            'label_images' => $images,
            'label_analysis' => null,
            'analysis_status' => 'klaar_voor_analyse',
            'analysis_error' => null,
        ]);

        return response()->json($this->payload($productDossier->fresh()));
    }

    public function analyze(Request $request, ProductDossier $productDossier, ProductDossierAiService $ai): JsonResponse
    {
        $this->ensureOwner($request, $productDossier);
        $storedImages = (array) ($productDossier->label_images ?? []);
        $images = [];
        foreach ($storedImages as $storedImage) {
            $contents = ProductDossierMedia::contents($productDossier->id, $storedImage);
            if ($contents === null) {
                continue;
            }
            $images[] = [
                'contents' => $contents,
                'mime_type' => (string) ($storedImage['mime_type'] ?? 'image/jpeg'),
            ];
        }

        $sourceNotes = (string) data_get($productDossier->data, 'source_notes', '');
        $productDossier->update(['analysis_status' => 'bezig', 'analysis_error' => null]);

        try {
            $analysis = $ai->analyzeLabels($images, $sourceNotes, [
                'productnaam' => $productDossier->product_name,
                'producttype' => $productDossier->product_type,
                'productfeiten' => (array) data_get($productDossier->data, 'facts', []),
            ]);
        } catch (RuntimeException $exception) {
            $productDossier->update([
                'analysis_status' => 'mislukt',
                'analysis_error' => $exception->getMessage(),
            ]);

            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $productDossier->refresh();
        $analysis = ProductDossierContent::reviewLabelStorage($analysis);
        $data = $this->sanitizeDossierData((array) ($productDossier->data ?? []));
        $data = ProductDossierContent::mergeComposition($data, $analysis);
        foreach (['brand' => 'merk', 'origin' => 'herkomst', 'supplier' => 'producent', 'storage' => 'bewaaradvies'] as $field => $source) {
            if ($field === 'storage' && ! empty($analysis['storage_needs_review'])) {
                continue;
            }
            if (! ProductDossierContent::hasValue($data['facts'][$field] ?? null) && ProductDossierContent::hasValue($analysis[$source] ?? null)) {
                $data['facts'][$field] = $analysis[$source];
            }
        }
        $data['nutrition'] = ProductDossierContent::mergeNutrition((array) ($data['nutrition'] ?? []), (array) ($analysis['voedingswaarden'] ?? []), 'etiket');

        $productDossier->update([
            'label_analysis' => $analysis,
            'data' => $data,
            'analysis_status' => 'geanalyseerd',
            'analysis_error' => null,
        ]);

        return response()->json($this->payload($productDossier->fresh()));
    }

    public function estimateNutrition(Request $request, ProductDossier $productDossier, ProductDossierAiService $ai): JsonResponse
    {
        $this->ensureOwner($request, $productDossier);
        $validated = $request->validate(['data' => ['nullable', 'array']]);
        $data = $this->sanitizeDossierData((array) ($validated['data'] ?? $productDossier->data ?? []));

        try {
            $estimate = $ai->estimateNutrition([...$data, 'product_name' => $productDossier->product_name, 'product_type' => $productDossier->product_type]);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $data['nutrition'] = ProductDossierContent::mergeNutrition((array) ($data['nutrition'] ?? []), [
            ...(array) ($estimate['voedingswaarden'] ?? []),
            'aannames' => $estimate['aannames'] ?? [],
            'waarschuwing' => $estimate['waarschuwing'] ?? null,
        ], 'ai_schatting');
        $data = $this->sanitizeDossierData($data);
        $productDossier->update(['data' => $data]);

        return response()->json([
            'estimate' => $estimate,
            'dossier' => $this->payload($productDossier->fresh()),
        ]);
    }

    public function generatePage(Request $request, ProductDossier $productDossier, AiCredentialStore $credentials): JsonResponse
    {
        $this->ensureOwner($request, $productDossier);
        $operation = $request->validate(['operation' => ['nullable', 'in:page,label,supplement']])['operation'] ?? 'page';
        if ($operation === 'page' && trim((string) data_get($productDossier->data, 'facts.origin')) === '') {
            return response()->json(['error' => 'Vul eerst de herkomst in.'], 422);
        }
        if ($operation === 'label' && empty($productDossier->label_images)) {
            return response()->json(['error' => 'Voeg een etiketfoto toe, of ga zonder etiket verder met stap 2.'], 422);
        }
        if (! $credentials->openAiContentIsActive()) {
            return response()->json(['error' => 'De AI-koppeling is niet actief. Controleer de verbinding bij Instellingen. Je concept is bewaard.'], 422);
        }
        DB::transaction(function () use ($productDossier, $operation): void {
            $dossier = ProductDossier::lockForUpdate()->findOrFail($productDossier->id);
            $generation = (array) $dossier->generation;
            if (in_array($generation['status'] ?? '', ['queued', 'processing'], true)
                && Carbon::parse($generation['requested_at'])->gt(now()->subMinutes(12))) {
                return; // Double clicks and reconnects resume the same request.
            }
            $token = (string) Str::uuid();
            $dossier->update(['generation' => ['token' => $token, 'operation' => $operation, 'status' => 'queued', 'requested_at' => now()->toISOString(), 'error' => null]]);
            GenerateProductPage::dispatch($dossier->id, $token, $dossier->only(['product_name', 'product_type', 'data', 'label_analysis', 'label_images', 'expert_assets']), ProductDossierContent::inputHash($dossier), $operation)->afterCommit();
        });

        return response()->json(['dossier' => $this->payload($productDossier->fresh())], 202);
    }

    public function labelImage(Request $request, ProductDossier $productDossier, int $index)
    {
        $this->ensureOwner($request, $productDossier);
        $image = $productDossier->label_images[$index] ?? null;
        $contents = $image ? ProductDossierMedia::contents($productDossier->id, $image) : null;
        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => $image['mime_type'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function uploadExpertAsset(Request $request, ProductDossier $productDossier, string $kind): JsonResponse
    {
        $this->ensureOwner($request, $productDossier);
        abort_unless(in_array($kind, ['photo', 'signature'], true), 404);
        $validated = $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp']]);
        $file = $validated['file'];
        // Preserve replaced originals privately; no destructive overwrite.
        $assets = (array) $productDossier->expert_assets;
        $assets[$kind] = ProductDossierMedia::store($productDossier->id, $file);
        $data = (array) $productDossier->data;
        $data['expert']['approved'] = false;
        $productDossier->update(['expert_assets' => $assets, 'data' => $data]);

        return response()->json($this->payload($productDossier->fresh()));
    }

    public function expertAsset(Request $request, ProductDossier $productDossier, string $kind)
    {
        $this->ensureOwner($request, $productDossier);
        $asset = $productDossier->expert_assets[$kind] ?? null;
        $contents = $asset ? ProductDossierMedia::contents($productDossier->id, $asset) : null;
        abort_if($contents === null, 404);

        return response($contents, 200, ['Content-Type' => $asset['mime_type'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function toneProfile(): JsonResponse
    {
        return response()->json(config('bbquality.tone_of_voice'));
    }

    public function export(Request $request, ProductDossier $productDossier, ProductDossierExport $export)
    {
        $this->ensureOwner($request, $productDossier);
        $format = $request->validate(['format' => ['nullable', 'in:json,html']])['format'] ?? 'json';
        $document = $export->build($productDossier);
        $body = $format === 'html' ? $document['product']['description'] : json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $filename = (Str::slug($productDossier->product_name) ?: 'product').'-concept.'.$format;

        return response($body, 200, [
            'Content-Type' => $format === 'html' ? 'text/html; charset=utf-8' : 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', 'in:concept,controle,publish_klaar'],
            'product_type' => ['nullable', 'in:meat,fish,sauce,rub,prepared,bundle,other'],
            'product_name' => ['required', 'string', 'max:200'],
            'data' => ['nullable', 'array'],
            'data.source_notes' => ['nullable', 'string', 'max:12000'],
            'data.facts' => ['nullable', 'array'],
            'data.ingredients' => ['nullable', 'string', 'max:20000'],
            'data.allergens' => ['nullable', 'string', 'max:5000'],
            'data.nutrition' => ['nullable', 'array'],
            'data.expert' => ['nullable', 'array'],
            'data.content' => ['nullable', 'array'],
            'expected_updated_at' => ['nullable', 'string', 'max:50'],
            'expected_revision' => ['nullable', 'string', 'size:64'],
        ]);
    }

    private function ensureOwner(Request $request, ProductDossier $productDossier): void
    {
        abort_unless($productDossier->user_id === $request->session()->get('userId'), 404);
    }

    private function cleanNullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function hasNutritionValues(array $nutrition): bool
    {
        foreach (['energie_kj', 'energie_kcal', 'vetten', 'verzadigde_vetten', 'koolhydraten', 'suikers', 'eiwitten', 'zout'] as $field) {
            if ($this->cleanNullable($nutrition[$field] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeDossierData(array $data): array
    {
        if (isset($data['facts']) && is_array($data['facts'])) {
            unset($data['facts']['legal_name'], $data['facts']['wettelijke_naam']);
        }

        $nutrition = (array) ($data['nutrition'] ?? []);
        $basis = mb_strtolower(trim((string) ($nutrition['basis'] ?? '')));
        $containsLegalLabelText = collect([
            'gekoeld rundvlees zonder been',
            'chilled boneless beef',
            'wettelijke benaming',
        ])->contains(fn (string $phrase) => str_contains($basis, $phrase));

        if ($containsLegalLabelText) {
            $nutrition['basis'] = str_contains($basis, '100 ml')
                ? 'Per 100 ml product'
                : 'Per 100 g, rauw product';
            $data['nutrition'] = $nutrition;
        }

        return $data;
    }

    private function revision(ProductDossier $dossier): string
    {
        return hash('sha256', json_encode([$dossier->product_name, $dossier->product_type, $dossier->data, $dossier->label_analysis, $dossier->label_images, $dossier->expert_assets]));
    }

    private function payload(ProductDossier $dossier, bool $includeData = true): array
    {
        $images = collect($dossier->label_images ?? [])->values()->map(fn (array $image, int $index) => [
            'index' => $index,
            'original_name' => $image['original_name'] ?? 'Etiketfoto '.($index + 1),
            'mime_type' => $image['mime_type'] ?? 'image/jpeg',
            'url' => '/api/product-dossiers/'.$dossier->id.'/labels/'.$index,
        ])->all();

        $payload = [
            'id' => $dossier->id,
            'revision' => $this->revision($dossier),
            'status' => $dossier->status,
            'product_type' => $dossier->product_type,
            'product_name' => $dossier->product_name,
            'label_images' => $images,
            'analysis_status' => $dossier->analysis_status,
            'analysis_error' => $dossier->analysis_error,
            'wordpress_status' => $dossier->wordpress_status,
            'generation' => $dossier->generation,
            'expert_assets' => collect((array) $dossier->expert_assets)->map(fn (array $asset, string $kind) => [
                'url' => '/api/product-dossiers/'.$dossier->id.'/expert-assets/'.$kind,
                'original_name' => $asset['original_name'],
            ])->all(),
            'updated_at' => $dossier->updated_at?->toISOString(),
        ];

        if ($includeData) {
            $payload['image_requests'] = ProductImageRequest::query()->where('user_id', $dossier->user_id)
                ->where('generation_context->product_dossier_id', $dossier->id)->latest()->limit(20)->get()
                ->map(fn ($images) => ['id' => $images->id, 'status' => $images->status, 'count' => count($images->results ?? []), 'updated_at' => $images->updated_at?->toISOString()])->all();
            $payload['data'] = $this->sanitizeDossierData((array) ($dossier->data ?? []));
            $labelAnalysis = (array) ($dossier->label_analysis ?? []);
            unset($labelAnalysis['wettelijke_naam'], $labelAnalysis['legal_name']);
            $payload['label_analysis'] = $labelAnalysis !== [] ? $labelAnalysis : null;
        }

        return $payload;
    }
}

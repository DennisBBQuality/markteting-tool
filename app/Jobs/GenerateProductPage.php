<?php

namespace App\Jobs;

use App\Models\ProductDossier;
use App\Services\ProductDossierAiService;
use App\Services\ProductDossierContent;
use App\Services\ProductDossierMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GenerateProductPage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 540;

    public bool $failOnTimeout = true;

    public function __construct(public string $dossierId, public string $token, public array $snapshot, public string $inputHash, public string $operation = 'page')
    {
        $this->onConnection((string) config('services.product_content.queue_connection', 'database'));
        $this->onQueue('product-content');
    }

    public function handle(ProductDossierAiService $ai): void
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        $claimed = DB::transaction(function (): bool {
            $dossier = ProductDossier::lockForUpdate()->find($this->dossierId);
            if (! $dossier || data_get($dossier->generation, 'token') !== $this->token || data_get($dossier->generation, 'status') !== 'queued') {
                return false;
            }
            $dossier->update(['generation' => [...$dossier->generation, 'status' => 'processing', 'started_at' => now()->toISOString()]]);

            return true;
        });
        if (! $claimed) {
            return;
        }
        try {
            $snapshot = new ProductDossier($this->snapshot);
            $snapshot->id = $this->dossierId;
            $generated = $this->operation === 'page' ? $ai->generateProductPage($snapshot) : $this->analyze($ai, $snapshot);
            DB::transaction(function () use ($generated): void {
                $dossier = ProductDossier::lockForUpdate()->find($this->dossierId);
                if (! $dossier || data_get($dossier->generation, 'token') !== $this->token) {
                    return;
                }
                if ($this->operation === 'page') {
                    ProductDossierContent::apply($dossier, $generated, $this->inputHash);
                } else {
                    $this->applyAnalysis($dossier, $generated);
                }
                $dossier->update(['generation' => [...$dossier->generation, 'status' => 'completed', 'finished_at' => now()->toISOString(), 'error' => null]]);
            });
        } catch (Throwable $exception) {
            $this->failed($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Product page generation failed.', ['dossier_id' => $this->dossierId, 'exception' => $exception ? get_class($exception) : null]);
        DB::transaction(function () use ($exception): void {
            $dossier = ProductDossier::lockForUpdate()->find($this->dossierId);
            if ($dossier && data_get($dossier->generation, 'token') === $this->token && data_get($dossier->generation, 'status') !== 'completed') {
                $message = $exception instanceof RuntimeException && ! $exception instanceof QueryException
                    ? $exception->getMessage() : 'De pagina kon niet worden gemaakt. Je invoer en eerdere tekst zijn bewaard. Probeer opnieuw.';
                $dossier->update(['generation' => [...$dossier->generation, 'status' => 'failed', 'finished_at' => now()->toISOString(), 'error' => $message]]);
            }
        });
    }

    private function analyze(ProductDossierAiService $ai, ProductDossier $snapshot): array
    {
        $images = [];
        if ($this->operation === 'label') {
            foreach ((array) $snapshot->label_images as $image) {
                $contents = ProductDossierMedia::contents($this->dossierId, $image);
                if ($contents !== null) {
                    $images[] = ['contents' => $contents, 'mime_type' => $image['mime_type']];
                }
            }
            if ($images === []) {
                throw new RuntimeException('De etiketfoto’s ontbreken. Voeg ze opnieuw toe.');
            }
        }
        $analysis = $ai->analyzeLabels($images, '', [
            'productnaam' => $snapshot->product_name, 'producttype' => $snapshot->product_type,
            'productfeiten' => data_get($snapshot->data, 'facts', []),
            'bestaande_ingrediënten' => data_get($snapshot->data, 'ingredients'),
        ]);
        if ($this->operation === 'supplement') {
            // With no label, this response is never allowed to claim label provenance.
            $analysis['ingrediënten_bron'] = ! empty($analysis['ingrediënten']) ? 'ai_schatting' : 'onbekend';
            $analysis['allergenen_bron'] = ! empty($analysis['allergenen']) ? 'ai_schatting' : 'onbekend';
            $analysis['voedingswaarden'] = [];
        }

        $data = ProductDossierContent::mergeComposition((array) $snapshot->data, $analysis);
        $nutrition = ProductDossierContent::mergeNutrition((array) ($data['nutrition'] ?? []), (array) ($analysis['voedingswaarden'] ?? []), 'etiket');
        $missing = array_filter(['energie_kj', 'energie_kcal', 'vetten', 'verzadigde_vetten', 'koolhydraten', 'suikers', 'eiwitten', 'zout'], fn ($key) => ! ProductDossierContent::hasValue($nutrition[$key] ?? null));
        if ($missing !== []) {
            try {
                $analysis['nutrition_estimate'] = $ai->estimateNutrition([...$data, 'product_name' => $snapshot->product_name, 'product_type' => $snapshot->product_type]);
            } catch (RuntimeException $exception) {
                // Preserve successfully extracted facts if the optional second call fails.
                $analysis['waarschuwingen'][] = 'De ingrediënten/etiketgegevens zijn verwerkt, maar de ontbrekende voedingswaarden konden niet worden aangevuld. '.$exception->getMessage();
            }
        }

        return ProductDossierContent::reviewLabelStorage($analysis);
    }

    private function applyAnalysis(ProductDossier $dossier, array $analysis): void
    {
        if (ProductDossierContent::inputHash($dossier) !== $this->inputHash) {
            throw new RuntimeException('De productgegevens zijn tijdens de analyse veranderd. Er is niets overschreven. Start de analyse opnieuw met de nieuwe gegevens.');
        }
        $data = ProductDossierContent::mergeComposition((array) $dossier->data, $analysis);
        if ($this->operation === 'label') {
            foreach (['brand' => 'merk', 'origin' => 'herkomst', 'supplier' => 'producent', 'storage' => 'bewaaradvies'] as $field => $source) {
                if ($field === 'storage' && ! empty($analysis['storage_needs_review'])) {
                    continue;
                }
                if (! ProductDossierContent::hasValue($data['facts'][$field] ?? null) && ProductDossierContent::hasValue($analysis[$source] ?? null)) {
                    $data['facts'][$field] = $analysis[$source];
                }
            }
            $data['nutrition'] = ProductDossierContent::mergeNutrition((array) ($data['nutrition'] ?? []), (array) ($analysis['voedingswaarden'] ?? []), 'etiket');
            $dossier->label_analysis = $analysis;
            $dossier->analysis_status = 'geanalyseerd';
            $dossier->analysis_error = null;
        }
        $estimate = $analysis['nutrition_estimate'] ?? [];
        $data['nutrition'] = ProductDossierContent::mergeNutrition((array) ($data['nutrition'] ?? []), [...(array) ($estimate['voedingswaarden'] ?? []), 'aannames' => $estimate['aannames'] ?? [], 'waarschuwing' => $estimate['waarschuwing'] ?? null], 'ai_schatting');
        $data['composition_warnings'] = $analysis['waarschuwingen'] ?? [];
        $dossier->data = $data;
        $dossier->save();
    }
}

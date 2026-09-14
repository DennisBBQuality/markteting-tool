<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ProductImageModelCatalog
{
    public const PREFERRED = 'gpt-image-2.5-sunburst';

    // API compatibility from official documentation; not a product-photo quality approval.
    private const KNOWN = [
        'gpt-image-2.5-sunburst' => 'GPT Image 2.5 Sunburst — nauwkeurig bewerken',
        'gpt-image-2.5-flare' => 'GPT Image 2.5 Flare — snelle beeldgeneratie',
        'gpt-image-2' => 'GPT Image 2 — eerdere versie',
    ];

    public function __construct(private readonly AiCredentialStore $credentials) {}

    public function selected(): string
    {
        $stored = Schema::hasTable('product_image_model_settings')
            ? DB::table('product_image_model_settings')->where('id', 1)->value('model') : null;

        return $stored ?: (string) config('services.product_images.openai.model', self::PREFERRED);
    }

    public function saveDefault(string $model): void
    {
        DB::table('product_image_model_settings')->updateOrInsert(['id' => 1], [
            'model' => $model, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function catalog(bool $refresh = false): array
    {
        $key = $this->credentials->openAiApiKey();
        $snapshot = null;
        $warning = null;
        if ($key !== null) {
            // Account-scoped cache: never reuse availability after changing the API key.
            $cacheKey = 'product-image-models:v1:'.hash('sha256', $key);
            $snapshot = Cache::get($cacheKey);
            $warning = Cache::get($cacheKey.':warning');
            $due = ! $snapshot || $snapshot['checked_at'] < now()->subDay()->timestamp;
            if (($refresh || $due) && ! $warning && Cache::add($cacheKey.':refresh', true, 60)) {
                try {
                    $response = Http::withToken($key)->acceptJson()->connectTimeout(3)->timeout(8)
                        ->get('https://api.openai.com/v1/models');
                    $rows = $response->json('data');
                    if (! $response->successful() || ! is_array($rows) || ! array_is_list($rows)) {
                        $warning = 'De modellenlijst kon niet worden vernieuwd. Controleer de API-rechten of probeer het later opnieuw.';
                    } else {
                        $models = [];
                        foreach (array_slice($rows, 0, 10000) as $row) {
                            $id = is_array($row) ? ($row['id'] ?? null) : null;
                            if (is_string($id) && $this->isImageModel($id)
                                && in_array($row['owned_by'] ?? '', ['openai', 'system'], true)) {
                                $models[$id] = $id;
                            }
                        }
                        $snapshot = ['ids' => array_values($models), 'checked_at' => now()->timestamp];
                        Cache::put($cacheKey, $snapshot, now()->addDays(7));
                        Cache::forget($cacheKey.':warning');
                    }
                } catch (ConnectionException) {
                    $warning = 'OpenAI is nu niet bereikbaar. De laatst bekende modellenlijst blijft beschikbaar.';
                }
                if ($warning) {
                    Cache::put($cacheKey.':warning', $warning, 300);
                }
            }
        } else {
            $warning = 'Voorbeeldmodus: stel een API-koppeling in om de beschikbare modellen bij OpenAI op te halen.';
        }

        $selected = $this->selected();
        $ids = array_unique([...array_keys(self::KNOWN), ...($snapshot['ids'] ?? []), $selected]);
        $models = array_map(function (string $id) use ($snapshot, $key): array {
            $base = preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $id);
            $known = isset(self::KNOWN[$base]);

            return [
                'id' => $id,
                'label' => (self::KNOWN[$base] ?? $id).($base !== $id ? ' ('.substr($id, -10).')' : ''),
                'available' => $key === null ? $known : ($snapshot ? in_array($id, $snapshot['ids'], true) : null),
                'experimental' => ! $known,
            ];
        }, $ids);
        usort($models, fn (array $a, array $b) => ($a['id'] !== self::PREFERRED) <=> ($b['id'] !== self::PREFERRED)
            ?: strcmp($b['id'], $a['id']));

        return [
            'default_model' => $selected,
            'models' => $models,
            'checked_at' => isset($snapshot['checked_at']) ? gmdate('c', $snapshot['checked_at']) : null,
            'warning' => $warning,
            'preview' => $key === null,
        ];
    }

    public function validateSelection(string $model, bool $acceptExperimental = false): string
    {
        $entry = collect($this->catalog()['models'])->firstWhere('id', $model);
        if (! $this->isImageModel($model) || ! $entry || $entry['available'] !== true) {
            throw ValidationException::withMessages(['image_model' => 'Dit afbeeldingsmodel is niet beschikbaar in de huidige modellenlijst. Vernieuw de lijst of controleer de API-koppeling.']);
        }
        if ($entry['experimental'] && ! $acceptExperimental) {
            throw ValidationException::withMessages(['image_model' => 'Dit nieuwe model is nog niet getest met Pitboard. Bevestig eerst dat je het als test wilt gebruiken.']);
        }

        return $model;
    }

    private function isImageModel(string $id): bool
    {
        // /models has no endpoint capabilities. New GPT Image families are candidates,
        // never automatically promoted to the team default or claimed compatible.
        return strlen($id) <= 120
            && preg_match('/^gpt-image-(\d+(?:\.\d+)?)(?:-[a-z0-9]+)*$/D', $id, $matches) === 1
            && (float) $matches[1] >= 2;
    }
}

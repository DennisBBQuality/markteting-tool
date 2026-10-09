<?php

namespace App\Services;

use App\Jobs\GenerateProductImageSeo;
use App\Models\ProductImageAsset;
use App\Models\ProductImageMetadata;
use App\Models\ProductImageRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductImageSeo
{
    public const STORAGE_ERROR = 'De SEO-opslag moet nog worden bijgewerkt. Kies SEO opnieuw maken: de applicatie probeert dan eerst de gerichte opslagupdate. De foto blijft bewaard. Als dit opnieuw mislukt, moet de beheerder de migratiestatus controleren.';

    public const FIELDS = ['filename', 'alt', 'title', 'caption', 'description'];

    public function ensureStorageReady(bool $repair = false): void
    {
        // Only an authorized SEO write/job may opt in. Reads and filename
        // reservation transactions must never execute database migrations.
        if ($repair && ! Schema::hasTable('product_image_download_names')) {
            try {
                if (Artisan::call('pitboard:upgrade-image-seo-storage') !== 0) {
                    throw new ProductImageSeoException(self::STORAGE_ERROR);
                }
            } catch (Throwable $exception) {
                Log::warning('Targeted image SEO storage upgrade failed', ['exception_class' => get_class($exception)]);
                throw new ProductImageSeoException(self::STORAGE_ERROR);
            }
        }
        if (! Schema::hasTable('product_image_download_names')) {
            throw new ProductImageSeoException(self::STORAGE_ERROR);
        }
    }

    public static function productFocused(array $context): bool
    {
        return in_array($context['product_type'] ?? '', ['sauce', 'accessory'], true);
    }

    public static function completeFields(?array $fields, array $context = []): bool
    {
        try {
            return $fields !== null && self::normalize($fields, $context)['filename'] === ($fields['filename'] ?? null);
        } catch (ValidationException) {
            return false;
        }
    }

    public static function normalizeForImage(array $fields, array $context, array $result): array
    {
        return self::normalize(ProductImagePreparationSeo::complete(self::normalize($fields, $context), $context, $result), $context);
    }

    public static function normalize(array $fields, array $context = []): array
    {
        $clean = [];
        foreach (self::FIELDS as $key) {
            // Empty form strings become null in Laravel's request middleware.
            // The key must still be present: a missing AI field is incomplete output.
            if ($key === 'caption' && self::productFocused($context) && array_key_exists($key, $fields)
                && ($fields[$key] === null || is_string($fields[$key]))) {
                $clean[$key] = trim(preg_replace('/\s+/u', ' ', strip_tags($fields[$key] ?? '')));
                if (mb_strlen($clean[$key]) > 400) {
                    throw ValidationException::withMessages([$key => 'Dit SEO-veld is te lang.']);
                }

                continue;
            }
            if (! is_string($fields[$key] ?? null) || trim($fields[$key]) === '') {
                throw ValidationException::withMessages([$key => 'Vul alle verplichte SEO-velden in.']);
            }
            $clean[$key] = trim(preg_replace('/\s+/u', ' ', strip_tags($fields[$key])));
            if ($clean[$key] === '' || mb_strlen($clean[$key]) > ($key === 'description' ? 1600 : 400)) {
                throw ValidationException::withMessages([$key => 'Dit SEO-veld is leeg of te lang.']);
            }
        }
        $name = preg_replace('/\.webp$/i', '', $clean['filename']);
        $name = preg_replace('/varkens[\s_-]+wangen/i', 'varkenswangen', $name);
        $name = preg_replace('/aardappel[\s_-]+puree/i', 'aardappelpuree', $name);
        if (preg_match('/\p{N}/u', $name)) {
            throw ValidationException::withMessages(['filename' => 'Gebruik een bestandsnaam zonder cijfers of volgnummers. Beschrijf een herkenbaar detail van deze foto.']);
        }
        $slug = trim(Str::limit(Str::slug(str_replace('_', ' ', $name)), 180, ''), '-');
        if ($slug === '') {
            throw ValidationException::withMessages(['filename' => 'Gebruik een beschrijvende bestandsnaam.']);
        }
        if (preg_match('/[0-9]/', $slug)) {
            throw ValidationException::withMessages(['filename' => 'Gebruik een bestandsnaam zonder cijfers of volgnummers. Beschrijf een herkenbaar detail van deze foto.']);
        }
        $clean['filename'] = $slug.'.webp';

        return $clean;
    }

    public function record(ProductImageAsset $asset): ?ProductImageMetadata
    {
        return ProductImageMetadata::where('product_image_asset_id', $asset->id)->where('image_version', $asset->version)->first();
    }

    public function payload(ProductImageAsset $asset): array
    {
        $row = $this->record($asset);
        if ($row && in_array($row->status, ['queued', 'processing']) && $row->updated_at->lt(now()->subMinutes($row->status === 'queued' ? 10 : 4))) {
            ProductImageMetadata::whereKey($row->id)->where('job_token', $row->job_token)
                ->where('status', $row->status)->where('updated_at', $row->getRawOriginal('updated_at'))->update([
                    'status' => 'failed', 'error' => 'De SEO-analyse duurde te lang. De foto en vorige SEO zijn bewaard. Probeer opnieuw.', 'job_token' => null,
                ]);
            $row->refresh();
        }

        $storageReady = Schema::hasTable('product_image_download_names');
        $request = ProductImageRequest::findOrFail($asset->product_image_request_id);
        $context = (array) $request->generation_context;
        $result = collect($request->results)->firstWhere('filename', $asset->filename) ?? [];

        return ['status' => $row?->status ?? 'idle', 'source' => $row?->source ?? 'none',
            'ready' => $row?->status === 'completed' && self::completeFields($row?->fields, $context) && $asset->refinement_status === 'idle',
            'optional_fields' => self::productFocused($context) ? ['caption'] : [],
            'visible_kitchen_scene' => ProductImagePreparationSeo::usesVisibleKitchenScene($context, $result),
            'storage_ready' => $storageReady,
            'revision' => $row?->revision ?? 0, 'error' => $storageReady ? $row?->error : self::STORAGE_ERROR, 'image_version' => $asset->version];
    }

    public function queue(ProductImageAsset $asset, ?int $expectedRevision = null, bool $replaceManual = false, bool $alreadyBackground = false): void
    {
        $job = $this->prepareJob($asset, $expectedRevision, $replaceManual);
        if ($job === null) {
            return;
        }
        try {
            $connection = (string) config('services.product_images.queue_connection', 'deferred');
            if ($alreadyBackground && $connection === 'deferred') {
                dispatch_sync($job);
            } else {
                dispatch($job->onConnection($connection));
            }
        } catch (Throwable $error) {
            $job->failed($error);
        }
    }

    private function prepareJob(ProductImageAsset $asset, ?int $expectedRevision = null, bool $replaceManual = false): ?GenerateProductImageSeo
    {
        $token = DB::transaction(function () use ($asset, $expectedRevision, $replaceManual) {
            $locked = ProductImageAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail(['id', 'version', 'refinement_status']);
            abort_if($locked->version !== $asset->version || $locked->refinement_status !== 'idle', 409, 'De foto is gewijzigd of wordt aangepast. Open de actuele versie.');
            $row = ProductImageMetadata::firstOrCreate(['product_image_asset_id' => $asset->id, 'image_version' => $asset->version]);
            abort_if($expectedRevision !== null && $row->revision !== $expectedRevision, 409, 'De SEO is intussen gewijzigd. Open de actuele velden.');
            if (in_array($row->status, ['queued', 'processing'])) {
                return null;
            }
            abort_if($row->source === 'manual' && ! $replaceManual, 409, 'Bevestig eerst het vervangen van handmatige SEO.');
            $token = (string) Str::uuid();
            $row->update(['status' => 'queued', 'error' => null, 'job_token' => $token]);

            return $token;
        });

        return $token === null ? null : new GenerateProductImageSeo($asset->id, $asset->version, $token);
    }

    /** Register all photos before announcing image completion, including those in later waves. */
    public function prepareAutomaticJobs(iterable $assets): array
    {
        $jobs = [];
        foreach ($assets as $asset) {
            try {
                $record = $this->record($asset);
                if ($record?->status === 'completed' || $record?->source === 'manual') {
                    continue;
                }
                if ($job = $this->prepareJob($asset)) {
                    $jobs[$asset->id] = $job;
                }
            } catch (Throwable) {
                Log::warning('Automatic image SEO could not be scheduled.', ['asset_id' => $asset->id, 'version' => $asset->version]);
            }
        }

        return $jobs;
    }

    public function runAutomaticJobs(array $jobs): void
    {
        $connection = (string) config('services.product_images.queue_connection', 'deferred');
        if ($connection !== 'deferred') {
            foreach ($jobs as $job) {
                try {
                    dispatch($job->onConnection($connection));
                } catch (Throwable $error) {
                    $job->failed($error);
                }
            }

            return;
        }

        // Already in deferred background work: nesting deferred callbacks can lose jobs.
        // Three simultaneous analyses, saved as responses arrive, instead of seven serial waits.
        foreach (array_chunk($jobs, 3, true) as $wave) {
            $inputs = [];
            foreach ($wave as $id => $job) {
                if ($input = $job->prepare()) {
                    $inputs[$id] = $input;
                }
            }
            if ($inputs === []) {
                continue;
            }
            $started = microtime(true);
            try {
                app(ProductImageSeoAnalyzer::class)->analyzeBatch($inputs, function ($id, $result) use ($wave) {
                    $result instanceof Throwable ? $wave[$id]->failed($result) : $wave[$id]->complete($result);
                });
            } catch (Throwable $error) {
                foreach ($wave as $job) {
                    $job->failed($error);
                }
            }
            Log::info('Image SEO wave finished', ['asset_ids' => array_keys($inputs), 'duration_ms' => (int) round((microtime(true) - $started) * 1000)]);
        }
    }

    /** SEO scheduling must never turn a successfully stored photo into a failed image job. */
    public function queueAutomatically(ProductImageAsset $asset): void
    {
        try {
            $this->queue($asset, alreadyBackground: true);
        } catch (Throwable) {
            // A concurrent refinement may already own a newer version. The SEO editor
            // can start analysis again; do not overwrite that version or expose errors.
            Log::warning('Automatic image SEO could not be scheduled.', ['asset_id' => $asset->id, 'version' => $asset->version]);
        }
    }

    public function save(ProductImageAsset $asset, array $fields, int $revision): void
    {
        $context = (array) ProductImageRequest::findOrFail($asset->product_image_request_id)->generation_context;
        $fields = self::normalize($fields, $context);
        $this->ensureStorageReady(repair: true);
        DB::transaction(function () use ($asset, $fields, $revision) {
            // Serialize filenames per photoset, and edits against image refinement.
            $request = ProductImageRequest::whereKey($asset->product_image_request_id)->lockForUpdate()->firstOrFail();
            $current = ProductImageAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            abort_if($current->version !== $asset->version || $current->refinement_status !== 'idle', 409, 'De foto is gewijzigd. Open de actuele versie.');
            $row = ProductImageMetadata::firstOrCreate(['product_image_asset_id' => $asset->id, 'image_version' => $asset->version]);
            abort_if($row->revision !== $revision, 409, 'De SEO is intussen gewijzigd. Open de actuele velden voordat je opnieuw opslaat.');
            $result = collect($request->results)->firstWhere('filename', $asset->filename) ?? [];
            $fields = self::normalizeForImage($fields, (array) $request->generation_context, $result);
            $row->update(['fields' => $this->uniqueFilename($asset, $fields), 'source' => 'manual', 'status' => 'completed',
                'revision' => $row->revision + 1, 'job_token' => null, 'error' => null]);
        });
    }

    /** Caller holds the asset/photoset locks. A unique DB key also protects parallel photosets. */
    public function uniqueFilename(ProductImageAsset $asset, array $fields, array $alternatives = []): array
    {
        $this->ensureStorageReady();
        $request = ProductImageRequest::findOrFail($asset->product_image_request_id);
        $result = collect($request->results)->firstWhere('filename', $asset->filename) ?? [];
        foreach (array_unique(array_filter([$fields['filename'], ...array_slice($alternatives, 0, 5)], 'is_string')) as $candidate) {
            try {
                $candidateFields = self::normalizeForImage([...$fields, 'filename' => $candidate], (array) $request->generation_context, $result);
            } catch (ValidationException) {
                continue;
            }
            $name = $candidateFields['filename'];
            // Old metadata is deliberately not migrated or renamed. Respect those names too.
            if (ProductImageMetadata::where('product_image_asset_id', '!=', $asset->id)->where('fields->filename', $name)->exists()) {
                continue;
            }
            $reserved = DB::table('product_image_download_names')->insertOrIgnore([
                'filename' => $name, 'product_image_asset_id' => $asset->id,
            ]);
            if ($reserved || DB::table('product_image_download_names')->where('filename', $name)->where('product_image_asset_id', $asset->id)->exists()) {
                return $candidateFields;
            }
        }

        throw ValidationException::withMessages(['filename' => 'Deze bestandsnaam is al in gebruik. Beschrijf een ander zichtbaar detail van deze foto of maak de SEO opnieuw. Er wordt geen volgnummer toegevoegd.']);
    }
}

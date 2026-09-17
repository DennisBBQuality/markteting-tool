<?php

namespace App\Services;

use App\Jobs\GenerateProductImageSeo;
use App\Models\ProductImageAsset;
use App\Models\ProductImageMetadata;
use App\Models\ProductImageRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductImageSeo
{
    public const FIELDS = ['filename', 'alt', 'title', 'caption', 'description'];

    public static function normalizeForImage(array $fields, array $context, array $result): array
    {
        return self::normalize(ProductImagePreparationSeo::complete(self::normalize($fields), $context, $result));
    }

    public static function normalize(array $fields): array
    {
        $clean = [];
        foreach (self::FIELDS as $key) {
            if (! is_string($fields[$key] ?? null) || trim($fields[$key]) === '') {
                throw ValidationException::withMessages([$key => 'Vul alle vijf SEO-velden in.']);
            }
            $clean[$key] = trim(preg_replace('/\s+/u', ' ', strip_tags($fields[$key])));
            if ($clean[$key] === '' || mb_strlen($clean[$key]) > ($key === 'description' ? 1600 : 400)) {
                throw ValidationException::withMessages([$key => 'Dit SEO-veld is leeg of te lang.']);
            }
        }
        $name = preg_replace('/\.webp$/i', '', $clean['filename']);
        $name = preg_replace('/varkens[\s_-]+wangen/i', 'varkenswangen', $name);
        $name = preg_replace('/aardappel[\s_-]+puree/i', 'aardappelpuree', $name);
        $slug = trim(Str::limit(Str::slug(str_replace('_', ' ', $name)), 180, ''), '-');
        if ($slug === '') {
            throw ValidationException::withMessages(['filename' => 'Gebruik een beschrijvende bestandsnaam.']);
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
        if ($row && in_array($row->status, ['queued', 'processing']) && $row->updated_at->lt(now()->subMinutes($row->status === 'queued' ? 30 : 4))) {
            ProductImageMetadata::whereKey($row->id)->where('job_token', $row->job_token)->update([
                'status' => 'failed', 'error' => 'De SEO-analyse duurde te lang. De foto en vorige SEO zijn bewaard. Probeer opnieuw.', 'job_token' => null,
            ]);
            $row->refresh();
        }

        return ['status' => $row?->status ?? 'idle', 'source' => $row?->source ?? 'none',
            'revision' => $row?->revision ?? 0, 'error' => $row?->error, 'image_version' => $asset->version];
    }

    public function queue(ProductImageAsset $asset, ?int $expectedRevision = null, bool $replaceManual = false, bool $alreadyBackground = false): void
    {
        $token = DB::transaction(function () use ($asset, $expectedRevision, $replaceManual) {
            $locked = ProductImageAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
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
        if ($token !== null) {
            try {
                $connection = (string) config('services.product_images.queue_connection', 'deferred');
                // Laravel's deferred collection does not drain callbacks added while it runs.
                // We are already after the HTTP response in this case; execute the isolated
                // text job here instead of registering a nested callback that may never run.
                if ($alreadyBackground && $connection === 'deferred') {
                    GenerateProductImageSeo::dispatchSync($asset->id, $asset->version, $token);
                } else {
                    GenerateProductImageSeo::dispatch($asset->id, $asset->version, $token)->onConnection($connection);
                }
            } catch (Throwable) {
                ProductImageMetadata::where('job_token', $token)->update(['status' => 'failed', 'job_token' => null,
                    'error' => 'De SEO-analyse kon niet starten. De foto en vorige SEO zijn bewaard.']);
            }
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
        $fields = self::normalize($fields);
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

    /** Caller holds the photoset lock; only current versions reserve download names. */
    public function uniqueFilename(ProductImageAsset $asset, array $fields): array
    {
        $used = ProductImageMetadata::join('product_image_assets as a', 'a.id', '=', 'product_image_metadata.product_image_asset_id')
            ->where('a.product_image_request_id', $asset->product_image_request_id)->where('a.id', '!=', $asset->id)
            ->whereColumn('a.version', 'product_image_metadata.image_version')->get(['product_image_metadata.*'])
            ->pluck('fields')->map(fn ($item) => $item['filename'] ?? '')->all();
        $base = substr($fields['filename'], 0, -5);
        $n = 2;
        while (in_array($fields['filename'], $used, true)) {
            $fields['filename'] = $base.'-'.$n++.'.webp';
        }

        return $fields;
    }
}

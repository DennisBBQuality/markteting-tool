<?php

namespace App\Services;

use App\Models\ProductImageAsset;
use App\Models\ProductImageMetadata;
use App\Models\ProductImageRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ProductImageCancellation
{
    public function stop(ProductImageRequest $request): void
    {
        $queued = DB::transaction(function () use ($request): bool {
            $locked = ProductImageRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $pendingSeo = $this->stopSeoRows(ProductImageAsset::where('product_image_request_id', $locked->id)->pluck('id')->all());
            if (in_array($locked->status, ['queued', 'processing'], true) || $pendingSeo > 0) {
                $waiting = $locked->status === 'processing';
                $wasQueued = $locked->status === 'queued';
                $locked->update(['status' => 'cancelled', 'progress_step' => $waiting ? 'cancelling' : 'cancelled',
                    'error' => null, 'completed_at' => $waiting ? null : now()]);

                return $wasQueued;
            }

            return false;
        });
        if ($queued) {
            $paths = collect($request->source_references ?: [])->pluck('path')->filter()->all();
            $paths[] = $request->source_path;
            Storage::disk('local')->delete(array_values(array_unique($paths)));
        }
    }

    public function stopSeo(ProductImageAsset $asset, int $version, int $revision): void
    {
        DB::transaction(function () use ($asset, $version, $revision): void {
            ProductImageRequest::whereKey($asset->product_image_request_id)->lockForUpdate()->firstOrFail();
            $current = ProductImageAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail(['id', 'version']);
            abort_if($current->version !== $version, 409, 'De foto is gewijzigd. Open de actuele versie.');
            $row = ProductImageMetadata::where('product_image_asset_id', $asset->id)->where('image_version', $version)->lockForUpdate()->first();
            if (! $row || ! in_array($row->status, ['queued', 'processing'], true)) {
                return; // Repeated stop, or completion already won. Never erase completed SEO.
            }
            abort_if($row->revision !== $revision, 409, 'De SEO is gewijzigd. Open de actuele velden.');
            $this->stopSeoRows([$asset->id], $version);
        });
    }

    private function stopSeoRows(array $assetIds, ?int $version = null): int
    {
        if (! Schema::hasTable('product_image_metadata')) {
            return 0;
        }

        return ProductImageMetadata::whereIn('product_image_asset_id', $assetIds)
            ->when($version !== null, fn ($query) => $query->where('image_version', $version))
            ->whereIn('status', ['queued', 'processing'])
            ->update(['status' => 'cancelled', 'job_token' => null, 'revision' => DB::raw('revision + 1'),
                'error' => 'SEO handmatig gestopt. De foto en eerder opgeslagen teksten zijn bewaard. Een al verzonden AI-aanvraag kan nog kosten geven.']);
    }
}

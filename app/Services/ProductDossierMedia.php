<?php

namespace App\Services;

use App\Models\ProductDossierAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProductDossierMedia
{
    public static function store(string $dossierId, UploadedFile $file): array
    {
        // Like generated product images, originals survive releases and web/worker instance changes.
        $asset = ProductDossierAsset::create([
            'product_dossier_id' => $dossierId,
            'mime_type' => $file->getMimeType() ?: 'image/jpeg',
            'contents_base64' => base64_encode($file->getContent()),
        ]);

        return ['asset_id' => $asset->id, 'mime_type' => $asset->mime_type, 'original_name' => $file->getClientOriginalName()];
    }

    public static function contents(string $dossierId, array $metadata): ?string
    {
        if (! empty($metadata['asset_id'])) {
            $asset = ProductDossierAsset::where('product_dossier_id', $dossierId)->find($metadata['asset_id']);
            $contents = $asset ? base64_decode($asset->contents_base64, true) : false;

            return $contents === false ? null : $contents;
        }

        // Older local concepts remain readable without rewriting their records during deployment.
        $path = $metadata['path'] ?? null;

        return is_string($path) && Storage::disk('local')->exists($path) ? Storage::disk('local')->get($path) : null;
    }
}

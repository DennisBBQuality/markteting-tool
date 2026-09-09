<?php

namespace App\Services;

use App\Models\ProductDossier;
use App\Models\ProductImageAsset;
use App\Models\ProductImageRequest;

class ProductDossierExport
{
    public function build(ProductDossier $dossier): array
    {
        $data = (array) $dossier->data;
        $content = (array) ($data['content'] ?? []);
        $paragraphs = fn (string $value) => implode('', array_map(fn ($p) => '<p>'.nl2br(e(trim($p))).'</p>', preg_split('/\n\s*\n/u', $value) ?: []));
        $html = '';
        foreach ((array) ($content['sections'] ?? []) as $section) {
            $html .= '<h2>'.e($section['kop'] ?? '').'</h2>'.$paragraphs((string) ($section['tekst'] ?? ''));
        }
        $expert = (array) ($data['expert'] ?? []);
        $expertApproved = ($expert['approved'] ?? false) && ! empty($expert['name']) && ! empty($expert['tip']);
        $review = [];
        $composition = [];
        foreach (['ingredients', 'allergens'] as $field) {
            $source = $data[$field.'_source_status'] ?? 'onbekend';
            $value = $data[$field] ?? null;
            $composition[$field] = ($source === 'etiket' || data_get($data, 'reviewed.'.$field) === true) && ProductDossierContent::hasValue($value) ? $value : null;
            if ($composition[$field] === null) {
                $review[] = $field === 'ingredients' ? 'Ingrediënten laten bevestigen; niet automatisch publiceren.' : 'Allergenen laten bevestigen; onbekend betekent niet allergenenvrij.';
            }
        }
        $nutrition = (array) ($data['nutrition'] ?? []);
        $verifiedNutrition = ($nutrition['source_status'] ?? '') === 'etiket' || data_get($data, 'reviewed.nutrition') === true ? $nutrition : null;
        if (! $verifiedNutrition) {
            $review[] = 'Voedingswaarden ontbreken of bevatten schattingen/handmatige invoer; bevestigen vóór publicatie.';
        }
        if (! empty($expert['tip']) && ! $expertApproved) {
            $review[] = 'Expertstip nog niet door de genoemde vakman goedgekeurd.';
        }
        if (! empty($content['input_changed_during_generation'])) {
            $review[] = 'Tekst is gebaseerd op eerdere productgegevens; opnieuw controleren.';
        }
        if (empty($content['short_description'])) {
            $review[] = 'Producttekst ontbreekt.';
        }
        $facts = (array) ($data['facts'] ?? []);
        $storageReview = ProductDossierContent::reviewLabelStorage(['bewaaradvies' => $facts['storage'] ?? '']);
        if (! empty($storageReview['storage_needs_review'])) {
            $review[] = 'Bewaaradvies noemt gekoelde bewaring; controleer dit tegen jullie diepgevroren levering. Bewaaradvies niet automatisch geëxporteerd.';
            unset($facts['storage']);
        }

        return [
            'format_version' => 1,
            'product' => [
                'status' => 'draft', 'name' => $dossier->product_name,
                'short_description' => $paragraphs((string) ($content['short_description'] ?? '')),
                'description' => $html,
                'slug' => data_get($content, 'seo.slug'),
            ],
            'pdp_fields' => [
                'facts' => $facts,
                ...$composition,
                'nutrition' => $verifiedNutrition,
                'faqs' => $content['faqs'] ?? [],
                'expert' => $expertApproved ? $expert : null,
            ],
            'seo' => $content['seo'] ?? [],
            'media_to_upload' => ProductImageRequest::query()->where('user_id', $dossier->user_id)
                ->where('generation_context->product_dossier_id', $dossier->id)->get()->flatMap(function ($images) {
                    return collect($images->results ?? [])->map(function (array $result) use ($images) {
                        $asset = ProductImageAsset::where('product_image_request_id', $images->id)->where('filename', $result['filename'])->first();

                        return [
                            ...app(ProductImageDelivery::class)->metadata((array) $images->generation_context, $result, $asset?->version ?? 1),
                            'local_download_url' => '/api/images/requests/'.$images->id.'/generated/'.rawurlencode(pathinfo($result['filename'], PATHINFO_FILENAME)).'?download=1&format=webp',
                            'requires_visual_review' => true,
                        ];
                    });
                })->values()->all(),
            'expert_assets_to_upload' => $expertApproved ? collect((array) $dossier->expert_assets)->map(fn ($asset, $kind) => ['original_name' => $asset['original_name'], 'local_download_url' => '/api/product-dossiers/'.$dossier->id.'/expert-assets/'.$kind])->all() : [],
            // Offer, price, stock, URL and ratings are omitted until real shop data exists.
            'structured_data_draft' => [
                '@context' => 'https://schema.org', '@type' => 'Product',
                'name' => $dossier->product_name,
                'description' => $content['short_description'] ?? '',
            ],
            'internal_review_do_not_publish' => [
                'required' => true,
                'checks' => array_values(array_unique([...$review, ...(array) ($content['control_points'] ?? []), ...(array) data_get($dossier->label_analysis, 'waarschuwingen', [])])),
                'source_data' => array_intersect_key($data, array_flip(['ingredients', 'ingredients_source_status', 'allergens', 'allergens_source_status', 'nutrition', 'expert', 'composition_warnings'])),
                'note' => 'Dit bestand is een conceptpakket, geen uitgevoerde WordPress-koppeling. Stem veldmapping en openbare product- en beeld-URL’s af vóór import.',
            ],
        ];
    }
}

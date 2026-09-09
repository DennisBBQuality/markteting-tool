<?php

namespace App\Services;

use App\Models\ProductDossier;

/** Source data and customer copy deliberately have separate lifecycles. */
class ProductDossierContent
{
    public const VERSION = 5;

    public static function inputHash(ProductDossier $dossier): string
    {
        $data = (array) $dossier->data;
        unset($data['content'], $data['content_history']);

        return hash('sha256', json_encode([$dossier->product_name, $dossier->product_type, $data, $dossier->label_analysis, $dossier->label_images, $dossier->expert_assets]));
    }

    public static function hasValue(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    public static function reviewLabelStorage(array $analysis): array
    {
        $advice = (string) ($analysis['bewaaradvies'] ?? '');
        // A supplier's chilled label is not the storage instruction for our frozen offer.
        if (preg_match('/gekoeld|(?<![-−\d])\b\d+\s*°\s*c/iu', $advice)) {
            $analysis['storage_needs_review'] = true;
            $analysis['waarschuwingen'][] = 'Het etiket noemt gekoelde bewaring ('.$advice.'). Controleer het bewaaradvies voor jullie diepgevroren levering; dit is niet automatisch als klantadvies ingevuld.';
        }

        return $analysis;
    }

    /** Fill empty values only; never replace label data or a colleague's correction. */
    public static function mergeNutrition(array $existing, array $proposal, string $source): array
    {
        $sources = (array) ($existing['field_sources'] ?? []);
        foreach (['basis', 'energie_kj', 'energie_kcal', 'vetten', 'verzadigde_vetten', 'koolhydraten', 'suikers', 'eiwitten', 'zout'] as $key) {
            if (self::hasValue($existing[$key] ?? null)) {
                $sources[$key] ??= $existing['source_status'] ?? 'onbekend';
            } elseif (self::hasValue($proposal[$key] ?? null)) {
                $existing[$key] = $proposal[$key];
                $sources[$key] = $source;
            }
        }
        $types = array_values(array_unique(array_values($sources)));
        $existing['field_sources'] = $sources;
        $existing['source_status'] = count($types) > 1 ? 'gemengd' : ($types[0] ?? 'onbekend');
        if (in_array('ai_schatting', $types, true)) {
            $existing['assumptions'] = $proposal['aannames'] ?? $existing['assumptions'] ?? [];
            $existing['warning'] = $proposal['waarschuwing'] ?? $existing['warning'] ?? 'Bevat AI-schattingen; controle vereist.';
        }

        return $existing;
    }

    public static function mergeComposition(array $data, array $proposal): array
    {
        foreach (['ingredients' => 'ingrediënten', 'allergens' => 'allergenen'] as $key => $sourceKey) {
            if (self::hasValue($data[$key] ?? null)) {
                continue;
            }
            $item = $proposal[$sourceKey] ?? null;
            $value = is_array($item) ? ($item['waarde'] ?? null) : $item;
            if (self::hasValue($value)) {
                $data[$key] = $value;
                $status = is_array($item) ? ($item['source_status'] ?? 'onbekend') : ($proposal[$sourceKey.'_bron'] ?? 'onbekend');
                $data[$key.'_source_status'] = in_array($status, ['etiket', 'ai_schatting', 'afgeleid_van_ingrediënten'], true) ? $status : 'onbekend';
            }
        }

        return $data;
    }

    public static function apply(ProductDossier $dossier, array $generated, string $inputHash): void
    {
        $data = (array) $dossier->data;
        $stale = self::inputHash($dossier) !== $inputHash;
        // A result for older source data must not populate the now changed facts.
        if (! $stale) {
            $data = self::mergeComposition($data, (array) $dossier->label_analysis);
            foreach (['ingrediënten', 'allergenen'] as $field) {
                if (isset($generated[$field]) && is_array($generated[$field])) {
                    $generated[$field]['source_status'] = $field === 'allergenen'
                        && ($data['ingredients_source_status'] ?? '') === 'etiket'
                        && ($generated[$field]['source_status'] ?? '') === 'afgeleid_van_ingrediënten'
                        ? 'afgeleid_van_ingrediënten' : 'ai_schatting';
                }
            }
            $data = self::mergeComposition($data, $generated);
            $nutrition = self::mergeNutrition((array) ($data['nutrition'] ?? []), (array) data_get($dossier->label_analysis, 'voedingswaarden', []), 'etiket');
            $data['nutrition'] = self::mergeNutrition($nutrition, (array) ($generated['voedingswaarden'] ?? []), 'ai_schatting');
        }
        if (! empty($data['content'])) {
            $data['content_history'] = array_slice([...(array) ($data['content_history'] ?? []), $data['content']], -10);
        }
        $data['content'] = [
            'short_description' => (string) ($generated['korte_introductie'] ?? ''),
            'sections' => (array) ($generated['secties'] ?? []),
            'faqs' => (array) ($generated['faqs'] ?? []),
            'seo' => (array) ($generated['seo'] ?? []),
            'control_points' => (array) ($generated['controlepunten'] ?? []),
            'generated_at' => now()->toISOString(),
            'generation_version' => self::VERSION,
            'input_changed_during_generation' => $stale,
        ];
        $dossier->update(['data' => $data, 'status' => 'controle']);
    }
}

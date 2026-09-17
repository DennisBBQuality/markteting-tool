<?php

namespace App\Services;

use App\Models\ProductImageStyleReference;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ProductImageStyleLibrary
{
    private const REFERENCES = [
        'keuken_pan_02' => ['file' => 'keuken-pan-02.png', 'label' => 'Pan op inductie in lichte keuken'],
        'keuken_pan_03' => ['file' => 'keuken-pan-03.png', 'label' => 'Pan op gasfornuis in lichte keuken'],
        'keuken_pan_04' => ['file' => 'keuken-pan-04.png', 'label' => 'Pan met lichte keukenachtergrond'],
        'keuken_oven_02' => ['file' => 'keuken-oven-02.png', 'label' => 'Bakplaat bij open huishoudoven'],
        'keuken_oven_03' => ['file' => 'keuken-oven-03.png', 'label' => 'Bakplaat voor verlichte huishoudoven'],
        'keuken_oven_04' => ['file' => 'keuken-oven-04.png', 'label' => 'Serveerplank voor huishoudoven'],
        'keuken_oven_05' => ['file' => 'keuken-oven-05.png', 'label' => 'Bakplaat met bakpapier bij huishoudoven'],
        'keuken_airfryer_02' => ['file' => 'keuken-airfryer-02.png', 'label' => 'Serveerplank bij airfryer in lichte keuken'],
        'keuken_airfryer_03' => ['file' => 'keuken-airfryer-03.png', 'label' => 'Serveerplank bij airfryer met kruiden'],
        'keuken_airfryer_04' => ['file' => 'keuken-airfryer-04.png', 'label' => 'Open airfryermand in lichte keuken'],
        'keuken_pan' => ['file' => 'keuken-pan.png', 'label' => 'Lichte woonkeuken met pan en fornuis; alleen setting'],
        'keuken_oven' => ['file' => 'keuken-oven.png', 'label' => 'Lichte woonkeuken met huishoudelijke oven; alleen setting'],
        'keuken_airfryer' => ['file' => 'keuken-airfryer.png', 'label' => 'Lichte woonkeuken met airfryer; alleen setting'],
        'vis_rauw_zwart' => [
            'file' => 'vis-rauw-zwart.png',
            'label' => 'BBQuality-visvoorbeeld: zwarte achtergrond en ondergrond, niet het product',
        ],
        'bbq_outdoor_kamado' => [
            'file' => 'bbq-outdoor-kamado.png',
            'label' => 'BBQ-buitenbeeld met kamado',
        ],
        'bbq_outdoor_smoker' => [
            'file' => 'bbq-outdoor-smoker.png',
            'label' => 'BBQ-buitenbeeld met smoker',
        ],
        'serveer_steak_rustiek' => [
            'file' => 'serveer-steak-rustiek.png',
            'label' => 'Rustiek steak-serveerbeeld',
        ],
        'serveer_moink_balls' => [
            'file' => 'serveer-moink-balls.png',
            'label' => 'Sfeervol MOINK-balls-serveerbeeld',
        ],
        'serveer_brisket_broodje' => [
            'file' => 'serveer-brisket-broodje.png',
            'label' => 'Brisket als compleet serveermoment',
        ],
        'serveer_brisket_plank' => [
            'file' => 'serveer-brisket-plank.png',
            'label' => 'Brisket op een rijk opgemaakte serveerplank',
        ],
        'rauw_bbquality_vast' => [
            'file' => 'rauw-bbquality-vast.png',
            'label' => 'Lege vaste BBQuality-achtergrond voor één rauwe variant',
        ],
        'product_buiten_bbquality' => [
            'file' => 'product-buiten-bbquality.png',
            'label' => 'Vaste BBQuality-buitenstijl voor fles of pot',
        ],
        'totaalpakket_rustiek' => [
            'file' => 'totaalpakket-rustiek.png',
            'label' => 'Rustiek totaalpakket op donker hout',
        ],
    ];

    /** Fixed allowlist: never turn request input into a filesystem path. */
    public function kitchenIds(string $group): array
    {
        return match ($group) {
            'pan' => ['keuken_pan', 'keuken_pan_02', 'keuken_pan_03', 'keuken_pan_04'],
            'oven' => ['keuken_oven', 'keuken_oven_02', 'keuken_oven_03', 'keuken_oven_04', 'keuken_oven_05'],
            'airfryer' => ['keuken_airfryer', 'keuken_airfryer_02', 'keuken_airfryer_03', 'keuken_airfryer_04'],
            default => [],
        };
    }

    public function nextKitchenId(string $group, ?string $previous): ?string
    {
        $ids = $this->kitchenIds($group);
        if ($ids === []) {
            return null;
        }
        $index = array_search($previous, $ids, true);

        return $ids[$index === false ? 0 : ($index + 1) % count($ids)];
    }

    public function kitchenId(string $group, mixed $selected): string
    {
        return in_array($selected, $this->kitchenIds($group), true) ? $selected : 'keuken_'.$group;
    }

    /** @return array{id: string, path: string, filename: string, label: string}|null */
    public function reference(?string $id): ?array
    {
        if ($id === null || ! isset(self::REFERENCES[$id])) {
            return null;
        }

        $reference = self::REFERENCES[$id];
        $path = resource_path('product-image-styles/'.$reference['file']);
        if (! is_file($path)) {
            throw new RuntimeException('De BBQuality-stijlreferentie ontbreekt: '.$id);
        }

        return [
            'id' => $id,
            'path' => $path,
            'filename' => 'style-'.$reference['file'],
            'label' => $reference['label'],
        ];
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys(self::REFERENCES);
    }

    /** @return array{id: string, contents_base64: string, filename: string, label: string}|null */
    public function approvedReference(array $context, array $plan): ?array
    {
        if (! Schema::hasTable('product_image_style_references')) {
            return null;
        }

        $productKey = ProductImageStyleReference::productKey((string) ($context['product_name'] ?? ''));
        $status = (string) ($plan['status'] ?? '');
        $styleId = $plan['style_id'] ?? null;
        if ($productKey === '' || $status === '' || ! is_string($styleId) || $styleId === '') {
            return null;
        }

        $reference = ProductImageStyleReference::query()
            ->where('product_key', $productKey)
            ->where('product_type', (string) ($context['product_type'] ?? 'meat'))
            ->where('status', $status)
            ->where('style_id', $styleId)
            ->latest('updated_at')
            ->first();

        if (! $reference) {
            return null;
        }

        return [
            'id' => $reference->id,
            'contents_base64' => $reference->contents_base64,
            'filename' => 'approved-'.$reference->id.'.png',
            'label' => 'Goedgekeurde '.$reference->product_name.'-referentie',
        ];
    }
}

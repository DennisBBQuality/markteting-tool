<?php

namespace App\Services;

use RuntimeException;

class ProductImageStyleLibrary
{
    private const REFERENCES = [
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
        'product_buiten_bbquality' => [
            'file' => 'product-buiten-bbquality.png',
            'label' => 'Vaste BBQuality-buitenstijl voor fles of pot',
        ],
        'totaalpakket_rustiek' => [
            'file' => 'totaalpakket-rustiek.png',
            'label' => 'Rustiek totaalpakket op donker hout',
        ],
    ];

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
}

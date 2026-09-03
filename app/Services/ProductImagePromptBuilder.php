<?php

namespace App\Services;

class ProductImagePromptBuilder
{
    private const COOKED_STYLES = [
        ['id' => 'rustiek_binnen', 'text' => 'Rustieke binnenopname op een donkere houten slagersplank, warm zijlicht en geringe scherptediepte.'],
        ['id' => 'bbq_buiten', 'text' => 'Heldere buitenopname bij een barbecue, warm daglicht, natuurlijke tuin onscherp op de achtergrond.'],
        ['id' => 'serveerbeeld', 'text' => 'Sfeervol serveerbeeld op hout, subtiele passende garnering en een ambachtelijke BBQ-uitstraling.'],
        ['id' => 'donkere_studio', 'text' => 'Donkere premium studio-opname met gericht zacht licht, diepe warme tinten en een rustige achtergrond.'],
        ['id' => 'bereiding', 'text' => 'Ambachtelijke bereidingsscène met snijplank en hoogstens één passend BBQ-hulpmiddel, zonder mensen.'],
    ];

    public function plans(array $context): array
    {
        return match ($context['product_type'] ?? 'meat') {
            'sauce' => $this->saucePlans($context),
            'bundle' => $this->bundlePlans($context),
            default => $this->meatPlans($context),
        };
    }

    public function prompt(string $basePrompt, array $context, array $plan): string
    {
        $name = trim((string) ($context['product_name'] ?? 'product'));
        $quantity = max(1, (int) ($context['quantity'] ?? 1));
        $notes = trim((string) ($context['notes'] ?? ''));
        $components = trim((string) ($context['components'] ?? ''));

        $parts = [
            trim($basePrompt),
            "PRODUCT: {$name}.",
            "HARD AANTALVEREISTE: toon exact {$quantity} exemplaar/exemplaren van het hoofdproduct. Nooit meer en nooit minder.",
            'De eerste referentiefoto is de hoofdfoto. Gebruik de overige referenties om productdetails, vorm, hoeveelheid en merkuitvoering betrouwbaar vast te stellen.',
            $plan['instruction'],
            'BEELDSTIJL: '.$plan['style'],
            'Lever precies één vierkante, fotorealistische afbeelding zonder watermerk, toegevoegde reclametekst of fantasielogo.',
        ];

        if ($notes !== '') {
            $parts[] = 'EXTRA INFORMATIE VAN DE MEDEWERKER: '.$notes;
        }
        if ($components !== '') {
            $parts[] = 'VERPLICHTE INHOUD VAN HET TOTAALPAKKET: '.$components;
        }

        return implode("\n\n", $parts);
    }

    public function refinementPrompt(string $instruction, array $context): string
    {
        $quantity = max(1, (int) ($context['quantity'] ?? 1));

        return "Pas uitsluitend de hieronder gevraagde wijziging toe op deze bestaande productfoto. Behoud alle niet-genoemde onderdelen, compositie, productidentiteit en stijl exact zo veel mogelijk. Behoud exact {$quantity} product(en). Verzin geen tekst, logo, etiket, ingrediënt of extra product.\n\nGEVRAAGDE WIJZIGING: ".trim($instruction);
    }

    private function meatPlans(array $context): array
    {
        $name = strtolower((string) ($context['product_name'] ?? ''));
        $isBeefSteak = str_contains($name, 'steak') || str_contains($name, 'biefstuk') || str_contains($name, 'picanha');
        $styles = self::COOKED_STYLES;
        shuffle($styles);

        return [
            [
                'status' => 'bereid', 'label' => 'Vlees bereid', 'style_id' => $styles[0]['id'],
                'style' => $styles[0]['text'],
                'instruction' => $isBeefSteak
                    ? 'Bereid het rundvlees medium. Snijd deze variant open zodat de medium roze kern, een sappige structuur en een krokante donkere korst zichtbaar zijn.'
                    : 'Bereid dit exacte product op een culinair en producttechnisch geloofwaardige BBQ-manier. Maak een aantrekkelijke, sappige korst en behoud het herkenbare product.',
            ],
            [
                'status' => 'bereid', 'label' => 'Vlees bereid', 'style_id' => $styles[1]['id'],
                'style' => $styles[1]['text'],
                'instruction' => $isBeefSteak
                    ? 'Bereid het rundvlees geloofwaardig en toon het volledig ongesneden. Geef de buitenkant een krokante, goed aangebraden korst. Deze foto moet duidelijk een andere stijl en achtergrond hebben dan de andere bereide variant.'
                    : 'Bereid dit exacte product op een tweede geloofwaardige BBQ-manier. Laat het product heel en gebruik nadrukkelijk een andere stijl en achtergrond dan de andere bereide variant.',
            ],
            [
                'status' => 'rauw', 'label' => 'Vlees rauw', 'style_id' => 'rauw_studio',
                'style' => 'Premium slagersfoto op warm donker hout met een rustige donkere achtergrond.',
                'instruction' => 'Verwijder plastic, vacuümzak, schaal, absorptiemat, stickers en etiketten volledig. Toon het product volledig rauw. Behoud de natuurlijk dieprode vleeskleur, correcte marmering, vetkap, snit en vorm.',
            ],
            [
                'status' => 'rauw', 'label' => 'Vlees rauw', 'style_id' => 'rauw_licht',
                'style' => 'Lichtere ambachtelijke productopname op een houten slagersplank, andere hoek en compositie dan de eerste rauwe foto.',
                'instruction' => 'Verwijder alle verpakking volledig. Toon hetzelfde product volledig rauw, met exact dezelfde hoeveelheid en anatomisch correcte structuur, kleur, marmering en vetverdeling.',
            ],
        ];
    }

    private function saucePlans(array $context): array
    {
        $exact = 'Behoud de fles of pot, dop, vorm, verhoudingen, kleuren, het volledige etiket, logo en iedere zichtbare letter exact volgens de referentie. Verander of verzin geen enkel woord. Het product blijft verpakt en staat rechtop als hoofdonderwerp.';

        return [
            ['status' => 'product', 'label' => 'Productfoto', 'style_id' => 'bbquality_buiten', 'instruction' => $exact,
                'style' => 'Vaste BBQuality-huisstijl: warme houten tafel, natuurlijk zonnig licht en een groene tuin zacht onscherp op de achtergrond.'],
            ['status' => 'product', 'label' => 'Productfoto', 'style_id' => 'bbquality_donker', 'instruction' => $exact,
                'style' => 'Donkere premium studio-opname op warm hout met zacht gericht licht; duidelijk anders dan de buitenvariant maar passend binnen dezelfde BBQuality-serie.'],
        ];
    }

    private function bundlePlans(array $context): array
    {
        $instruction = 'Maak één totaalbeeld met uitsluitend alle aangeleverde en beschreven onderdelen. Alle eetbare vleesproducten blijven volledig rauw. Behoud van ieder onderdeel het exacte aantal. Verpakte merkproducten behouden hun echte verpakking en etiket; verzin niets.';

        return [
            ['status' => 'totaal', 'label' => 'Totaalbeeld', 'style_id' => 'pakket_bovenaanzicht', 'instruction' => $instruction,
                'style' => 'Geordend premium bovenaanzicht op een grote donkere houten werktafel, met elk onderdeel volledig zichtbaar.'],
            ['status' => 'totaal', 'label' => 'Totaalbeeld', 'style_id' => 'pakket_hero', 'instruction' => $instruction,
                'style' => 'Lage hero-compositie op rustiek hout met warme studioverlichting, een andere indeling dan het bovenaanzicht en elk onderdeel duidelijk herkenbaar.'],
        ];
    }
}

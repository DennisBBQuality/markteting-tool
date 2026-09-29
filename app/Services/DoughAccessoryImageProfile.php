<?php

namespace App\Services;

use App\Models\ImagePrompt;

/** Fixed three-photo sets; never inherit meat anatomy or automatic cooking of accessories. */
class DoughAccessoryImageProfile
{
    public function plans(array $context): array
    {
        $dough = ($context['product_type'] ?? '') === 'dough';
        $pizza = $dough && ($context['dough_kind'] ?? 'bread') === 'pizza_balls';
        $family = $dough ? ($pizza ? 'pizzabollen' : 'brood') : 'accessoire';
        $label = $dough ? 'Deeg' : 'Accessoires';
        $preserve = $dough
            ? 'Toon het brood of deeg zoals aangeleverd: behoud vorm, kleur, oppervlak, sneden en aantal uit de productreferenties. Bak rauwe deegbollen niet voor deze productfoto; maak van bestaand brood geen rauw deeg. Verwijder alleen losse transportverpakking, geen delen van het product.'
            : 'Toon exact het aangeleverde accessoire. Behoud geometrie, afmetingen in verhouding, materiaal, afwerking, kleur, onderdelen, logo’s en zichtbare opschriften. Niet bakken, vervormen, smelten of als voedsel behandelen. Verzin geen functionaliteit, hittebestendigheid, maatvoering, merken of tekst. Plaats het product veilig naast apparatuur; niet in vuur of een hete oven zonder aangetoonde geschiktheid.';

        $home = ! $dough ? $preserve : ($pizza
            ? 'Toon de aangeleverde deegbollen herkenbaar op het werkblad met een compacte elektrische pizzaoven voor binnengebruik op de achtergrond. Geen houtvuur of rook binnenshuis. Behoud exact het opgegeven aantal deegbollen; geen extra pizza, deeg of voedsel in de oven.'
            : 'Toon hetzelfde type brood terwijl het in een herkenbare huishoudelijke oven wordt gebakken, met geopende ovendeur en het brood goed zichtbaar op de bakplaat. Behoud de oorspronkelijke broodvorm en hoeveelheid; alleen natuurlijke rijs en bruining zijn toegestaan wanneer het aangeleverde product nog ongebakken is. Reeds gebakken brood blijft herkenbaar, niet verkoold. Geen pizzaoven, geen pizza en geen tweede portie op het aanrecht.');
        $bbq = ! $dough ? $preserve : ($pizza
            ? 'Maak van iedere aangeleverde deegbol één pizza die op een pizzasteen in een open kamado wordt gebakken. Het opgegeven aantal deegbollen wordt hetzelfde aantal pizza’s, nooit daarnaast nog losse deegbollen. Toon steen en kamado herkenbaar. Natuurlijke gerezen rand en bruining, niet verbrand; geen vleesstructuur of grillstrepen op het deeg. Bescheiden pizzabeleg is alleen een serveersuggestie, geen claim over meegeleverde ingrediënten. Houd de hoeveelheid en schaal geloofwaardig; geen pizza’s stapelen of verkleinen om ze passend te maken.'
            : 'Toon hetzelfde brood in een buiten-BBQ-setting bij een herkenbare kamado, op een serveerplank naast de barbecue. Behoud broodtype, vorm en exact aantal, met natuurlijke korst en kruim waar die oorspronkelijk zichtbaar zijn. Geen pizza, extra broden of verzonnen vulling.');

        return [
            [
                'status' => 'product', 'label' => $label.' · Zwart & hout',
                'style_id' => $family.'_zwart_hout', 'scene_family' => 'product_zwart_hout',
                'style_reference_id' => 'rauw_bbquality_vast', 'keep_style_reference' => true,
                'instruction' => $preserve,
                'style' => 'VASTE BBQUALITY-ACHTERGROND: diepzwart egaal achtervlak bovenin, warme natuurlijke houten planken onderin. Neem vlakverdeling, houtnerf, camerahoek en belichting over uit de lege achtergrondreferentie. Het volledige product staat centraal op het hout met een echte contactschaduw en ruimte rondom. Geen extra bord, doek, kruiden of decoratie.',
            ],
            [
                'status' => $dough && ! $pizza ? 'bereid' : 'product', 'label' => $label.' · Huiselijk',
                'style_id' => $family.'_huiselijk', 'scene_family' => 'woonkeuken_natuurlijk',
                'style_reference_id' => 'keuken_oven', 'keep_style_reference' => true,
                'instruction' => $home,
                'style' => 'Een normale, herkenbare woonkeuken zoals de bestaande Pan-, Oven- en Airfryer-sfeer, maar iets minder licht. Gebruik middentonen, natuurlijk hout, rustige warmgrijze kasten en zacht zijlicht. Geen overwegend witte high-key showroom, geen donkere studio en geen oranje kleurzweem. Het hoofdproduct blijft helder, scherp en volledig zichtbaar. De achtergrond is rustig en iets onscherp. De expliciet gevraagde apparatuur heeft voorrang op het apparaat in het stijlvoorbeeld.',
            ],
            [
                'status' => $pizza ? 'bereid' : 'product', 'label' => $label.' · BBQ',
                'style_id' => $family.'_bbq', 'scene_family' => 'buiten_bbq',
                'style_reference_id' => 'bbq_outdoor_kamado', 'keep_style_reference' => true,
                'instruction' => $bbq,
                'style' => 'De bestaande BBQuality-BBQ-stijl buiten: natuurlijk hout, zacht gefilterd daglicht, een herkenbare kamado en groen in de achtergrond. Neutrale witbalans, rustige compositie, subtiele schaduwen en voldoende ruimte rondom het volledige hoofdproduct. Bij pizza is de kamado met pizzasteen de baklocatie, niet slechts een achtergrondobject.',
            ],
        ];
    }

    public function prompt(string $basePrompt, array $context, array $plan): string
    {
        $quantity = max(1, (int) ($context['quantity'] ?? 1));
        $count = max(1, (int) ($context['product_reference_count'] ?? 1));
        $dough = ($context['product_type'] ?? '') === 'dough';
        $parts = [
            trim($basePrompt) === trim(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT)
                ? 'Maak een fotorealistische BBQuality-webshopfoto van het aangeleverde product, met behoud van identiteit en hoeveelheid. Volg uitsluitend de productspecifieke regels hieronder.'
                : trim($basePrompt),
            'PRODUCT: '.trim((string) ($context['product_name'] ?? 'product')).'.',
            $dough ? 'PRODUCTTYPE DEEG: behoud brood- en deegstructuur; pas geen vlees-, vis-, vet- of marmeringsregels toe.'
                : 'PRODUCTTYPE ACCESSOIRES: dit is een gebruiksvoorwerp, geen voedsel. Productdetails en materiaal blijven onveranderd in alle scènes. Behoud echte merktekens; verzin geen letters of functies. Menselijke controle van opschriften blijft nodig.',
            "HARD AANTALVEREISTE: gebruik exact {$quantity} oorspronkelijke exemplaren. Alleen bij de pizza-BBQ-scène worden {$quantity} deegbollen omgevormd tot {$quantity} pizza’s; geen extra exemplaren in de achtergrond of in een apparaat.",
            "REFERENTIEROLLEN: afbeelding 1 t/m {$count} zijn de actuele echte productreferenties. De hoofdfoto bepaalt productidentiteit, vorm, materiaal en verhoudingen. Andere aanzichten zijn geen extra producten.",
        ];
        if ($plan['approved_reference_added'] ?? false) {
            $index = $count + 1;
            $parts[] = "Afbeelding {$index} is een goedgekeurde eerdere foto van hetzelfde product en dezelfde variantstijl. Gebruik die alleen als kwaliteitsanker; de actuele productreferenties en het opgegeven aantal blijven leidend.";
        }
        if ($plan['bundled_reference_added'] ?? true) {
            $parts[] = 'De allerlaatste afbeelding is uitsluitend een BBQuality-voorbeeld voor achtergrond, licht en compositie. Kopieer NOOIT voedsel, accessoires, aantallen, merken of tekst uit dit stijlvoorbeeld. Het voorbeeld kan vlees bevatten: dat is geen productreferentie. De gevraagde keukenhelderheid en apparatuur hieronder gaan vóór het voorbeeld.';
        }
        $parts[] = $plan['instruction'];
        $parts[] = 'BEELDSTIJL: '.$plan['style'];
        $parts[] = 'Natuurlijke kleuren en geloofwaardige oppervlakken, geen HDR, kunstmatige glans of overdreven korrel. Lever precies één liggende 4:3-afbeelding, zonder bijsnijden van het product, watermerk of toegevoegde reclametekst.';
        if (trim((string) ($context['notes'] ?? '')) !== '') {
            $parts[] = 'EXTRA PRODUCTDETAILS VAN DE MEDEWERKER: '.trim($context['notes']);
        }

        return implode("\n\n", $parts);
    }
}

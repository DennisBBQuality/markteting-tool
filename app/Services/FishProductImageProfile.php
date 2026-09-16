<?php

namespace App\Services;

use App\Models\ImagePrompt;

/** Separate fish material/orientation rules, with the existing cooked scene settings. */
class FishProductImageProfile
{
    public function plans(array $cookedScenes): array
    {
        $cooked = [];
        foreach ($cookedScenes as $index => $scene) {
            $cooked[] = [
                'status' => 'bereid', 'label' => 'Vis bereid',
                'style_id' => $index === 0 ? 'vis_buiten_bbq' : 'vis_serveermoment',
                'scene_family' => $scene['scene_family'],
                'style_reference_id' => $scene['style_reference_id'],
                'style' => str_replace(['Het vlees', 'het vlees', 'vleesproduct'], ['De vis', 'de vis', 'visproduct'], $scene['style']),
                'instruction' => 'Toon het aangeleverde visproduct geloofwaardig bereid, passend bij de opgegeven soort en productvorm. Behoud een hele vis als hele vis, een filet of zalmhaas als hetzelfde liggende stuk, en schaal- of schelpdieren als dezelfde aangeleverde delen. Geen standaard steakplakken, brisket-bark, vleesmarmering of roze biefstukkern. Behoud huid, schubben of schaal alleen waar het oorspronkelijke product die heeft; voeg bij een product zonder huid nooit huid toe. Garing mag natuurlijke krimp, kleurverandering en subtiele bruining geven, zonder uitdrogen of verkolen. Maak bij vis fijne natuurlijke lamellen, geen grove vleesvezels. Bij schaal- en schelpdieren volgt de textuur en kleur de betreffende soort. Geen automatische snee of losgemaakte stukken; behoud het product heel zoals aangeleverd. Hoogstens een bescheiden serveergarnituur, nooit extra vis of zeevruchten. Garnituur is uitsluitend presentatie, geen claim over de verkochte productinhoud.',
            ];
        }

        return [...$cooked,
            [
                'status' => 'rauw', 'label' => 'Vis rauw', 'style_id' => 'vis_rauw_zwart',
                'scene_family' => 'vis_zwart', 'style_reference_id' => 'vis_rauw_zwart',
                'style' => 'VASTE ZWARTE VISSETTING: een diepzwart achtervlak en een zwarte ondergrond zoals het afzonderlijke BBQuality-visvoorbeeld. De ondergrond heeft een subtiele donkere structuur en een bescheiden natuurlijke reflectie, geen spiegelvloer. Geen warmbruine of goudbruine houten ondergrond en geen zwart-boven/hout-onder-combinatie uit de vleesstudio. Geen bord, snijplank, ijs, kruiden, citroen, doek of andere accessoires. Het product ligt met een echte contactschaduw op de zwarte ondergrond.',
                'instruction' => $this->rawInstruction(),
            ],
            [
                'status' => 'rauw', 'label' => 'Vis rauw', 'style_id' => 'vis_rauw_hout',
                'scene_family' => 'rauw_licht', 'style_reference_id' => null,
                'style' => 'Lichtere ambachtelijke productopname op een houten plank met zacht diffuus daglicht, zoals de lichte rauwe vleesvariant. Hout is in deze tweede rauwe variant juist toegestaan. Toon detail zonder overbelichting, met een andere camerahoek dan de zwarte visfoto. Geen vaste zwarte achterwand boven een goudbruine houten vloer; kies een rustige, lichte houtsetting. Geen ijs, garnering, kruiden of extra producten.',
                'instruction' => $this->rawInstruction(),
            ],
        ];
    }

    private function rawInstruction(): string
    {
        return 'Toon exact hetzelfde product rauw en onbewerkt. Verwijder uitsluitend verpakking, stickers, etiketten en absorptiemateriaal. Behoud de natuurlijke soortspecifieke kleur, snit, huid of afwezigheid van huid, schubben, schaal, vinnen en staart voor zover aanwezig in de productreferentie. Verzin geen productdelen. Niet portioneren, openvouwen, oprollen, stapelen, inkorten of verdikken. De productreferentie bepaalt de vis, niet het visproduct in het achtergrondvoorbeeld.';
    }

    public function prompt(string $basePrompt, array $context, array $plan): string
    {
        $cooked = $plan['status'] === 'bereid';
        $quantity = max(1, (int) ($context['quantity'] ?? 1));
        $count = max(1, (int) ($context['product_reference_count'] ?? 1));
        $base = trim($basePrompt) === trim(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT)
            ? 'Maak een hoogwaardige, fotorealistische BBQuality-productfoto van de aangeleverde vis, schaal- of schelpdieren. Behoud productidentiteit en hoeveelheid; pas alleen de gevraagde bereiding en setting toe.'
            : trim($basePrompt);
        $parts = [
            $base,
            'PRODUCT: '.trim((string) ($context['product_name'] ?? 'vis')).'.',
            'PRODUCTTYPE VIS: onderstaande visregels bepalen materiaal, ligging en achtergrond; pas geen algemene vlees-, steak- of brisketregels toe.',
            "HARD AANTALVEREISTE: toon exact {$quantity} exemplaar/exemplaren van het aangeleverde hoofdproduct. Geen extra vis, verdubbelde filets of toegevoegde schaaldieren.",
            'PRODUCTVORM EN LIGGING: de eerste productreferentie bepaalt het exemplaar. Behoud de oorspronkelijke lengte-breedte-dikteverhouding, contour, taps toelopende delen en herkenbare onregelmatigheden. Leg het product natuurlijk en stabiel plat op de breedste rustzijde, met de lange as overwegend horizontaal door het beeld. Nooit rechtop zetten, op de smalle kop laten balanceren, tegen iets leunen of als een dik verticaal blok presenteren. Een andere camerahoek mag de fysieke ligging niet veranderen. Aanvullende aanzichten verduidelijken hetzelfde product; maak geen geïdealiseerde tussenvorm.',
            "REFERENTIEROLLEN: afbeelding 1 t/m {$count} zijn de echte productreferenties en de enige bron voor productidentiteit, vorm, huid of schaal en hoeveelheid. Ze bepalen niet de nieuwe achtergrond. De hoofdfoto blijft leidend; bij bereiding zijn uitsluitend natuurlijke veranderingen door garing toegestaan.",
        ];
        if ($plan['approved_reference_added'] ?? false) {
            $index = $count + 1;
            $parts[] = "Afbeelding {$index} is een goedgekeurde eerdere foto van exact dit visproduct en deze variantstijl. Gebruik die als kwaliteitsanker, nooit voor een afwijkende vorm, extra producten of overdreven glans. De actuele productreferenties en de expliciete liggingsregels blijven leidend.";
        }
        $bundled = $plan['bundled_reference_added'] ?? (($plan['style_reference_id'] ?? null) !== null);
        if ($bundled) {
            $parts[] = $cooked
                ? 'De allerlaatste afbeelding is uitsluitend het bestaande BBQuality-sfeervoorbeeld voor de omgeving, camerahoek en compositie van bereid vlees. Neem die achtergrondstijl over, maar nooit het vlees, de bark, vetnaden, vleesvezels, gaarheid, kruidenlaag of het aantal. Gebruik de visregels voor materiaal en zacht licht.'
                : 'De allerlaatste afbeelding is uitsluitend het BBQuality-voorbeeld voor de ZWARTE ACHTERGROND EN ZWARTE ONDERGROND, met subtiele structuur en reflectie. Het bevat een voorbeeldvis: kopieer die vis nooit, ook niet de zalmkleur, vorm, dikte, snit, soort of hoeveelheid. Het voorbeeld is geen extra productreferentie. Eventuele lichte marges rond referentiebeelden horen niet bij de achtergrond.';
        }
        $parts[] = $plan['instruction'];
        $parts[] = 'BEELDSTIJL: '.$plan['style'];
        $parts[] = 'FOTOGRAFIE VIS: zacht gericht of diffuus licht, natuurlijke kleuren en rustige contrasten. Geloofwaardige fijne voedselstructuur en plaatselijke subtiele vochtglans, zonder korreligheid, HDR, verscherpingsranden, plastic, wasachtige oppervlakken of een overal glimmende laklaag. Houd het volledige product in beeld met ademruimte rondom, geen beeldvullende macro-opname. Voeg geen tekst, watermerk, merk of verpakking toe. Lever precies één vierkante afbeelding.';
        if (trim((string) ($context['notes'] ?? '')) !== '') {
            $parts[] = 'EXTRA INFORMATIE VAN DE MEDEWERKER: '.trim($context['notes']);
        }

        return implode("\n\n", $parts);
    }
}

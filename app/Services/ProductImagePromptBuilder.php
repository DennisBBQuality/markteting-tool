<?php

namespace App\Services;

class ProductImagePromptBuilder
{
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
            $this->quantityInstruction($plan['status'], $quantity),
            'BRONBEHOUD: de eerste referentiefoto is de hoofdfoto. Behoud identiteit, oorspronkelijke lengte-breedte-dikteverhouding, silhouet, snit, vetkap, vetverdeling, marmering en herkenbare onregelmatigheden. Maak een lang of plat product nooit korter, compacter of dikker.',
            $this->referenceInstruction($context, $plan),
            $plan['instruction'],
            'BEELDSTIJL: '.$plan['style'],
            'FOTOGRAFISCHE KWALITEIT: echte voedselstructuur, natuurlijke kleur, realistische vezels, vet en vocht. Vermijd plastic, wasachtig, overdreven glad of uniform vlees, uitgebeten hooglichten, kunstmatige glans, gitzwarte korst en generieke stockfoto-uitstraling.',
            'Lever precies één vierkante, fotorealistische afbeelding zonder watermerk, toegevoegde reclametekst of fantasielogo. Voeg nooit een tweede hoofdproduct toe.',
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
        $family = $this->meatFamily((string) ($context['product_name'] ?? ''));
        [$outdoorInstruction, $servingInstruction, $servingReference] = $this->cookedInstructions($family);
        $rawShapeInstruction = $this->rawShapeInstruction($family);

        return [
            [
                'status' => 'bereid', 'label' => 'Vlees bereid', 'style_id' => 'bbq_buiten_'.$family,
                'scene_family' => 'buiten_bbq', 'style_reference_id' => $family === 'ribs' ? 'bbq_outdoor_smoker' : 'bbq_outdoor_kamado',
                'style' => 'Duidelijke BBQ-sfeer buiten in warm natuurlijk daglicht. Toon een geloofwaardige barbecue, kamado of smoker zacht onscherp in de achtergrond, met een ambachtelijke houten serveerplank. Dit is een ruim sfeerbeeld en geen donkere studio-close-up.',
                'instruction' => $outdoorInstruction,
            ],
            [
                'status' => 'bereid', 'label' => 'Vlees bereid', 'style_id' => 'serveerbeeld_'.$family,
                'scene_family' => 'serveermoment', 'style_reference_id' => $servingReference,
                'style' => 'Rijk maar geloofwaardig serveermoment met duidelijke foodstyling, meer omgeving in beeld en een andere camerahoek, achtergrond en compositie dan de buitenvariant. Een neutraal schaaltje saus, augurk, ingelegde ui, brood of kruiden mag alleen als passende sfeerdecoratie; voeg geen extra vleesproduct of merkartikel toe.',
                'instruction' => $servingInstruction,
            ],
            [
                'status' => 'rauw', 'label' => 'Vlees rauw', 'style_id' => 'rauw_studio',
                'scene_family' => 'rauw_bbquality_vast', 'style_reference_id' => 'rauw_bbquality_vast',
                'style' => 'VASTE BBQUALITY-ACHTERGROND: neem de achtergrondopbouw uit het laatste referentiebeeld zo exact mogelijk over. Gebruik een volledig egale diepzwarte achterwand bovenin en dezelfde warme goudbruine houten plaat met grove nerf onderin. Behoud dezelfde zichtbare verhouding tussen zwart en hout, frontale tot licht verhoogde camerahoek, directe belichting en eenvoudige centrale plaatsing. Voeg geen plank, bord, doek, schaaltje, kruiden, verpakking of ander accessoire toe.',
                'instruction' => 'Verwijder uitsluitend plastic, vacuümzak, schaal, absorptiemat, stickers en etiketten. Behandel dit als vrijleggen van hetzelfde product, niet als het opnieuw ontwerpen of anatomisch reconstrueren ervan. Kopieer de exacte buitencontour, lengte-breedte-dikteverhouding, oriëntatie en grote herkenbare uitstulpingen of inkepingen die door de verpakking zichtbaar zijn. Niet oprollen, openvouwen, samendrukken, verdikken, inkorten, bijsnijden, splitsen of mooier modelleren. Toon het product volledig rauw en onbewerkt met natuurlijk dieprood spierweefsel, realistische vezelrichting, correcte marmering en dezelfde plaats, dikte en onregelmatigheid van de vetkap. '.$rawShapeInstruction,
            ],
            [
                'status' => 'rauw', 'label' => 'Vlees rauw', 'style_id' => 'rauw_licht',
                'scene_family' => 'rauw_licht', 'style_reference_id' => null,
                'style' => 'Lichtere ambachtelijke productopname op een houten slagersplank met zacht diffuus daglicht, behoud van detail in zowel rood vlees als wit vet en zonder overbelichting. Gebruik een andere hoek dan de donkere rauwe foto.',
                'instruction' => 'Verwijder uitsluitend de verpakking. Toon exact hetzelfde product volledig rauw en onbewerkt; behandel dit niet als een nieuw of ideaal gevormd stuk vlees. Behoud exact dezelfde lengte-breedte-dikteverhouding, buitencontour, oriëntatie, grote uitstulpingen en inkepingen, anatomische structuur, dieprode vleeskleur, vezelrichting, marmering en vetverdeling. Niet openvouwen, oprollen, splitsen, inkorten of verdikken. Het vet blijft natuurlijk mat en vezelig, nooit glad, roze, wasachtig of plasticachtig. '.$rawShapeInstruction,
            ],
        ];
    }

    private function saucePlans(array $context): array
    {
        $exact = 'Behoud de fles of pot, dop, vorm, verhoudingen, kleuren, het volledige etiket, logo en iedere zichtbare letter exact volgens de referentie. Verander of verzin geen enkel woord. Het product blijft verpakt en staat rechtop als hoofdonderwerp.';

        return [
            ['status' => 'product', 'label' => 'Productfoto', 'style_id' => 'bbquality_buiten', 'scene_family' => 'buiten_bbq', 'style_reference_id' => 'product_buiten_bbquality', 'instruction' => $exact,
                'style' => 'Vaste BBQuality-huisstijl: warme houten tafel, natuurlijk zonnig licht en een groene tuin zacht onscherp op de achtergrond.'],
            ['status' => 'product', 'label' => 'Productfoto', 'style_id' => 'bbquality_donker', 'scene_family' => 'donkere_studio', 'style_reference_id' => null, 'instruction' => $exact,
                'style' => 'Donkere premium studio-opname op warm hout met zacht gericht licht; duidelijk anders dan de buitenvariant maar passend binnen dezelfde BBQuality-serie.'],
        ];
    }

    private function bundlePlans(array $context): array
    {
        $instruction = 'Maak één totaalbeeld met uitsluitend alle aangeleverde en beschreven onderdelen. Alle eetbare vleesproducten blijven volledig rauw. Behoud van ieder onderdeel het exacte aantal. Verpakte merkproducten behouden hun echte verpakking en etiket; verzin niets.';

        return [
            ['status' => 'totaal', 'label' => 'Totaalbeeld', 'style_id' => 'pakket_bovenaanzicht', 'scene_family' => 'bovenaanzicht', 'style_reference_id' => 'totaalpakket_rustiek', 'instruction' => $instruction,
                'style' => 'Geordend premium bovenaanzicht op een grote donkere houten werktafel, met elk onderdeel volledig zichtbaar.'],
            ['status' => 'totaal', 'label' => 'Totaalbeeld', 'style_id' => 'pakket_hero', 'scene_family' => 'hero', 'style_reference_id' => null, 'instruction' => $instruction,
                'style' => 'Lage hero-compositie op rustiek hout met warme studioverlichting, een andere indeling dan het bovenaanzicht en elk onderdeel duidelijk herkenbaar.'],
        ];
    }

    private function quantityInstruction(string $status, int $quantity): string
    {
        if ($status === 'bereid') {
            return "HARD AANTALVEREISTE: bereid exact {$quantity} oorspronkelijk(e) exemplaar/exemplaren van het hoofdproduct. Sneden of plakken blijven aantoonbaar delen van diezelfde {$quantity} exemplaar/exemplaren en zijn geen extra producten. Nooit vlees dupliceren.";
        }

        return "HARD AANTALVEREISTE: toon exact {$quantity} exemplaar/exemplaren van het hoofdproduct. Nooit meer en nooit minder.";
    }

    private function referenceInstruction(array $context, array $plan): string
    {
        $count = max(1, (int) ($context['product_reference_count'] ?? 1));
        if (($plan['style_reference_id'] ?? null) === null) {
            return "REFERENTIEROLLEN: afbeelding 1 t/m {$count} zijn uitsluitend productreferenties. Gebruik ze samen om vorm, materiaal, details en hoeveelheid feitelijk vast te stellen.";
        }

        if (($plan['style_reference_id'] ?? null) === 'rauw_bbquality_vast') {
            return "REFERENTIEROLLEN: afbeelding 1 t/m {$count} zijn uitsluitend productreferenties en zijn de enige bron voor de vorm van het rauwe hoofdproduct. De allerlaatste afbeelding is een LEGE VASTE BBQUALITY-ACHTERGRONDREFERENTIE zonder product. Neem daarvan uitsluitend het zwarte achtervlak, het warme hout, de vlakverdeling, belichting, camerahoek en kadrering zo exact mogelijk over. Leid nooit productvorm, dikte, snit, vet of hoeveelheid af uit de achtergrondreferentie. Bij ieder conflict winnen de productreferenties altijd.";
        }

        return "REFERENTIEROLLEN: afbeelding 1 t/m {$count} zijn uitsluitend productreferenties en zijn leidend voor het hoofdproduct. De allerlaatste afbeelding is uitsluitend een goedgekeurd BBQuality-STIJLVOORBEELD. Neem daarvan alleen fotografie, sfeer, licht, camerastandpunt, kadrering en type omgeving over. Kopieer nooit het vlees, gerecht, aantal, merk, tekst, verpakking of accessoires uit het stijlvoorbeeld. Bij conflict winnen de productreferenties altijd.";
    }

    /** @return array{string, string, string} */
    private function cookedInstructions(string $family): array
    {
        return match ($family) {
            'steak' => [
                'Bereid het rundvlees medium en toon het volledig ongesneden. Geef het een natuurlijke donkerbruine, krokant aangebraden korst met subtiele grillsporen; geen zwarte of lakachtige buitenkant.',
                'Bereid hetzelfde rundvlees medium en snijd uitsluitend deze variant open. Toon een sappige warme roze kern, geloofwaardige spiervezels en een krokant aangebraden buitenkant. De plakken blijven duidelijk afkomstig van het ene oorspronkelijke product.',
                'serveer_steak_rustiek',
            ],
            'brisket' => [
                'Rook deze exacte brisket geloofwaardig. Toon één volledige brisket buiten bij de smoker, eventueel met slechts enkele plakken die zichtbaar van hetzelfde stuk zijn afgesneden. Maak een droge, diepbruine mahonie bark met onregelmatige specerijen; nooit egaal zwart, nat gelakt of verbrand.',
                'Maak van dezelfde ene brisket een sfeervol serveerbeeld op een plank: netjes tegen de draad gesneden plakken met zichtbare sappige vezels en een natuurlijke smoke ring. Houd een herkenbaar deel van het hele oorspronkelijke stuk in beeld. De bark is donker mahoniebruin en krokant, niet gitzwart of glanzend plastic.',
                'serveer_brisket_plank',
            ],
            'moink' => [
                'Bereid exact hetzelfde aantal MOINK balls buiten bij de barbecue. Behoud bij iedere bal de herkenbare spekband, maak het spek krokant en de glaze glanzend maar niet plasticachtig.',
                'Serveer exact hetzelfde aantal geglaceerde MOINK balls in een gezellige BBQ-compositie. Hoogstens één bal mag worden doorgesneden om de sappige gehaktstructuur te tonen; beide helften tellen samen als die ene bal.',
                'serveer_moink_balls',
            ],
            'burger' => [
                'Grill exact hetzelfde aantal burgers of patties buiten op of naast de barbecue. Behoud maat, dikte en grove vleestextuur en maak een natuurlijke bruine korst zonder het aantal te veranderen.',
                'Maak een rijk maar geloofwaardig serveerbeeld met exact hetzelfde aantal bereide burgers of patties. Brood en neutrale toppings mogen alleen als presentatie, maar het aantal vleeselementen blijft exact gelijk.',
                'serveer_brisket_broodje',
            ],
            'ribs' => [
                'Bereid exact hetzelfde aantal hele ribrekken buiten bij een smoker. Behoud botstructuur, lengte en breedte. Maak een natuurlijke roodbruine BBQ-korst met lichte karamellisatie, niet zwart of kunstmatig glanzend.',
                'Serveer dezelfde ribrekken op een houten plank in een andere, rijkere compositie. Enkele ribben mogen losgesneden zijn als duidelijk blijft dat ze uit hetzelfde aantal aangeleverde rekken komen.',
                'serveer_brisket_plank',
            ],
            default => [
                'Bereid exact dit product buiten op een culinair en producttechnisch geloofwaardige BBQ-manier. Behoud herkenbare vorm, structuur en hoeveelheid en maak een natuurlijke aangebraden korst.',
                'Maak een sfeervol serveerbeeld van exact hetzelfde bereide product met passende neutrale foodstyling. Gebruik een andere camerahoek en compositie dan de buitenvariant en behoud productidentiteit en hoeveelheid.',
                'serveer_brisket_plank',
            ],
        };
    }

    private function rawShapeInstruction(string $family): string
    {
        return match ($family) {
            'brisket' => 'PRODUCTVORM BRISKET: dit is één grote, hele en ongetrimde brisket. Behoud de lange, brede, aaneengesloten vorm van het verpakte stuk en de zichtbare verhouding tussen het plattere en dikkere deel. Maak er nooit een losse stapel, platte lap, compact blok, opengevouwen of gevlinderd stuk van. Alle spier- en vetdelen blijven verbonden binnen precies dezelfde buitenomtrek als op de echte productreferenties.',
            'ribs' => 'PRODUCTVORM RIBS: behoud het volledige lange ribrek als één aaneengesloten product, met hetzelfde aantal zichtbare botposities, dezelfde kromming, lengte en breedte. Maak er geen losse ribben of compact blok van.',
            'steak' => 'PRODUCTVORM STEAK: behoud exact de oorspronkelijke snit, dikte, omtrek en positie van vetkap of vetrand. Maak de steak niet ronder, hoger, symmetrischer of compacter.',
            default => 'PRODUCTVORM: behoud het product als hetzelfde aantal aaneengesloten stukken met exact dezelfde individuele omtrek en onderlinge plaatsing als op de echte productreferenties.',
        };
    }

    private function meatFamily(string $productName): string
    {
        $name = strtolower($productName);

        foreach ([
            'brisket' => ['brisket', 'runderborst', 'borststuk'],
            'moink' => ['moink', 'gehaktbal', 'meatball'],
            'burger' => ['burger', 'patty', 'hamburger'],
            'ribs' => ['sparerib', 'spare rib', 'ribs', 'ribfinger', 'rib finger'],
            'steak' => ['steak', 'biefstuk', 'picanha', 'ribeye', 'entrecote', 'tomahawk', 't-bone', 'côte de boeuf'],
        ] as $family => $terms) {
            foreach ($terms as $term) {
                if (str_contains($name, $term)) {
                    return $family;
                }
            }
        }

        return 'algemeen';
    }
}

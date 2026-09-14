<?php

namespace App\Services;

use App\Models\ImagePrompt;

class ProductImagePromptBuilder
{
    private const COOKED_BASE_PROMPT = 'Maak een fotorealistische BBQuality-sfeerfoto van het aangeleverde product na bereiding. Het beeld moet ogen als een echte, zorgvuldig belichte foodfoto voor de webshop. Gebruik de productreferenties voor productidentiteit en hoeveelheid, en een afzonderlijk stijlvoorbeeld alleen volgens de hieronder beschreven referentierollen. Verzin geen hoofdproducten, aantallen, verpakkingen, logo\'s of etiketteksten. Neutrale foodstyling is alleen toegestaan wanneer de scène dit vraagt.';

    private const COOKED_PHOTOGRAPHY = 'FOTOGRAFIE BEREID VLEES: zacht diffuus zijlicht met geleidelijke overgangen tussen licht en schaduw, rustige contrasten en natuurlijke kleuren. Het hoofdproduct is helder en voldoende scherp; de omgeving valt zacht uit focus. Geef vlees een subtiele vezeltekening en zachte toonovergangen op werkelijke productschaal. Vocht en gesmolten vet glanzen plaatselijk en zacht; de aangebraden vleesdelen eromheen zijn minder reflecterend. Een vetnaad mag dus glanzen zonder dat de hele korst een olieachtige glans krijgt. Gebruik een glaze alleen waar de productspecifieke bereiding die vraagt. Korst en kruiding vormen een samenhangend oppervlak, met fijne details die ondergeschikt blijven aan het geheel. Geen aangezette microcontrasten, verscherpingsranden, HDR-effect, toegevoegde filmkorrel of overal oplichtende korrels. Maak het vlees ook niet glad of wazig om detail te verbergen. Laat het volledige hoofdproduct en alle bijbehorende plakken binnen het kader, met zichtbare plankrand en ademruimte rondom; geen beeldvullende macro-opname.';

    private const BRAISED_SOURCE = 'BRONBEHOUD BIJ STOVEN: de eerste referentiefoto bepaalt het te bereiden vlees, de snit en de oorspronkelijke hoeveelheid. Behoud de herkenbare spier- en vetverdeling waar die na het stoven nog zichtbaar is. Natuurlijke krimp, gesmolten vet, zacht geworden bindweefsel en voorzichtig loskomende vezels horen bij deze bereiding. De rauwe buitencontour hoeft dus niet star intact te blijven. Laat het grootste deel als herkenbaar zacht gestoofd stuk zien; een klein deel mag met een vork zijn losgemaakt. Die losse delen komen uit hetzelfde stuk en zijn geen extra vlees. Maak geen ander soort vlees en voeg geen extra vleesporties toe. De genoemde puree, groenten en jus zijn uitsluitend serveersuggesties, geen bewering over de inhoud of ingrediënten van het verkochte product.';

    private const BRAISED_PHOTOGRAPHY = 'FOTOGRAFIE BEREID VLEES: zacht diffuus zijlicht, rustige contrasten en natuurlijke kleuren. Het stoofvlees staat centraal en oogt zacht, sappig en volledig gaar, met subtiel loskomende vezels. Jus heeft plaatselijke zachte glans en loopt natuurlijk om het vlees; geen dikke laklaag, droge grillkorst of knapperige rub. Geen aangezette microcontrasten, verscherpingsranden, toegevoegde filmkorrel of diep ingetekende vezelgroeven. Maak het vlees ook niet glad of wazig. Houd het vlees en de losgemaakte delen volledig in beeld, met zichtbare bord- of panrand en ademruimte rondom; geen beeldvullende macro-opname.';

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
        $cooked = $plan['status'] === 'bereid';
        $braised = $cooked && ($plan['preparation'] ?? null) === 'stoof';

        // Only specialize the built-in default. Never discard a team's custom base prompt.
        $effectiveBase = $cooked && trim($basePrompt) === trim(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT)
            ? self::COOKED_BASE_PROMPT
            : trim($basePrompt);

        $parts = [
            $effectiveBase,
            "PRODUCT: {$name}.",
            $this->quantityInstruction($plan['status'], $quantity, $braised),
            $braised ? self::BRAISED_SOURCE : ($cooked
                ? 'BRONBEHOUD BIJ BEREIDING: de eerste referentiefoto is de hoofdfoto. Behoud snit, herkenbare lengte-breedte-dikteverhouding, verbonden productdelen en het opgegeven aantal oorspronkelijke producten. Neem asymmetrie, een toelopend uiteinde of inkepingen alleen over waar ze in de productfoto zichtbaar zijn; verzin ze niet. Maak een langgerekt stuk niet ronder, hoger of compacter voor een mooiere compositie. De zichtbare spierindeling en ligging van vetnaden blijven herkenbaar. Maak het product niet magerder of vetter op basis van algemene aannames over de diersoort. Alleen veranderingen door de gevraagde bereiding zijn toegestaan: natuurlijke krimp, garing, bruining en het smelten van vet. Behoud dus niet letterlijk de rauwe kleur of rauwe oppervlaktestructuur; vet mag slinken en bruinen, maar aanwezige vetstroken worden niet weggepoetst. Maak geen ander stuk vlees, vergroot het niet en verwijder geen productdelen. Afgesneden plakken komen uit het getoonde bereide stuk en worden nooit als extra vlees toegevoegd.'
                : 'BRONBEHOUD: de eerste referentiefoto is de hoofdfoto. Behoud identiteit, oorspronkelijke lengte-breedte-dikteverhouding, silhouet, snit, vetkap, vetverdeling, marmering en herkenbare onregelmatigheden. Maak een lang of plat product nooit korter, compacter of dikker.'),
            $this->referenceInstruction($context, $plan),
            $plan['instruction'],
            'BEELDSTIJL: '.$plan['style'],
            $braised ? self::BRAISED_PHOTOGRAPHY : ($cooked
                ? self::COOKED_PHOTOGRAPHY
                : 'FOTOGRAFISCHE KWALITEIT: echte voedselstructuur, natuurlijke kleur, realistische vezels, vet en vocht. Vermijd plastic, wasachtig, overdreven glad of uniform vlees, uitgebeten hooglichten, kunstmatige glans, gitzwarte korst en generieke stockfoto-uitstraling.'),
            $cooked
                ? 'Lever precies één vierkante, fotorealistische afbeelding zonder watermerk, toegevoegde reclametekst of fantasielogo. Voeg geen hoofdproducten toe buiten het opgegeven aantal.'
                : 'Lever precies één vierkante, fotorealistische afbeelding zonder watermerk, toegevoegde reclametekst of fantasielogo. Voeg nooit een tweede hoofdproduct toe.',
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

        $plans = [
            [
                'status' => 'bereid', 'label' => 'Vlees bereid', 'style_id' => 'bbq_buiten_'.$family,
                'scene_family' => 'buiten_bbq', 'style_reference_id' => $family === 'ribs' ? 'bbq_outdoor_smoker' : 'bbq_outdoor_kamado',
                'style' => 'Een ontspannen BBQ-moment buiten. Het vlees ligt op een ambachtelijke houten serveerplank in zacht gefilterd daglicht, met een barbecue, kamado of smoker en wat groen herkenbaar maar onscherp op de achtergrond. Gebruik een neutrale daglichtwitbalans, zonder hard direct zonlicht op het vlees. Het hout mag van zichzelf warm zijn; leg geen oranje of goudgele kleurzweem over vlees, vet en plank. Houd de plank rustig, zonder standaard uitgestrooide peper, zout of kruiden als decoratie. De omgeving geeft sfeer, het product blijft centraal.',
                'instruction' => $outdoorInstruction,
            ],
            [
                'status' => 'bereid', 'label' => 'Vlees bereid', 'style_id' => 'serveerbeeld_'.$family,
                'scene_family' => 'serveermoment', 'style_reference_id' => $servingReference,
                'style' => 'Een rustig, geloofwaardig serveermoment aan tafel, met zacht raamlicht en een andere camerahoek, achtergrond en compositie dan de buitenvariant. Toon de houten plank met een deel van de tafel eromheen. Kies hoogstens één of twee kleine, ondergeschikte accessoires, zoals een doek, een neutraal schaaltje saus of wat augurk. Bestrooi vlees en plank niet standaard met losse peper- of zoutkorrels. Voeg geen extra vleesproduct of merkartikel toe.',
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

        // A stew needs its own presentation and source-preservation rules. Keep raw plans intact.
        return $family === 'stoof'
            ? [...$this->braisedPlans(), ...array_slice($plans, 2)]
            : $plans;
    }

    private function braisedPlans(): array
    {
        $instruction = 'BEREIDING: maak van het aangeleverde vlees een stoofgerecht, langzaam gestoofd of gesudderd in jus tot het zacht is. Toon overwegend intact stoofvlees met een klein, natuurlijk met een vork losgemaakt deel dat de zachte binnenkant laat zien. GEEN SNIJPLAKKEN: geen nette gesneden plakjes, plakjeswaaier, steakpresentatie of droog braadstuk op een snijplank. Serveer met een bescheiden hoeveelheid puree, enkele groenten en jus; het vlees blijft de hoofdrol houden. Deze bijgerechten zijn alleen foodstyling. Verzin geen extra vleesproduct of merkartikel.';

        return [
            [
                'status' => 'bereid', 'label' => 'Vlees bereid', 'style_id' => 'bbq_buiten_stoof',
                'preparation' => 'stoof', 'scene_family' => 'buiten_bbq', 'style_reference_id' => null,
                'style' => 'Een echt geserveerd stoofgerecht op een rustiek keramisch bord buiten, op een houten tafel. Leg het vlees deels op puree, met jus eromheen en enkele groenten ernaast. Een kamado en groen zijn herkenbaar maar onscherp op de achtergrond. Zacht gefilterd daglicht met neutrale witbalans; geen hard zonlicht of oranje kleurzweem. De tafel mag warm van kleur zijn. Geen uitgestrooide peper of zout als decoratie.',
                'instruction' => $instruction,
            ],
            [
                'status' => 'bereid', 'label' => 'Vlees bereid', 'style_id' => 'serveerbeeld_stoof',
                'preparation' => 'stoof', 'scene_family' => 'serveermoment', 'style_reference_id' => null,
                'style' => 'Een huiselijk serveermoment aan tafel in zacht raamlicht. Toon het stoofvlees in een ondiepe stoofpan met een bescheiden laag jus en enkele groenten. Zet een klein schaaltje puree ondergeschikt ernaast. Kies een andere camerahoek en compositie dan de buitenvariant, zonder barbecue in beeld. Houd het vlees goed zichtbaar boven de jus, met rustige styling en hoogstens een neutrale doek.',
                'instruction' => $instruction,
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

    private function quantityInstruction(string $status, int $quantity, bool $braised = false): string
    {
        if ($braised) {
            return "HARD AANTALVEREISTE: stoof exact {$quantity} oorspronkelijk(e) exemplaar/exemplaren van het hoofdproduct. Losgemaakte delen behoren tot diezelfde {$quantity} exemplaar/exemplaren; voeg ze niet bovenop de oorspronkelijke hoeveelheid toe. Nooit vlees dupliceren.";
        }
        if ($status === 'bereid') {
            return "HARD AANTALVEREISTE: bereid exact {$quantity} oorspronkelijk(e) exemplaar/exemplaren van het hoofdproduct. Sneden of plakken blijven aantoonbaar delen van diezelfde {$quantity} exemplaar/exemplaren en zijn geen extra producten. Nooit vlees dupliceren.";
        }

        return "HARD AANTALVEREISTE: toon exact {$quantity} exemplaar/exemplaren van het hoofdproduct. Nooit meer en nooit minder.";
    }

    private function referenceInstruction(array $context, array $plan): string
    {
        $count = max(1, (int) ($context['product_reference_count'] ?? 1));
        $approvedAdded = (bool) ($plan['approved_reference_added'] ?? false);
        $bundledAdded = array_key_exists('bundled_reference_added', $plan)
            ? (bool) $plan['bundled_reference_added']
            : ($plan['style_reference_id'] ?? null) !== null;

        if ($plan['status'] === 'bereid') {
            return $this->cookedReferenceInstruction($count, $approvedAdded, $bundledAdded, ($plan['preparation'] ?? null) === 'stoof');
        }

        if ($approvedAdded && $bundledAdded) {
            $approvedIndex = $count + 1;

            return "REFERENTIEROLLEN: afbeelding 1 t/m {$count} zijn de actuele echte productreferenties en blijven de enige bron voor exacte vorm en hoeveelheid. Afbeelding {$approvedIndex} is een door BBQuality goedgekeurde eerdere foto van precies dit product en deze variantstijl; gebruik die voor realistische productuitstraling, bereiding, structuur en fotografische kwaliteit, maar kopieer nooit het aantal of afwijkende vormdetails. De allerlaatste afbeelding is uitsluitend de LEGE VASTE BBQUALITY-ACHTERGRONDREFERENTIE. Neem daarvan alleen zwart vlak, hout, vlakverdeling, licht, camerahoek en kadrering over. Bij conflict winnen altijd de actuele echte productreferenties.";
        }

        if ($approvedAdded) {
            return "REFERENTIEROLLEN: afbeelding 1 t/m {$count} zijn de actuele echte productreferenties en blijven leidend voor exacte vorm en hoeveelheid. De allerlaatste afbeelding is een door BBQuality goedgekeurde eerdere foto van precies dit product en dezelfde variantstijl. Gebruik die als kwaliteitsanker voor realistische bereiding, korst, voedselstructuur, sfeer, licht en compositie. Kopieer nooit het aantal, een ontbrekend productdeel of vormdetails die afwijken van de actuele echte productreferenties.";
        }

        if (! $bundledAdded) {
            return "REFERENTIEROLLEN: afbeelding 1 t/m {$count} zijn uitsluitend productreferenties. Gebruik ze samen om vorm, materiaal, details en hoeveelheid feitelijk vast te stellen.";
        }

        if (($plan['style_reference_id'] ?? null) === 'rauw_bbquality_vast') {
            return "REFERENTIEROLLEN: afbeelding 1 t/m {$count} zijn uitsluitend productreferenties en zijn de enige bron voor de vorm van het rauwe hoofdproduct. De allerlaatste afbeelding is een LEGE VASTE BBQUALITY-ACHTERGRONDREFERENTIE zonder product. Neem daarvan uitsluitend het zwarte achtervlak, het warme hout, de vlakverdeling, belichting, camerahoek en kadrering zo exact mogelijk over. Leid nooit productvorm, dikte, snit, vet of hoeveelheid af uit de achtergrondreferentie. Bij ieder conflict winnen de productreferenties altijd.";
        }

        return "REFERENTIEROLLEN: afbeelding 1 t/m {$count} zijn uitsluitend productreferenties en zijn leidend voor het hoofdproduct. De allerlaatste afbeelding is uitsluitend een goedgekeurd BBQuality-STIJLVOORBEELD. Neem daarvan alleen fotografie, sfeer, licht, camerastandpunt, kadrering en type omgeving over. Kopieer nooit het vlees, gerecht, aantal, merk, tekst, verpakking of accessoires uit het stijlvoorbeeld. Bij conflict winnen de productreferenties altijd.";
    }

    private function cookedReferenceInstruction(int $count, bool $approvedAdded, bool $bundledAdded, bool $braised = false): string
    {
        $parts = [$braised
            ? "REFERENTIEROLLEN: afbeelding 1 t/m {$count} zijn de actuele echte productreferenties, leidend voor snit en oorspronkelijke hoeveelheid. De hoofdfoto bepaalt het exemplaar, niet de uiteindelijke serveerwijze. Pas materiaal en vorm aan het stoven aan volgens BRONBEHOUD BIJ STOVEN; kopieer geen rauwe kleur of stevige rauwe structuur."
            : "REFERENTIEROLLEN: afbeelding 1 t/m {$count} zijn de actuele echte productreferenties. De hoofdfoto bepaalt het te bereiden exemplaar; aanvullende foto's verduidelijken zichtbare details, niet een gemiddelde of ideale productvorm. Gebruik ze voor productidentiteit, hoofdvorm, vetverdeling en hoeveelheid; pas kleur en materiaal aan de gevraagde bereiding aan. Bij conflict winnen de productreferenties altijd voor identiteit, vorm, vetverdeling en hoeveelheid."];

        if ($approvedAdded) {
            $index = $count + 1;
            $parts[] = $braised
                ? "Afbeelding {$index} is een door BBQuality goedgekeurd stoofvoorbeeld van precies dit product en deze variantstijl. Gebruik het voor de stoofbereiding en bord- of panpresentatie, niet om een ander vleesproduct, extra hoeveelheid of overdreven glans te kopiëren. De echte productreferenties blijven leidend voor identiteit en oorspronkelijke hoeveelheid."
                : "Afbeelding {$index} is een door BBQuality goedgekeurde eerdere foto van precies dit product en deze variantstijl. Gebruik die als kwaliteitsanker voor een geloofwaardige bereiding en presentatie, niet om extra korrels, glans of verscherping te kopiëren. Kopieer nooit het aantal, ontbrekende productdelen of afwijkende vormdetails; de actuele productreferenties blijven leidend.";
        }
        if ($bundledAdded) {
            $parts[] = 'De allerlaatste afbeelding is uitsluitend een goedgekeurd BBQuality-STIJLVOORBEELD voor type omgeving, camerahoek en ruimtelijke opbouw. Het is geen referentie voor korst, vleesvezels, vocht, glaze, kruiding of scherpte. Kopieer nooit het vlees, gerecht, aantal, merk, tekst, verpakking of accessoires uit het stijlvoorbeeld. Gebruik voor de belichting en materiaalweergave de instructie FOTOGRAFIE BEREID VLEES, ook wanneer het stijlvoorbeeld harder licht, sterke glans of grove korrels toont.';
        }

        return implode(' ', $parts);
    }

    /** @return array{string, string, string} */
    private function cookedInstructions(string $family): array
    {
        return match ($family) {
            'steak' => [
                'Bereid de aangeleverde steak medium en toon het volledig ongesneden. Geef het een natuurlijke donkerbruine, krokant aangebraden korst met subtiele grillsporen; geen zwarte of lakachtige buitenkant. Gebruik fijne, spaarzame kruiding, geen dekkende laag losse peperkorrels.',
                'Bereid dezelfde steak medium en snijd uitsluitend deze variant open. Toon een sappige warme roze kern, geloofwaardige spiervezels en een krokant aangebraden buitenkant. Gebruik fijne, spaarzame kruiding, geen dekkende laag losse peperkorrels. De plakken blijven duidelijk afkomstig van het bijbehorende oorspronkelijke product; snijvlakken en vetnaden sluiten logisch aan op het resterende stuk.',
                'serveer_steak_rustiek',
            ],
            'brisket' => [
                'Toon het opgegeven aantal briskets geloofwaardig gerookt buiten bij de smoker, natuurlijk plat op de plank. Houd iedere brisket grotendeels heel; hoogstens enkele plakken zijn van één uiteinde afgesneden. FYSIEKE CONTINUÏTEIT: de snijvlakken van de losse plakken sluiten aan op het resterende stuk; samen reconstrueren ze het bereide volume en silhouet. Laat nooit een hap, wig, hoek of zijstuk uit de zijkant verdwijnen. De brisket heeft een samenhangende, overwegend matte mahoniebruine bark met rustige kleurvariatie; specerijen zijn klein en ingebed in de korst, niet een laag glinsterende korrels. Het snijvlak toont mals, gaar vlees met subtiele vezels en plaatselijk zacht vocht, zonder nadrukkelijke groeven of rauwe vetvlakken.',
                'Serveer hetzelfde opgegeven aantal gerookte briskets op een houten plank. Leg enkele plakken losjes bij het bijbehorende resterende stuk, tegen de draad gesneden, met kleine natuurlijke verschillen in snijrand en plaatsing. FYSIEKE CONTINUÏTEIT: iedere plak komt van één snijzijde en sluit qua doorsnede en vetnaad aan op het resterende stuk; geen onverklaarbare hap, wig, hoek of zijdeel. De bark is samenhangend en overwegend mat mahoniebruin, met ingebedde fijne kruiding. De smoke ring is dun, subtiel en plaatselijk onderbroken. Toon mals gaar vlees met rustige vezeltekening en plaatselijk vocht, zonder plastic glans, herhaalde patronen, identieke plakken of diep ingetekende vezelgroeven. Houd het volledige product in beeld; geen beeldvullende macro-opname.',
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
            'brisket' => 'PRODUCTVORM EN ORIËNTATIE BRISKET: dit is één grote, hele en ongetrimde brisket. Leg de brisket altijd natuurlijk en stabiel plat op de breedste zijde op het hout, met de lange as overwegend horizontaal door het beeld. De brisket mag nooit staan, rechtop worden gezet, op de smalle kop rusten, tegen iets leunen of verticaal worden gekadreerd. Behoud de lange, brede, aaneengesloten vorm van het verpakte stuk en de zichtbare verhouding tussen het plattere en dikkere deel. Maak er nooit een losse stapel, platte lap, compact blok, opengevouwen of gevlinderd stuk van. Alle spier- en vetdelen blijven verbonden binnen precies dezelfde buitenomtrek als op de echte productreferenties.',
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
            // An explicitly named steak keeps its existing plan; ordinary sucade defaults to stewing.
            'stoof' => ['sucade', 'sukade', 'stoofvlees', 'suddervlees', 'stooflappen', 'sudderlappen'],
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

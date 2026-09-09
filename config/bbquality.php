<?php

return [
    'tone_of_voice' => [
        'version' => 5,
        'reviewed_at' => '2026-09-07',
        'name' => 'BBQuality — de vakman aan je BBQ',
        'principles' => [
            'Schrijf persoonlijk, enthousiast en nuchter. Je praat met een klant, niet over een doelgroep. Gebruik je, jij en waar passend onze.',
            'Vertel direct wat je krijgt en wat dit product lekker, handig of bijzonder maakt. Een vraag of uitnodiging mag bij een saus of gemakkelijk maaltijdproduct.',
            'Wissel korte en iets langere zinnen af. Geen verplicht openingssjabloon. Een korte zin mag wanneer hij iets toevoegt.',
            'Maak kenmerken tastbaar: de vorm helpt bij portioneren, vet geeft sappigheid, een saus maakt een burger romig. Leg vaktermen in één gewone zin uit.',
            'Heerlijk, mooi, mals, sappig en lekker passen bij ons; stapel geen superlatieven. Maak geen garanties van algemene productkennis.',
            'De korte producttekst is echt kort: meestal 35–65 woorden. De uitgebreide tekst vertelt meer in 2–5 eigen, concrete tussenkoppen. Geen minimumlengte per alinea.',
            'Elk blok voegt iets toe. Maak geen apart landenverhaal of encyclopedisch snitblok om ruimte te vullen. Eindig niet elk blok met dezelfde verkooppraat.',
            'FAQ’s beantwoorden echte koopvragen: wat is het, wat krijg je, wat is het verschil, waarvoor past het? Begin het antwoord direct. Geen zoekwoordstapeling of LLM-jargon.',
            'Specifieke herkomstverhalen, voer, keurmerken, raspercentages, trimwerk en marmeringsscores vereisen bewijs voor dit product.',
            'Technisch bereidingsadvies hoort bij de aangeleverde expertstip. Nooit zelf een vakman, handtekening, bezoek of persoonlijke ervaring verzinnen.',
        ],
        'avoid' => [
            'Rapporttaal zoals rundvleespositionering, duidelijke identiteit en wordt aangeboden onder.',
            'Defensieve bijzinnen over wat we niet weten. Onbekende claims weglaten; noodzakelijke controles apart zetten.',
            'Wettelijke etiketbenamingen zoals gekoeld rundvlees zonder been in commerciële tekst.',
            'Kunstmatige opvulling, herhaling en een volledige productnaam in iedere FAQ.',
        ],
        // Short quotations from the supplied PDPs: style references, never product evidence.
        'sources' => [
            ['type' => 'meat', 'keywords' => ['brisket'], 'title' => 'Black Angus brisket El Rancho Uruguay', 'url' => 'https://www.bbquality.nl/product/black-angus-brisket-el-rancho-uruguay/', 'excerpt' => 'Je krijgt zowel de flat als de point. Bovendien leveren we deze brisket al getrimd. Daardoor hoef je zelf nog maar weinig snijwerk te doen.', 'lesson' => 'Aanbod → eigenschap → praktisch voordeel. Het trimwerk geldt alleen voor het bronproduct.'],
            ['type' => 'meat', 'keywords' => ['short ribs'], 'title' => 'Wagyu short ribs', 'url' => 'https://www.bbquality.nl/product/wagyu-short-ribs/', 'excerpt' => 'Wagyu short ribs behoren tot de absolute favorieten van liefhebbers van low & slow barbecue.', 'lesson' => 'Herkenbare BBQ-context, daarna marmering en structuur uitleggen.'],
            ['type' => 'meat', 'keywords' => ['picanha'], 'title' => 'Wagyu picanha tips', 'url' => 'https://www.bbquality.nl/product/wagyu-picanha-tips/', 'excerpt' => 'Deze Wagyu picanha tips zijn afkomstig uit de punt van de picanha, één van de meest geliefde delen van het rund.', 'lesson' => 'Direct de precieze snit uitleggen. Tips zijn niet hetzelfde als een hele picanha.'],
            ['type' => 'fish', 'keywords' => ['dorade'], 'title' => 'Dorade filet met huid', 'url' => 'https://www.bbquality.nl/product/dorade-filet-met-huid/', 'excerpt' => 'Onze dorade filet met huid is een verfijnd stukje vis met een milde smaak en fijne structuur.', 'lesson' => 'Persoonlijke opening, smaak en structuur in gewone woorden. Daarna het voordeel van de huid.'],
            ['type' => 'fish', 'keywords' => ['tonijn', 'saku'], 'title' => 'Tonijn saku', 'url' => 'https://www.bbquality.nl/product/tonijn-saku/', 'excerpt' => null, 'lesson' => 'Eerst de saku-vorm begrijpelijk maken, daarna de praktische betekenis van het gelijkmatig gesneden blok. Zelfstandige FAQ-antwoorden.'],
            ['type' => 'sauce', 'keywords' => ['umami'], 'title' => 'BBQuality The Umami', 'url' => 'https://www.bbquality.nl/product/bbquality-the-umami/', 'excerpt' => 'Maak kennis met BBQuality The Umami, de nieuwste smaakmaker in ons assortiment!', 'lesson' => 'Uitnodigende opening, herkenbare smaken en concrete toepassingen. Nieuw niet automatisch op een volgend product plakken.'],
            ['type' => 'sauce', 'keywords' => ['truff', 'truffle'], 'title' => 'BBQuality The Truffle', 'url' => 'https://www.bbquality.nl/product/bbquality-the-truffle/', 'excerpt' => 'Maak van elke hap iets bijzonders met BBQuality The Truffle, onze romige plantaardige truffel mayo.', 'lesson' => 'Smaak en eetmoment voorop. Romig en plantaardig zijn hier productfeiten, geen standaardclaims.'],
            ['type' => 'prepared', 'keywords' => ['döner', 'doner'], 'title' => 'Döner kebab gesneden', 'url' => 'https://www.bbquality.nl/product/doner-kebab-gesneden/', 'excerpt' => 'Zin in een goed gevuld broodje döner, een kapsalon of pizza met kruidige döner?', 'lesson' => 'Een herkenbaar eetmoment als opening. Verduidelijk wat al voorbereid is; neem geen receptuur over.'],
        ],
    ],
];

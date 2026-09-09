# Productstudio — audit en oplevering, 7 september 2026

## Resultaat en afbakening

De lokale Productstudio is gecontroleerd van broninvoer tot conceptexport. De audit is uitgevoerd vanuit de dagelijkse route van een medewerker: productnaam/etiket → feiten controleren → tekst en FAQ → beelden → export. Screenshots, daadwerkelijke AI-proeven en automatische tests zijn gecombineerd; een geslaagde automatische test is niet gebruikt als bewijs voor goede schrijfstijl of natuurgetrouwe fotografie.

Er is uitsluitend in de lokale testomgeving gewerkt, op `feature/product-dossier-test`. Bestaande wijzigingen in de werkmap zijn behouden. Er is niets op de BBQuality-website gepubliceerd, geen WordPress-koppeling geactiveerd en geen productieconfiguratie gewijzigd. Voor de browserproeven is een apart lokaal testaccount gebruikt.

## Bevindingen per stap en uitgevoerde verbeteringen

| Stap | Bevinding | Afgerond in deze wijziging |
|---|---|---|
| Starten | Veel introductieruimte; de daadwerkelijke invoer kwam op een smal scherm laat in beeld. | Compacte test-/koppelingsstatus, aangepaste zijbalk, aanklikbare stappen en blijvende opslagknoppen. |
| Productnaam en etiket | Lange analyseaanvraag; onduidelijke of verdwijnende fouten; ontbrekende ingrediënten/allergenen. | Analyse in een aparte achtergrondtaak; leesbare thumbnails; direct invullen uit etiket of verantwoord gemarkeerde inschatting. Onbekende samengestelde recepturen worden niet verzonnen. |
| Ontbrekende voedingswaarden | Tweede handeling nodig; kans dat een schatting bestaande waarden vervangt. | Na etiketanalyse automatisch aanvullen waar verantwoord. Bestaande waarden blijven staan, met bron per veld. Een mislukte aanvullende schatting gooit de geslaagde etiketanalyse niet weg. |
| Gegevens controleren | Handmatige correcties en broninformatie konden verloren gaan bij opslaan vanuit een oude tab. | Revisiecontrole, bronbehoud, invullen uitsluitend in lege velden, herstelbare browserinvoer en scheiding tussen productfeiten en klantentekst. |
| Bewaren | Het echte leveranciersetiket vermeldt gekoelde bewaring, terwijl de verkoop diepgevroren is. | Tegenstrijdigheid blijft intern zichtbaar; gekoeld labeladvies wordt niet automatisch klantadvies. Ook oude conflicterende bewaartekst wordt uitgesloten van het publiceerbare exportveld. |
| Producttekst | Geforceerde lengte, afstandelijke formuleringen en tegenstrijdige schrijfregels. Stijlvoorbeelden en productclaims liepen door elkaar. | Nieuw centraal schrijfprofiel met echte korte PDP-fragmenten, natuurlijke lengte, concrete tussenkoppen en productgericht ritme. Productclaims uit een voorbeeld gelden niet voor nieuwe producten. |
| Genereren | Lang openstaande webaanvragen, dubbele verzoeken, onvoldoende blijvende foutinformatie. | HTTP 202 en databasequeue, maximaal één actieve opdracht per dossier, echte verstreken tijd, hervatten van status na heropenen, blijvende fouten en behoud van eerdere tekst. API-budgetproblemen worden onderscheiden van tijdelijke aanvraaglimieten. |
| Redactie en FAQ | Tekst moeilijk na te bewerken; één blok onhandig om over te nemen. | Teksteditor voor intro, secties, vragen, antwoorden en SEO. Vraag en antwoord apart kopieerbaar. Laatste tien tekstversies herstelbaar. Altijd 5–7 verschillende FAQ's bij generatie. |
| Expertstip | Foto en persoonlijke handtekening ontbraken in het dossier. | Optionele privé-uploads en expliciete goedkeuring voor persoonlijke tip/presentatie. AI verzint geen medewerker, citaat, bezoek of ervaring. |
| Productfoto's | PNG als normale download; beelden en productdossier stonden los. Lokaal kon beeldwerk de webserver blokkeren. | Automatische verliesvrije WEBP-download met beschrijvende metadata; origineel behouden voor aanpassingen. Fotoset koppelbaar aan eigen dossier. Lokale beeldworker los van webserver en tekstworker. |
| Export | WordPress-blok was vooral een vooruitblik; schattingen konden als feiten worden opgevat. | JSON-conceptpakket en veilige HTML. Onbevestigde samenstelling/voedingswaarden blijven apart bij interne controle. Media komen mee als nog te uploaden bestanden; geen verzonnen prijzen, voorraad, beoordelingen of URL's. |

## De BBQuality-tone of voice

Centraal bestand: `config/bbquality.php`, versie 5. In de studio zichtbaar onder **Onze schrijfstijl**. De feitelijke generatie staat in `app/Services/ProductDossierAiService.php`.

De toon: een toegankelijke slager/BBQ-vakman die tegen de klant praat. Je/jij, concrete productkenmerken, smakelijk zonder lege superlatieven, afwisselende zinnen en begrijpelijke uitleg. Vertel eerst wat je krijgt, vervolgens waarom dat lekker of bruikbaar is. Geen “rundvleespositionering”, bureaucratische voorbehouden, wettelijke benamingen of interne controles in commerciële tekst.

Korte tekst doorgaans 35–65 woorden; uitgebreide tekst 2–5 betekenisvolle onderwerpen zonder verplichte opvulling. De schrijver kiest passende voorbeelden bij producttype/productnaam. Er is maximaal één redactionele herstelronde, geen eindeloze keten van herschrijvingen. Het ingestelde kwaliteitsniveau is niet verlaagd.

Deze acht opgegeven bronpagina's zijn daadwerkelijk bekeken:

- [Tonijn saku](https://www.bbquality.nl/product/tonijn-saku/): productvorm en gebruik concreet uitleggen.
- [The Umami](https://www.bbquality.nl/product/bbquality-the-umami/): toegankelijke introductie, smaak en herkenbare eetcombinaties.
- [Wagyu short ribs](https://www.bbquality.nl/product/wagyu-short-ribs/): begrijpelijke snituitleg en enthousiasme voor bereiding.
- [Black Angus brisket El Rancho Uruguay](https://www.bbquality.nl/product/black-angus-brisket-el-rancho-uruguay/): duidelijke productopbouw, whole packer en het specifieke trimwerk.
- [The Truffle](https://www.bbquality.nl/product/bbquality-the-truffle/): uitnodigende opening en smaakervaring.
- [Wagyu picanha tips](https://www.bbquality.nl/product/wagyu-picanha-tips/): precies benoemen welke productvorm wordt geleverd.
- [Döner kebab gesneden](https://www.bbquality.nl/product/doner-kebab-gesneden/): praktisch gebruik en herkenbare toepassingen; tegenstrijdige receptuurdetails niet overnemen naar andere producten.
- [Dorade filet met huid](https://www.bbquality.nl/product/dorade-filet-met-huid/): mild/fijn, productkenmerk direct koppelen aan voordeel.

Voorbeeld uit de daadwerkelijke tweede AI-proef, op basis van expliciet ingevoerde feiten:

> De Black Angus brisket El Rancho Uruguay is een complete whole packer met zowel de flat als de point. Hij is speciaal voor BBQuality getrimd en heeft een marmeringsscore van minimaal MBS 3+. Een prachtige brisket uit Uruguay voor wie graag uitgebreid low & slow aan de slag gaat op de BBQ of smoker.

Dit is een gegenereerd concept, geen universele tekst voor iedere brisket. Whole packer, trimwerk en MBS zijn in deze proef aangeleverde productfeiten. De bronpagina's zijn geen vrijbrief om zulke claims naar andere producten over te zetten. De uiteindelijke merkredactie blijft bij BBQuality; menselijkheid is niet volledig met een automatische test vast te stellen.

## Vindbaarheid voor zoekmachines en LLM's

De studio maakt beschrijvende titels, natuurlijke metaomschrijvingen, een slug, zelfstandige FAQ-antwoorden, feitelijke productsamenvatting en beeldmetadata. Dit ondersteunt duidelijke, consistente productpagina's, maar een lokale conceptgenerator maakt een pagina nog niet vindbaar op internet.

Er bestaat geen speciale metadata-set die aanbevelingen door een LLM garandeert. De uiteindelijke pagina moet openbaar, crawlbaar en indexeerbaar zijn; zichtbare tekst, productgegevens en structured data moeten overeenkomen. Zie [Google over AI-functies](https://developers.google.com/search/docs/appearance/ai-features). Alt-tekst en relevante tekst horen bij de gepubliceerde afbeelding op de webpagina; alleen een WEBP-bestandsnaam is niet voldoende. Zie [Google Images-richtlijnen](https://developers.google.com/search/docs/appearance/google-images).

De export bevat daarom een beperkte `Product`-opzet zonder verzonnen `Offer`, voorraad, reviews of publieke URL. Echte winkelgegevens en openbare beeldlinks worden pas tijdens de toekomstige WordPress-import toegevoegd.

## Verificatie

- Volledige Laravel-suite: **169 tests geslaagd, 1.302 assertions**.
- JavaScript-functietests: **8 geslaagd**. Onder andere knopvergrendeling, bronbehoud, browserherstel en apart kopieerbare FAQ-delen.
- Twee daadwerkelijke tekstgeneraties via de ingestelde AI-koppeling: ongeveer **37 en 55 seconden**. De eerste proef is gebruikt om de stijl verder aan te scherpen. Dit zijn waarnemingen, geen gegarandeerde doorlooptijden.
- Daadwerkelijke etiketanalyse met het aangeleverde brisketetiket: ongeveer **28 seconden**; aanvullende gegevensproef ongeveer **16 seconden**. De definitieve gezamenlijke eindproef duurde **42 seconden** en vulde herkomst, leverancier, ingrediënten, allergenen en voedingswaarden in, met de juiste bron-/schattingsmeldingen. Opnieuw opslaan behield die bronmeldingen.
- In de browser getest: invoer, echte foto-upload, achtergrondstatus, heropenen, tekst bewerken, opgeslagen versiegeschiedenis en daadwerkelijke JSON-download. Breedtes 1.440 en 689 px gecontroleerd: geen horizontale overflow. De browserconsole gaf bij de eindproef geen fouten.
- Verliesvrije WEBP-conversie getest op afmetingen, pixelwaarden en transparantie. Bestaande tests voor beeldvarianten, prompts, referenties, aanpassen en stijlbibliotheek blijven onderdeel van de suite.
- Fouten gesimuleerd: API-budget uitgeput, mislukte aanvulling, achtergebleven queue-opdracht, dubbele klik, gewijzigde brongegevens tijdens generatie, oude tab, toegang tot andermans dossier/media en onveilige HTML-inhoud in export.
- Geen nieuwe betaalde fotoset gegenereerd tijdens deze audit. De fotografische vormgetrouwheid van nieuwe beelden vereist visuele controle; code-tests bewijzen dat niet. Sauzen/rubs behouden de verplichte menselijke etiketcontrole voor downloaden en als stijl opslaan.

Lokale visuele bewijsbestanden staan in `storage/app/studio-audit-20260907/`: `before.png`, `after-desktop.png` en `after-narrow.png`. Dit zijn testschermen, geen assets voor publicatie.

## Lokaal starten en storingen herkennen

Voer na eventuele nieuwe migraties uit:

```bash
php artisan migrate
bash scripts/start-productstudio-local.sh
```

De helper weigert buiten `APP_ENV=local` en start drie lokale processen via launchctl:

- `nl.bbquality.pitboard-test`: webserver op `127.0.0.1:8000`.
- `nl.bbquality.productstudio-worker`: queue `product-content`, timeout 540 seconden.
- `nl.bbquality.productstudio-images`: queue `images`, timeout 600 seconden.

`DB_QUEUE_RETRY_AFTER` is standaard 660 seconden, dus groter dan beide worker-timeouts. Een onduidelijke timeout veroorzaakt geen automatische betaalde herkansing. De bestaande twee gelijktijdige beeldvarianten binnen één fotoset blijven behouden. Tekst en beeld hebben elk hun eigen worker zodat een fotoset geen teksttaak blokkeert. Externe projectlimieten gelden nog steeds voor beide.

Logs: `storage/logs/productstudio-worker.*.log`, `storage/logs/productstudio-images.*.log` en `storage/logs/local-server.*.log`. Processen overleven een gesloten terminal; opnieuw starten kan nodig zijn na uitloggen of herstarten van macOS. Sleutels worden niet in deze documentatie opgenomen.

## Nog benodigde externe keuzes — niet stilzwijgend ingevuld

1. **WordPress/WooCommerce-testkoppeling:** URL, toegangsgegevens met beperkte rechten, taxonomie-ID's en mapping van de echte PDP-/expert-/SEO-velden. De export is klaar als conceptpakket, maar de API-import is niet actief. Publiceren is niet uitgevoerd.
2. **Voedingswaarden en allergenen bevestigen:** schattingen helpen bij conceptwerk, maar vormen geen productspecificatie of bewijs van afwezigheid. Onbekende recepturen of kruisbesmetting moeten uit betrouwbare productinformatie komen.
3. **Definitieve merk- en beeldgoedkeuring:** keur de proefteksten en actuele foto's inhoudelijk goed. Generatieve modellen kunnen vorm, hoeveelheid, labels of productclaims verkeerd weergeven; de broncontrole blijft nodig.
4. **Live SEO/GEO-validatie:** pas na daadwerkelijke import zijn canonicals, indexeerbaarheid, openbare afbeeldingen, interne links en overeenstemming van zichtbare gegevens/structured data op de echte PDP te testen. Deze lokale wijziging wijzigt die productiepagina's niet.

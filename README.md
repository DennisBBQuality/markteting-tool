# BBQuality The Pitboard — Marketing Team Tool

Een uitgebreide marketing team samenwerkingstool gebouwd met Laravel 12 en een vanilla JavaScript SPA-frontend. De applicatie is volledig Nederlandstalig en biedt projectmanagement, taakbeheer, kalenderplanning en meer.

## Kernfunctionaliteiten

- **Projectmanagement** — Projecten met status (actief/gepauzeerd/afgerond/gearchiveerd), prioriteit (laag/normaal/hoog/urgent) en deadlines
- **Taakbeheer** — Kanban-stijl taken (todo/bezig/review/klaar) met drag-and-drop herordening
- **Kalender** — Evenementen voor content, deadlines, meetings, social posts, emails en blogs (FullCalendar integratie)
- **Sticky Notes** — Kleurgecodeerde notities gekoppeld aan projecten en taken
- **Bestandsbijlagen** — Uploads tot 10MB gekoppeld aan projecten, taken, kalenderitems en notities
- **Afbeeldingen** — AI-productfotogenerator voor vlees, vis, sauzen/rubs en totaalpakketten, plus batch WebP-conversie
- **Productstudio** — Etiketanalyse, beheerbare productkeuzes, gestructureerde PDP-teksten, voedingswaardeschattingen en voorbereiding op WordPress-concepten
- **Dashboard** — Statistieken over projecten, actieve taken, deadlines en kalenderitems

Klantenservice is op verzoek van Dennis voorlopig uit het Pitboard gehaald (11 september 2026). De navigatie, het scherm en het laden van de module zijn verwijderd; de ticket-API is standaard uitgeschakeld. Bestaande ticketgegevens en de onderliggende code blijven behouden. Zie [pauzeren van Klantenservice](docs/customer-service-paused-2026-09-11.md).

## Technische Stack

| Component      | Technologie                          |
|----------------|--------------------------------------|
| Backend        | Laravel 12, PHP 8.2+                |
| Frontend       | Vanilla JavaScript SPA, Tailwind CSS |
| Database       | SQLite (configureerbaar naar MySQL)  |
| Build tool     | Vite 7                               |
| Authenticatie  | Custom session-based                 |
| Kalender       | FullCalendar 6.1                     |
| Iconen         | FontAwesome 6.5                      |

## Installatie

```bash
# Kloon de repository
git clone <repository-url>
cd marketing-team-tool

# Installeer PHP dependencies
composer install

# Installeer Node dependencies
npm install

# Kopieer environment bestand
cp .env.example .env

# Genereer application key
php artisan key:generate

# Maak de SQLite database aan
touch database/database.sqlite

# Draai migraties
php artisan migrate

# Maak storage link aan
php artisan storage:link

# Build frontend assets
npm run build
```

## Ontwikkeling

```bash
# Start de development server met ruimte voor vier etiketfoto's en langere AI-taken
composer run serve-local

# Start Vite dev server voor hot reload
npm run dev
```

## Database Structuur

De applicatie gebruikt 10 modellen, allemaal met UUID primary keys:

- **Users** — Naam, email, rol (admin/manager/lid), kleur, avatar
- **Projects** — Naam, beschrijving, kleur, status, prioriteit, deadline
- **Tasks** — Titel, beschrijving, status, prioriteit, toewijzing, positie (voor drag-and-drop)
- **Calendar Items** — Titel, type, start/einddatum, kleur
- **Notes** — Titel, inhoud, kleur (standaard geel)
- **Attachments** — Bestanden tot 10MB, gekoppeld aan projecten/taken/kalender/notities

## API Overzicht

Alle API routes zijn beschermd met custom session-based authenticatie.

| Endpoint                  | Beschrijving                              |
|---------------------------|-------------------------------------------|
| `POST /api/auth/login`    | Inloggen                                  |
| `GET /api/auth/me`        | Huidige gebruiker ophalen                 |
| `GET/POST /api/projects`  | Projecten lijst en aanmaken               |
| `GET/POST /api/tasks`     | Taken lijst en aanmaken (met filters)     |
| `PUT /api/tasks/reorder/batch` | Batch herordening voor drag-and-drop |
| `GET/POST /api/calendar`  | Kalenderitems lijst en aanmaken           |
| `GET/POST /api/notes`     | Notities lijst en aanmaken                |
| `GET/POST /api/attachments` | Bijlagen lijst en uploaden              |
| `POST /api/convert/webp`  | Batch WebP conversie                      |
| `GET/PUT /api/images/prompt` | Productfotoprompt lezen of instellen   |
| `POST /api/images/generate` | Productfoto-opdracht veilig in de wachtrij zetten |
| `GET /api/images/requests/{id}` | Voortgang en resultaat van een productfoto-opdracht |
| `GET /api/dashboard/stats`| Dashboard statistieken                    |

## Productfoto-generator

### Websiteformaat en unieke namen (18 september 2026)

De webshop toont productbeelden in 4:3 (gecontroleerd: 840 × 630). De generator maakt direct liggende beelden van 1536 × 1152, inclusief nabewerkingen, zonder achteraf bijsnijden of uitrekken. Afwijkende providerafmetingen worden geweigerd zonder betaalde automatische herhaling. WEBP behoudt de pixels en de voorbeeldkaarten tonen het hele beeld. Bestaande beelden en SEO worden niet herschreven.

De additieve migratie `2026_09_18_100000_create_product_image_download_names_table` bewaakt unieke beschrijvende downloadnamen met een primaire sleutel. Bestaande metadata wordt daarnaast gecontroleerd. Geen wijzigingen aan Taken, Kalender, Notities of Projecten. Zie [controle en grenzen](docs/image-format-filenames-2026-09-18.md).

### Kiesbare foto-varianten en beeldspecifieke SEO (16 september 2026)

Bij **Vlees** en **Vis** kiest de gebruiker zelf minimaal één groep: **Rauwe variant (2)**, **BBQ (2)**, **Pan (1)**, **Oven (1)** en **Airfryer (1)**. Standaard staan Rauw, BBQ en Pan aan (vijf foto's); alles samen levert zeven foto's op. De bestaande rauwe en BBQ-stijlen blijven behouden. De drie keukenvarianten gebruiken een lichte moderne woonkeuken met diffuus raamlicht, gebroken wit/zand/licht steen en het passende apparaat zichtbaar op de achtergrond. De drie door Dennis aangeleverde voorbeelden sturen alleen setting/licht/compositie, nooit productsoort, hoeveelheid of gerecht. Stoofproducten blijven gestoofd en vis behoudt zijn vorm/soort/hoeveelheid. De setting is geen garantie dat elk product geschikt is voor dat apparaat. Saus/rub en totaalpakket blijven twee beelden. Bestaande vier- en vijffotosets blijven bruikbaar; oude API-aanvragen zonder variantkeuze behouden het eerdere vijfbeeldplan.

Na generatie en na elke nabewerking analyseert een aparte achtergrondtaak **de daadwerkelijke PNG van elke fotoversie** voor vijf Nederlandse SEO-velden: bestandsnaam, alt-tekst, afbeeldingstitel, bijschrift en beschrijving. De tekst gebruikt de bestaande beeldgeschikte Productstudio-tekstmodelconfiguratie (`OPENAI_PRODUCT_CONTENT_MODEL`); het gekozen afbeeldingsmodel blijft uitsluitend voor de foto zelf. Elke analyse is een extra API-aanroep. Fouten bij SEO verwijderen geen foto of eerder opgeslagen SEO; ze blijven zichtbaar in het SEO-venster. De fake-driver doet geen externe analyse en meldt dit expliciet.

Sinds 17 september zijn er **vier Pan-, vijf Oven- en vier Airfryer-voorbeelden**. Nieuwe opdrachten wisselen per gebruiker en categorie door deze reeks; de gekozen ID wordt in de opdracht bewaard. Ook productpresentatie in de pan, op een bakplaat of in een passende airfryermand is mogelijk, zonder dubbele porties. Logo's en screenshotranden uit de voorbeelden worden expliciet uitgesloten. Bestaande rauwe/BBQ-stijlen veranderen niet. Zie `docs/image-seo-kitchen-2026-09-16.md` voor tests, grenzen en de releasechecklist.

**SEO-gegevens** opent een bewerkbaar venster met afzonderlijke kopieerknoppen, alles kopiëren, opslaan en opnieuw analyseren. De opgeslagen naam wordt gebruikt voor WEBP-download en dossierexport. Namen zijn kleine letters met koppeltekens tussen woorden en `.webp`; Nederlandse samenstellingen blijven intact, bijvoorbeeld `varkenswangen-ontvliesd-gestoofd-aardappelpuree.webp`. Namen bevatten geen cijfers of volgnummers. AI levert per foto enkele beschrijvende alternatieven; een gedeeld naamregister voorkomt gelijke namen tussen fotosets en gebruikers. Bij een handmatige dubbele naam verschijnt een foutmelding. WEBP-download vraagt eerst geldige beeldspecifieke SEO. Interne bestanden/versies worden niet hernoemd. Bijgerechten zijn serveersuggesties, geen claims over productinhoud. Onzekere zichtbare details mogen niet worden verzonnen; menselijke controle vóór publicatie blijft nodig.

Handmatige teksten zijn beschermd tegen laat binnenkomende analyses en verouderde tabbladen. Opnieuw analyseren vraagt bevestiging bij handmatige teksten. Een nieuwe fotoversie krijgt eigen metadata; herstel van een oude versie neemt de daarbij bewaarde SEO mee. Ook bestaande database-opgeslagen foto's kunnen afzonderlijk opnieuw worden geanalyseerd zonder nieuwe foto te maken.

De additieve migratie `2026_09_16_140000_create_product_image_metadata_table` voegt alleen de metadatatabel toe. Geen wijziging van Taken, Kalender, Notities of Projecten. Bij een databasequeue is de fototaak-timeout 1200 seconden en de standaard `DB_QUEUE_RETRY_AFTER` 1320 seconden; een expliciete omgevingswaarde moet hoger zijn dan de taak-timeout. Herstart bestaande workers na een release. De bestaande deferred-uitvoering blijft ondersteund: automatische SEO wordt binnen het al lopende achtergrondproces afgehandeld, niet als geneste deferred-callback. Deze wijziging maakt meer afbeeldingen en extra tekstanalyses, dus kan meer tijd en API-kosten gebruiken.

Referentiefoto's kunnen worden geüpload, gesleept of toegevoegd met **Afbeelding plakken**. Kopieer de afbeelding zelf, niet alleen de link. De plakknop vraagt zo nodig browsertoestemming; bij ontbrekende ondersteuning of geweigerde toegang wordt het fotovak geselecteerd voor **Cmd+V / Ctrl+V**. Sneltoetsplakken werkt alleen in het referentiefotovak en verandert het plakken in tekstvelden niet. Er worden uitsluitend afbeeldingsbestanden verwerkt, geen klembordtekst of externe afbeeldingslinks. De bestaande limieten (vijf foto's, JPG/PNG/WEBP, 10 MB per foto), hoofdfotokeuze en normale upload blijven gelden. Plakken start geen AI-opdracht en verstuurt de foto nog niet naar de server.

De module **Afbeeldingen** gebruikt lokaal standaard de kostenloze `fake`-driver. Daarmee kan de volledige upload- en resultaatflow worden getest zonder externe verzoeken of API-kosten.

Een beheerder kan de OpenAI API-sleutel veilig instellen via **Instellingen → AI-koppelingen**. De sleutel wordt met de Laravel-applicatiesleutel versleuteld in de database opgeslagen, wordt nooit teruggestuurd naar de browser en komt niet in Git terecht.

Als alternatief kan een serverbeheerder de koppeling via de productie-`.env` instellen:

```dotenv
PRODUCT_IMAGE_DRIVER=openai
OPENAI_API_KEY=<jouw-api-sleutel>
```

De standaardadapter gebruikt `gpt-image-2.5-sunburst` via de OpenAI Image Edit API met hoge uitvoerkwaliteit. Er kunnen maximaal vijf productreferentiefoto's worden meegestuurd, waarbij de medewerker één hoofdfoto aanwijst en altijd zelf het exacte productaantal invult. Voor GPT Image 2 en 2.5 wordt de legacy-instelling `input_fidelity` niet meegestuurd. De afzonderlijke varianten worden maximaal twee tegelijk gemaakt om de wachttijd te verkorten zonder model, resolutie, PNG-formaat, referenties of hoge kwaliteitsinstelling te wijzigen. Voor bereide beelden, de vaste rauwe BBQuality-achtergrond en de vaste buitenstijl wordt automatisch één apart, intern BBQuality-stijlvoorbeeld toegevoegd. De prompt maakt streng onderscheid tussen productreferenties en die laatste stijl- of achtergrondreferentie. Eén rauwe variant gebruikt altijd de echte BBQuality-achtergrondstijl met een zwart achtervlak en warm hout; bereide beelden mogen deze referentie nooit gebruiken. Bereide vleesbeelden gebruiken gegarandeerd twee verschillende scènefamilies (buiten-BBQ en serveermoment), met productspecifieke regels voor onder andere steak, brisket, MOINK balls, burgers en ribs. Rauw vlees krijgt extra regels voor behoud van silhouet, verhoudingen, vetkap, kleur en natuurlijke structuur. Een medewerker kan daarna uitsluitend de gekozen foto laten aanpassen; eerdere versies blijven herstelbaar. Een perfecte actuele versie kan vanuit de resultaatkaart met productnaam aan de gedeelde stijlbibliotheek worden toegevoegd. Bij een volgende opdracht voor exact hetzelfde product, dezelfde productsoort en dezelfde variantstijl wordt de laatst goedgekeurde foto als kwaliteitsanker meegestuurd; de nieuwe echte productreferenties blijven altijd leidend voor vorm en hoeveelheid. Voor sauzen en rubs is vóór downloaden en opslaan als stijl een handmatige etiketcontrole verplicht, omdat generatieve beeldmodellen exacte tekst niet betrouwbaar kunnen garanderen. Resultaten worden altijd als PNG verwerkt. Model, kwaliteit en timeout zijn configureerbaar via de bijbehorende `OPENAI_IMAGE_*` variabelen in `.env.example`. Alle nieuwe generaties en nabewerkingen gebruiken vast 1536 × 1152 (4:3); een oudere `OPENAI_IMAGE_SIZE` wordt niet meer gebruikt. API-sleutels horen nooit in Git.

### Natuurlijkere bereide fotografie (testprofiel 11 september 2026)

`ProductImagePromptBuilder` scheidt voor de bereide varianten productidentiteit van veranderingen door garing. Het ingebouwde basisprofiel krijgt alleen voor bereid vlees een gespecialiseerde inleiding; eigen beheerdersteksten blijven behouden en opgeslagen prompts worden niet overschreven. Zacht diffuus licht, rustige contrasten, subtiele vezeltekening, plaatselijke vochtglans en een ruimere compositie vervangen de nadruk op grove kruiding en uitgesproken detail. Brisket houdt aansluitende snijvlakken, een samenhangende bark en het opgegeven aantal oorspronkelijke stukken. Medium/ongesneden versus medium/gesneden steak blijft behouden.

De bestaande gebundelde bereide stijlbeelden sturen alleen omgeving, camerahoek en ruimtelijke opbouw, niet korst, vleesstructuur, glaze of scherpte. Er zijn geen nieuwe referentiefoto's toegevoegd; opgeslagen goedgekeurde voorbeelden blijven bruikbaar met expliciete bescherming tegen het kopiëren van overmatige korrel en glans. Rauw, sauzen/rubs, totaalpakketten, modelkeuze, hoge kwaliteit, resolutie, verliesvrije WEBP-export en parallelle verwerking zijn ongewijzigd. Dit is een technisch getest promptprofiel, geen visueel goedgekeurde nieuwe fotoset. Zie `docs/cooked-image-prompt-2026-09-11.md` voor vergelijking en controlepunten.

De tweede verfijningsronde, op basis van de bizon-ribeyevoorbeelden, bewaakt daarnaast de bronafhankelijke asymmetrie, verhoudingen en vetnaden. De hoofdfoto bepaalt het exemplaar; andere aanzichten mogen geen geïdealiseerde tussenvorm opleveren. Vet mag bij garing slinken en plaatselijk glanzen, maar wordt niet verminderd op basis van een algemene aanname over de diersoort. De buitenvariant krijgt neutrale daglichtkleuren en geen standaard uitgestrooide kruiding; steak krijgt fijne, spaarzame kruiding. De bestaande serveerscène en bedoelde glaze bij bijvoorbeeld MOINK balls blijven behouden. Ook deze ronde vraagt een nieuwe fotoset voor visuele beoordeling.

Sucade/sukade en expliciet genoemde stoof- of sudderproducten krijgen nu een eigen stoofprofiel: een geserveerd gerecht met jus, een klein natuurlijk losgemaakt deel en bescheiden bijgerechten, zonder nette snijplakken. Buiten wordt dit op een bord gepresenteerd, aan tafel in een ondiepe stoofpan. Deze bijgerechten zijn serveersuggesties, geen productingrediënten. Een expliciete steaknaam behoudt het bestaande steakprofiel; dit is gerichte naamherkenning, geen algemene automatische bereidingsanalyse. De rauwe varianten blijven gelijk. Het stoofprofiel krijgt geen gebundeld grill-/brisketvoorbeeld; uitsluitend een exact passend, later goedgekeurd stoofvoorbeeld kan worden hergebruikt onder de nieuwe stijl-ID's. Bestaande bibliotheekfoto's en resultaten worden niet gemigreerd of gewijzigd.

### Visfotografie

De knop **Vis** omvat ook schaal- en schelpdieren en biedt dezelfde variantkeuzes als Vlees. Rauw 1 gebruikt een zwarte achtergrond én zwarte ondergrond naar het door Dennis aangeleverde echte visvoorbeeld. Rauw 2 gebruikt de lichte ambachtelijke houtsetting. De zwart/goudbruin-houtachtergrond voor vlees wordt nooit als visreferentie meegestuurd. De vis blijft natuurlijk plat liggen en behoudt zijn verhoudingen, snit en aanwezige huid/schaal. De twee BBQ-varianten delen de bestaande buiten-BBQ- en serveerscènes met vlees, maar gebruiken een eigen visprompt voor garing, materiaal en presentatie. Ook Pan/Oven/Airfryer gebruiken deze productregels. Geen standaard steakplakken of vleesvezels.

De regels staan in `FishProductImageProfile`; vlees, sauzen en pakketten behouden hun bestaande prompts. Nieuwe visstijl-ID's en filtering op producttype voorkomen hergebruik van oude, onder Vlees opgeslagen visresultaten. Het echte zalmvoorbeeld is alleen een achtergrondreferentie, nooit bewijs voor de kleur, vorm of soort van een nieuw product. Technische tests gebruiken gesimuleerde providerantwoorden; de eerste echte visset moet nog visueel beoordeeld worden. Er zijn geen migraties of productie-instellingswijzigingen nodig.

### Afbeeldingsmodel kiezen

De generator heeft een dropdown per fotoset; via **Instellingen → AI-koppelingen** kan alleen een beheerder de standaard voor het team opslaan. Voorrang: expliciete fotosetkeuze → opgeslagen teamstandaard → `OPENAI_IMAGE_MODEL` → `gpt-image-2.5-sunburst`. Sleutels hoeven hiervoor niet opnieuw te worden opgeslagen. Nieuwe opdrachten leggen `image_model` vast in `generation_context`; de worker en nabewerking gebruiken die keuze, ook als een beheerder ondertussen de standaard wijzigt. Oudere opdrachten zonder modelregistratie gebruiken de actuele standaard en vormen geen bewijs welk model ze oorspronkelijk heeft gemaakt.

Bij openen wordt de lijst automatisch vernieuwd als de vorige controle meer dan 24 uur oud is. **Lijst vernieuwen** haalt haar eerder op (maximaal één providercontrole per minuut; vijf minuten pauze na een fout). Dit is verversing tijdens gebruik, geen aparte nachtelijke taak. De lijst komt server-side van OpenAI `GET /v1/models`, met een afzonderlijke cache per API-sleutel. Bij storing blijft de laatste succesvolle lijst maximaal zeven dagen bewaard met een waarschuwing. Zonder eerdere lijst is toegang onbekend; in voorbeeldmodus worden uitsluitend de ingebouwde modellen getoond zonder echte toegang te claimen.

Alleen OpenAI-eigen GPT Image-modelnamen vanaf versie 2 worden opgenomen, inclusief snapshots. Nieuwe onbekende varianten verschijnen als **nieuw, nog niet getest** en vereisen een expliciete testbevestiging. De modellen-API levert geen endpoint-/parametercompatibiliteit: afwijkende nieuwe namen buiten deze familie of nieuwe API-eisen kunnen een adapterwijziging vereisen. Ontdekking verandert nooit de teamstandaard; een weigering start geen vervangende of herhaalde betaalde aanvraag. Hoge kwaliteit, PNG-bron/verliesvrije WEBP-download en maximaal twee parallelle varianten blijven behouden. Het updaten van code wijzigt een expliciete bestaande serverinstelling niet; kies Sunburst desgewenst via de beheer-dropdown. Migratie: alleen de nieuwe tabel `product_image_model_settings`, geen wijziging aan projecten, taken of kalender.

Beeldgeneratie draait als achtergrondtaak. Lokaal gebruikt de module standaard de databasequeue `images`, met een aparte beeldworker: de ingebouwde PHP-server kan anders tijdens het genereren geen volgende webaanvraag of statuscontrole afhandelen. De starthelper hieronder start beide workers. Een expliciete `PRODUCT_IMAGE_QUEUE_CONNECTION`-instelling heeft voorrang. De status wordt tijdens het maken in de database bijgewerkt. Kwaliteit en maximaal twee gelijktijdige beeldvarianten zijn behouden.

Voor een grotere productieomgeving kan `PRODUCT_IMAGE_QUEUE_CONNECTION=database` worden ingesteld. Start dan naast de website permanent een queue-worker:

```bash
php artisan queue:work database --queue=images --timeout=1200 --tries=1
```

Gegenereerde afbeeldingen worden afgeschermd in de gedeelde database opgeslagen. Daardoor blijven ze ook bereikbaar wanneer de achtergrondtaak en de website op verschillende serverprocessen draaien. De beveiligde afbeeldingslinks zijn bewust extensieloos, zodat de webserver ze niet voor openbare statische bestanden aanziet. Alleen de ingelogde medewerker die de opdracht heeft gestart kan ze bekijken of downloaden.

## Productstudio

De module **Productstudio** maakt concepten voor nieuwe producten die nog niet in WordPress staan. De productnaam wordt altijd handmatig en verplicht ingevoerd; het etiket en de overige gegevens mogen ontbreken bij opslaan. Herkomst is volgens de afgesproken inrichting nodig om de pagina te genereren. Een medewerker kan maximaal vier etiketfoto's uploaden. Nieuwe etiketfoto's en expertmedia worden privé in `product_dossier_assets` bewaard, net als de bestaande databaseopslag van gegenereerde beelden. Daarmee blijven ze beschikbaar na een release en op andere web-/worker-instanties. Alleen de eigenaar kan de afgeschermde media-URL openen. Oude lokale concepten met bestandsopslag blijven leesbaar.

Categorie, snit en selectie komen uit beheerbare keuzelijsten die vanuit de Productstudio kunnen worden aangevuld of opgeschoond. Na het opslaan maakt AI een controleerbare PDP-opzet met een korte introductie, twee tot vijf inhoudssecties, vijf tot zeven losse FAQ's, SEO/GEO-velden en controlepunten. De vier resultaatonderdelen staan in afzonderlijke overzichtstabbladen. Wettelijke etikettermen en interne onzekerheden worden nooit als klantentekst gebruikt. Ingrediënten, allergenen en voedingswaarden worden uit het etiket overgenomen of duidelijk als schatting/afleiding gemarkeerd. Bereidingsadvies mag alleen uit de ingevulde expertstip komen.

De BBQuality-tone-of-voice staat centraal in `config/bbquality.php` (versie 5). Dit profiel bevat echte korte fragmenten en bronlinks van acht door BBQuality aangeleverde PDP's, met concrete schrijfprincipes. Het is zichtbaar via **Onze schrijfstijl**. De bronpagina's zijn stijlvoorbeelden, nooit bewijs voor productclaims van een nieuw product. `ProductDossierAiService` kiest passende voorbeelden, scheidt feiten en stijl en maakt maximaal één redactionele herstelronde. Er is geen kunstmatige minimale alinealengte meer.

**Achtergrondverwerking is verplicht.** Etiketanalyse, aanvullen en pagina schrijven vanuit de studio leveren HTTP 202 op. Lokaal gebruiken ze de databasequeue `product-content`. Start in een tweede terminal:

```bash
composer run studio-worker
```

In productie gebruikt de studio standaard Laravel `deferred`, aansluitend op de bestaande beeldgeneratie: de verwerking begint na het versturen van het antwoord, zonder nieuwe hostingworker. Voor een omgeving met een permanente contentworker kan `PRODUCT_CONTENT_QUEUE_CONNECTION=database` worden ingesteld. Die worker moet `product-content` afhandelen; de standaardqueue alleen is niet voldoende. Een aparte worker is bij veel gelijktijdig gebruik aanbevolen, omdat deferred werk een webproces bezet houdt. Er worden door de release geen productie-instellingen gewijzigd.

De worker gebruikt een timeout van 540 seconden en één poging; `DB_QUEUE_RETRY_AFTER` moet groter zijn dan de worker-timeout (gedeelde standaard 1320, ook voor de langere fototaken). Eén opdracht mag maximaal twee AI-verzoeken van ieder maximaal 240 seconden uitvoeren. Er wordt niet automatisch opnieuw betaald na een onduidelijke timeout. Herhaalde klikken hervatten dezelfde actieve opdracht. De voortgang, fout en vorige tekst staan in het dossier; na herladen wordt de taak hervat. De lokale Mac-testomgeving kan met `bash scripts/start-productstudio-local.sh` worden gestart (server + aparte worker via launchctl). De server bindt uitsluitend aan 127.0.0.1. Deze lokale services overleven het sluiten van een terminal, niet noodzakelijk het uitloggen van macOS.

Etiketgegevens en handmatige correcties worden niet door schattingen overschreven. De studio houdt bij voedingswaarden een bron per veld bij. Aanvullen werkt ook zonder etiket; onbekende complexe recepturen blijven onbekend. Een modelantwoord dat tijdens paginageneratie ten onrechte “etiket” als bron noemt wordt niet als etiketbewijs opgeslagen. Een gekoeld leveranciersetiket wordt apart gecontroleerd tegen de diepgevroren levering. Handmatige bevestiging voor publicatie vereist een echte specificatie/receptuur, geen AI-schatting alleen.

De editor biedt blijvende foutmeldingen, browserherstel per gebruiker/concept, een revisiecontrole tegen overschrijven vanuit een oude tab, individueel kopieerbare vragen en antwoorden, tekstbewerking en de laatste tien tekstversies. Optionele foto/handtekening van de vakman blijven privé. De expertstip wordt alleen als goedgekeurde persoonlijke tip geëxporteerd na expliciete goedkeuring.

Gegenereerde productfoto's zijn automatisch als **verliesvrij WEBP** te downloaden, met behoud van de originele pixels en resolutie. De PNG-bron blijft voor bewerking bewaard. De **SEO-gegevens**-knop biedt beschrijvende bestandsnaam, alt-tekst, titel, bijschrift en beschrijving. Een fotoset kan aan een productdossier worden gekoppeld en komt dan mee in de conceptexport als nog te uploaden media. Afbeeldingen en metadata moeten visueel worden gecontroleerd; de naam alleen bewijst geen details zoals gaarheid of snijwijze.

**Export en WordPress:** JSON en veilige HTML zijn beschikbaar. JSON bevat de korte en uitgebreide tekst, FAQ's, SEO, productfeiten, gecontroleerde samenstelling, expertinformatie en gekoppelde media. Schattingen/onbevestigde samenstelling staan apart onder `internal_review_do_not_publish`, niet in publiceerbare velden. De `Product`-structured-data-opzet verzint geen prijzen, voorraad, beoordelingen of openbare URL's. De echte WordPress/WooCommerce-koppeling is nog niet actief: daarvoor zijn de doelomgeving, toegangsgegevens en daadwerkelijke veldmapping nodig. Er wordt niets gepubliceerd of naar WordPress verstuurd.

Na de etiketanalyse worden ontbrekende voedingswaarden automatisch waar verantwoord geschat. Deze extra stap overschrijft geen bestaande etiketwaarde. Als alleen de schatting faalt, blijven de gelezen gegevens bewaard met een blijvende waarschuwing. De knop **Ontbrekende gegevens aanvullen** blijft beschikbaar voor producten zonder etiket en voor opnieuw aanvullen.

Zie `docs/productstudio-audit-2026-09-07.md` voor controlepunten, testbewijs en grenzen van deze lokale release.

## Niet bezorgd Trunkrs

Beheerders kunnen de eigen-mailboxkoppeling inrichten via **Instellingen → Trunkrs**: gegevens opslaan, expliciete leestoegang bevestigen, aanmelden bij Microsoft, rapportmap verifiëren en een eerste rapportcontrole starten. Dit wordt via de bestaande GitHub-uitrol geleverd; geen hostinglogin of extra Microsoft-account nodig. Instellingen en tijdelijke aanmeldgegevens staan versleuteld in de nieuwe additieve tabel `trunkrs_settings`; tokens blijven versleuteld in `trunkrs_connections`. De beperkte Composer-installatiehook en de beheerknop **Trunkrs-opslag voorbereiden** gebruiken uitsluitend de twee vaste additieve Trunkrs-migraties, zonder bestaande gegevens te vervangen. Een handmatige webcontrole bewijst geen automatische serverplanning; hiervoor is een afzonderlijke heartbeat zichtbaar. Zie de [beheerprocedure en beveiligingsgrenzen](docs/trunkrs-mailbox-modes.md).

Het dashboard bevat een afzonderlijke, read-only tegel voor de dagelijkse Trunkrs-rapporten. Alle zendingen uit het rapport blijven samen staan, ook annuleringen. De oorspronkelijke status, bezorgdatum, mailontvangst en importtijd blijven controleerbaar; een ontbrekend rapport is geen nul. Alleen actieve ingelogde gebruikers hebben toegang.

Automatische verwerking gebeurt met `trunkrs:sync` via de **hostingserverplanning**, niet via de browser of een laptop. De koppeling staat standaard uit en ondersteunt een apart Microsoft-leesaccount (`shared`) of, na expliciete toestemming, het bestaande account van de mailboxeigenaar (`own`). Die laatste optie vereist geen extra leesaccount, maar geeft Microsoft-technisch leestoegang tot de hele eigen mailbox; uitsluitend de applicatie beperkt de verwerking tot de rapportmap. De serverkoppeling is niet de chatconnector. Zie [actuele inrichting en toegangsgrenzen](docs/trunkrs-mailbox-modes.md). De bestaande migratie voegt alleen twee Trunkrs-tabellen toe; bestaande projecten, taken en kalendergegevens worden niet gewijzigd.

## Rollen & Rechten

| Rol     | Rechten                                                    |
|---------|------------------------------------------------------------|
| Admin   | Volledige toegang, gebruikersbeheer, kanalen verwijderen   |
| Manager | Projecten aanmaken/verwijderen, kanalen aanmaken           |
| Lid     | Taken, notities en kalenderitems gebruiken                  |

## Data Migratie

Voor migratie vanuit het oudere Node.js/SQLite systeem:

```bash
php artisan import:old-data /pad/naar/oude/database.sqlite
```

Dit importeert gebruikers, projecten, taken, kalenderitems, notities en bijlagen.

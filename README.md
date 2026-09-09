# BBQuality The Pitboard — Marketing Team Tool

Een uitgebreide marketing team samenwerkingstool gebouwd met Laravel 12 en een vanilla JavaScript SPA-frontend. De applicatie is volledig Nederlandstalig en biedt projectmanagement, taakbeheer, kalenderplanning en meer.

## Kernfunctionaliteiten

- **Projectmanagement** — Projecten met status (actief/gepauzeerd/afgerond/gearchiveerd), prioriteit (laag/normaal/hoog/urgent) en deadlines
- **Taakbeheer** — Kanban-stijl taken (todo/bezig/review/klaar) met drag-and-drop herordening
- **Kalender** — Evenementen voor content, deadlines, meetings, social posts, emails en blogs (FullCalendar integratie)
- **Sticky Notes** — Kleurgecodeerde notities gekoppeld aan projecten en taken
- **Bestandsbijlagen** — Uploads tot 10MB gekoppeld aan projecten, taken, kalenderitems en notities
- **Afbeeldingen** — AI-productfotogenerator voor vlees, sauzen/rubs en totaalpakketten, plus batch WebP-conversie
- **Productstudio** — Etiketanalyse, beheerbare productkeuzes, gestructureerde PDP-teksten, voedingswaardeschattingen en voorbereiding op WordPress-concepten
- **Dashboard** — Statistieken over projecten, actieve taken, deadlines en kalenderitems

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

De module **Afbeeldingen** gebruikt lokaal standaard de kostenloze `fake`-driver. Daarmee kan de volledige upload- en resultaatflow worden getest zonder externe verzoeken of API-kosten.

Een beheerder kan de OpenAI API-sleutel veilig instellen via **Instellingen → AI-koppelingen**. De sleutel wordt met de Laravel-applicatiesleutel versleuteld in de database opgeslagen, wordt nooit teruggestuurd naar de browser en komt niet in Git terecht.

Als alternatief kan een serverbeheerder de koppeling via de productie-`.env` instellen:

```dotenv
PRODUCT_IMAGE_DRIVER=openai
OPENAI_API_KEY=<jouw-api-sleutel>
```

De standaardadapter gebruikt `gpt-image-2` via de OpenAI Image Edit API met hoge uitvoerkwaliteit. Er kunnen maximaal vijf productreferentiefoto's worden meegestuurd, waarbij de medewerker één hoofdfoto aanwijst en altijd zelf het exacte productaantal invult. GPT Image 2 verwerkt referentiebeelden automatisch met hoge trouw; de niet-ondersteunde instelling `input_fidelity` wordt daarom bewust niet meegestuurd. De afzonderlijke varianten worden maximaal twee tegelijk gemaakt om de wachttijd te verkorten zonder model, resolutie, PNG-formaat, referenties of hoge kwaliteitsinstelling te wijzigen. Voor bereide beelden, de vaste rauwe BBQuality-achtergrond en de vaste buitenstijl wordt automatisch één apart, intern BBQuality-stijlvoorbeeld toegevoegd. De prompt maakt streng onderscheid tussen productreferenties en die laatste stijl- of achtergrondreferentie. Eén rauwe variant gebruikt altijd de echte BBQuality-achtergrondstijl met een zwart achtervlak en warm hout; bereide beelden mogen deze referentie nooit gebruiken. Bereide vleesbeelden gebruiken gegarandeerd twee verschillende scènefamilies (buiten-BBQ en serveermoment), met productspecifieke regels voor onder andere steak, brisket, MOINK balls, burgers en ribs. Rauw vlees krijgt extra regels voor behoud van silhouet, verhoudingen, vetkap, kleur en natuurlijke structuur. Een medewerker kan daarna uitsluitend de gekozen foto laten aanpassen; eerdere versies blijven herstelbaar. Een perfecte actuele versie kan vanuit de resultaatkaart met productnaam aan de gedeelde stijlbibliotheek worden toegevoegd. Bij een volgende opdracht voor exact hetzelfde product, dezelfde productsoort en dezelfde variantstijl wordt de laatst goedgekeurde foto als kwaliteitsanker meegestuurd; de nieuwe echte productreferenties blijven altijd leidend voor vorm en hoeveelheid. Voor sauzen en rubs is vóór downloaden en opslaan als stijl een handmatige etiketcontrole verplicht, omdat generatieve beeldmodellen exacte tekst niet betrouwbaar kunnen garanderen. Resultaten worden altijd als PNG verwerkt. Model, afmetingen, kwaliteit en timeout zijn configureerbaar via de bijbehorende `OPENAI_IMAGE_*` variabelen in `.env.example`. API-sleutels horen nooit in Git.

Beeldgeneratie draait als achtergrondtaak. Lokaal gebruikt de module standaard de databasequeue `images`, met een aparte beeldworker: de ingebouwde PHP-server kan anders tijdens het genereren geen volgende webaanvraag of statuscontrole afhandelen. De starthelper hieronder start beide workers. Een expliciete `PRODUCT_IMAGE_QUEUE_CONNECTION`-instelling heeft voorrang. De status wordt tijdens het maken in de database bijgewerkt. Kwaliteit en maximaal twee gelijktijdige beeldvarianten zijn behouden.

Voor een grotere productieomgeving kan `PRODUCT_IMAGE_QUEUE_CONNECTION=database` worden ingesteld. Start dan naast de website permanent een queue-worker:

```bash
php artisan queue:work database --queue=images --timeout=600 --tries=1
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

De worker gebruikt een timeout van 540 seconden en één poging; `DB_QUEUE_RETRY_AFTER` moet groter zijn dan de worker-timeout (standaard 660). Eén opdracht mag maximaal twee AI-verzoeken van ieder maximaal 240 seconden uitvoeren. Er wordt niet automatisch opnieuw betaald na een onduidelijke timeout. Herhaalde klikken hervatten dezelfde actieve opdracht. De voortgang, fout en vorige tekst staan in het dossier; na herladen wordt de taak hervat. De lokale Mac-testomgeving kan met `bash scripts/start-productstudio-local.sh` worden gestart (server + aparte worker via launchctl). De server bindt uitsluitend aan 127.0.0.1. Deze lokale services overleven het sluiten van een terminal, niet noodzakelijk het uitloggen van macOS.

Etiketgegevens en handmatige correcties worden niet door schattingen overschreven. De studio houdt bij voedingswaarden een bron per veld bij. Aanvullen werkt ook zonder etiket; onbekende complexe recepturen blijven onbekend. Een modelantwoord dat tijdens paginageneratie ten onrechte “etiket” als bron noemt wordt niet als etiketbewijs opgeslagen. Een gekoeld leveranciersetiket wordt apart gecontroleerd tegen de diepgevroren levering. Handmatige bevestiging voor publicatie vereist een echte specificatie/receptuur, geen AI-schatting alleen.

De editor biedt blijvende foutmeldingen, browserherstel per gebruiker/concept, een revisiecontrole tegen overschrijven vanuit een oude tab, individueel kopieerbare vragen en antwoorden, tekstbewerking en de laatste tien tekstversies. Optionele foto/handtekening van de vakman blijven privé. De expertstip wordt alleen als goedgekeurde persoonlijke tip geëxporteerd na expliciete goedkeuring.

Gegenereerde productfoto's zijn automatisch als **verliesvrij WEBP** te downloaden, met behoud van de originele pixels en resolutie. De PNG-bron blijft voor bewerking bewaard. De **SEO-gegevens**-knop biedt beschrijvende bestandsnaam, alt-tekst, titel, bijschrift en beschrijving. Een fotoset kan aan een productdossier worden gekoppeld en komt dan mee in de conceptexport als nog te uploaden media. Afbeeldingen en metadata moeten visueel worden gecontroleerd; de naam alleen bewijst geen details zoals gaarheid of snijwijze.

**Export en WordPress:** JSON en veilige HTML zijn beschikbaar. JSON bevat de korte en uitgebreide tekst, FAQ's, SEO, productfeiten, gecontroleerde samenstelling, expertinformatie en gekoppelde media. Schattingen/onbevestigde samenstelling staan apart onder `internal_review_do_not_publish`, niet in publiceerbare velden. De `Product`-structured-data-opzet verzint geen prijzen, voorraad, beoordelingen of openbare URL's. De echte WordPress/WooCommerce-koppeling is nog niet actief: daarvoor zijn de doelomgeving, toegangsgegevens en daadwerkelijke veldmapping nodig. Er wordt niets gepubliceerd of naar WordPress verstuurd.

Na de etiketanalyse worden ontbrekende voedingswaarden automatisch waar verantwoord geschat. Deze extra stap overschrijft geen bestaande etiketwaarde. Als alleen de schatting faalt, blijven de gelezen gegevens bewaard met een blijvende waarschuwing. De knop **Ontbrekende gegevens aanvullen** blijft beschikbaar voor producten zonder etiket en voor opnieuw aanvullen.

Zie `docs/productstudio-audit-2026-09-07.md` voor controlepunten, testbewijs en grenzen van deze lokale release.

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

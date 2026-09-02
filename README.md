# BBQuality The Pitboard — Marketing Team Tool

Een uitgebreide marketing team samenwerkingstool gebouwd met Laravel 12 en een vanilla JavaScript SPA-frontend. De applicatie is volledig Nederlandstalig en biedt projectmanagement, taakbeheer, kalenderplanning en meer.

## Kernfunctionaliteiten

- **Projectmanagement** — Projecten met status (actief/gepauzeerd/afgerond/gearchiveerd), prioriteit (laag/normaal/hoog/urgent) en deadlines
- **Taakbeheer** — Kanban-stijl taken (todo/bezig/review/klaar) met drag-and-drop herordening
- **Kalender** — Evenementen voor content, deadlines, meetings, social posts, emails en blogs (FullCalendar integratie)
- **Sticky Notes** — Kleurgecodeerde notities gekoppeld aan projecten en taken
- **Bestandsbijlagen** — Uploads tot 10MB gekoppeld aan projecten, taken, kalenderitems en notities
- **Afbeeldingen** — AI-productfotogenerator met 2 bereide en 2 rauwe varianten, plus batch WebP-conversie
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
# Start de development server (via Laravel Herd of Artisan)
php artisan serve

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

De standaardadapter gebruikt `gpt-image-2` via de OpenAI Image Edit API met hoge uitvoerkwaliteit. GPT Image 2 verwerkt referentiebeelden automatisch met hoge trouw; de niet-ondersteunde instelling `input_fidelity` wordt daarom bewust niet meegestuurd. De referentiefoto wordt lokaal genormaliseerd naar een ondoorzichtige vierkante PNG; GPT Image kan de volledige invoerafbeelding via de prompt aanpassen en heeft daarvoor geen transparante uitsnede of DALL·E-masker nodig. Resultaten worden altijd als PNG verwerkt. Model, afmetingen, kwaliteit en timeout zijn configureerbaar via de bijbehorende `OPENAI_IMAGE_*` variabelen in `.env.example`. API-sleutels horen nooit in Git.

Beeldgeneratie draait als achtergrondtaak, zodat een normale webaanvraag niet minutenlang open hoeft te blijven. Standaard gebruikt deze module Laravel's `deferred`-verbinding. Daarmee start de taak direct nadat het webantwoord is verstuurd en is op een normale server geen apart proces of permanente queue-worker nodig. De status wordt tijdens het maken per stap in de database bijgewerkt.

Voor een grotere productieomgeving kan `PRODUCT_IMAGE_QUEUE_CONNECTION=database` worden ingesteld. Start dan naast de website permanent een queue-worker:

```bash
php artisan queue:work --queue=images --timeout=600 --tries=2
```

Gegenereerde afbeeldingen worden afgeschermd in de gedeelde database opgeslagen. Daardoor blijven ze ook bereikbaar wanneer de achtergrondtaak en de website op verschillende serverprocessen draaien. Alleen de ingelogde medewerker die de opdracht heeft gestart kan ze bekijken of downloaden.

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

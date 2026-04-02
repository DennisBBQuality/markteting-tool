# BBQuality The Pitboard — Marketing Team Tool

Een uitgebreide marketing team samenwerkingstool gebouwd met Laravel 12 en een vanilla JavaScript SPA-frontend. De applicatie is volledig Nederlandstalig en biedt projectmanagement, taakbeheer, kalenderplanning, team chat en meer.

## Kernfunctionaliteiten

- **Projectmanagement** — Projecten met status (actief/gepauzeerd/afgerond/gearchiveerd), prioriteit (laag/normaal/hoog/urgent) en deadlines
- **Taakbeheer** — Kanban-stijl taken (todo/bezig/review/klaar) met drag-and-drop herordening
- **Kalender** — Evenementen voor content, deadlines, meetings, social posts, emails en blogs (FullCalendar integratie)
- **Sticky Notes** — Kleurgecodeerde notities gekoppeld aan projecten en taken
- **Team Chat** — Kanalen per project + directe berichten, met @mention-notificaties en polling
- **Bestandsbijlagen** — Uploads tot 10MB gekoppeld aan projecten, taken, kalenderitems en notities
- **WebP-conversie** — Batch image conversie tool met kwaliteitsinstelling
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
- **Chat Channels** — Algemeen of per project
- **Chat Threads** — Directe berichten tussen twee gebruikers
- **Chat Messages** — Berichten in kanalen of threads
- **Notifications** — @mentions en directe berichten notificaties

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
| `GET/POST /api/chat/channels` | Chat kanalen                          |
| `GET /api/chat/threads`   | Directe berichten threads                 |
| `GET /api/chat/poll`      | Real-time message polling                 |
| `GET /api/notifications`  | Notificaties ophalen                      |
| `POST /api/convert/webp`  | Batch WebP conversie                      |
| `GET /api/dashboard/stats`| Dashboard statistieken                    |

## Rollen & Rechten

| Rol     | Rechten                                                    |
|---------|------------------------------------------------------------|
| Admin   | Volledige toegang, gebruikersbeheer, kanalen verwijderen   |
| Manager | Projecten aanmaken/verwijderen, kanalen aanmaken           |
| Lid     | Taken, notities, kalenderitems en chat gebruiken            |

## Data Migratie

Voor migratie vanuit het oudere Node.js/SQLite systeem:

```bash
php artisan import:old-data /pad/naar/oude/database.sqlite
```

Dit importeert alle gebruikers, projecten, taken, kalenderitems, notities, bijlagen, chat kanalen, threads, berichten en notificaties.

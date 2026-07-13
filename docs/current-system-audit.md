# Current System Audit — BBQuality The Pitboard

Auditdatum: 13 juli 2026  
Scope: statische analyse van de repository; er zijn geen dependencies geïnstalleerd, migrations uitgevoerd, databases gewijzigd of functionele bestanden aangepast.

## Samenvatting

The Pitboard is een compacte Laravel 12-applicatie met een custom sessiegebaseerde JSON-API en een klassieke Vanilla JavaScript-SPA. De bestaande project-, taak-, chat-, notificatie-, zoek/filter- en bijlagenfuncties bieden bruikbare patronen voor een klantenservicemodule. De huidige architectuur mist echter fijnmazige autorisatie, domeinservices, auditlogging, transacties en bescherming tegen gelijktijdige wijzigingen. Die onderdelen zijn essentieel voordat klantgegevens en externe communicatie worden toegevoegd.

De aanbevolen aanpak is een begrensde klantenservicemodule naast de bestaande functionaliteit: eigen modellen en migrations, controllers onder een aparte API-namespace, services voor workflow en kanaalintegraties, en een losse frontendmodule die aansluit op de bestaande navigatie en vormgeving. Begin uitsluitend met handmatige testtickets en voeg e-mail, WhatsApp, orderdata en AI pas gefaseerd toe.

## 1. Programmeertalen, frameworks en versies

### In de repository vastgelegd

| Onderdeel | Technologie | Versie/constraint |
|---|---|---|
| Backendtaal | PHP | `^8.2` |
| Backendframework | Laravel | `^12.0`; gelockt op `v12.53.0` |
| ORM/database-laag | Eloquent en Laravel Query Builder | Onderdeel van Laravel 12 |
| Afbeeldingsverwerking | Intervention Image Laravel | `^1.5`; gelockt op `1.5.6` |
| Frontendtaal | JavaScript, HTML en CSS | Vanilla JavaScript, geen frontendframework |
| CSS-tooling | Tailwind CSS | `^4.0.0`; lokaal geïnstalleerd `4.3.2` |
| Bundler | Vite | `^7.0.7`; lokaal geïnstalleerd `7.3.6` |
| HTTP-client in Vite-entry | Axios | `^1.11.0`; lokaal geïnstalleerd `1.18.1` |
| Kalender | FullCalendar via CDN | `6.1.9` |
| Drag-and-drop | SortableJS via CDN | `1.15.0` |
| Iconen | Font Awesome via CDN | `6.5.0` |
| Testframework | PHPUnit | `^11.5.3`; gelockt op `11.5.55` |
| PHP-formatter | Laravel Pint | `^1.24`; gelockt op `1.27.1` |
| Logviewer voor development | Laravel Pail | `^1.2.2`; gelockt op `1.2.6` |

### Lokale runtime tijdens de audit

- PHP `8.4.23`.
- Composer `2.10.1`.
- Node.js `v24.13.0`.
- npm `11.6.2`.

Deze lokale versies zijn geen gedocumenteerde productievereisten. Er is geen `package-lock.json` aanwezig, waardoor npm-installaties niet volledig reproduceerbaar zijn.

## 2. Globale mappenstructuur

```text
app/
├── Console/Commands/       # Importcommando voor de oude SQLite-app
├── Http/Controllers/Api/   # JSON-controllers per bestaande functie
├── Http/Middleware/        # Custom auth- en rolmiddleware
├── Models/                 # Tien Eloquent-modellen
└── Providers/              # Lege AppServiceProvider
bootstrap/
├── app.php                 # Routing, middleware-aliassen, CSRF-uitzondering
└── providers.php
config/                     # Laravel-configuratie voor app, DB, sessies, logging enz.
database/
├── factories/              # Eén standaard UserFactory
├── migrations/             # Negen migrations
└── seeders/                # Defaultgebruiker en algemeen chatkanaal
public/
├── css/style.css           # Feitelijk gebruikte, handgeschreven applicatiestijl
├── img/                    # Logo
├── js/                     # Feitelijk gebruikte SPA-modules
├── index.html              # SPA-shell en navigatie
└── index.php               # Laravel web-entrypoint
resources/
├── css/app.css             # Tailwind/Vite-entry
├── js/                     # Minimale Vite/Axios-entry
└── views/                  # Standaard Laravel-welcomeview, niet de actieve SPA
routes/
├── web.php                 # Alle API-routes, uploadroute en SPA-fallback
└── console.php
storage/                    # Logs, frameworkdata, uploads en conversieresultaten
tests/
├── Feature/                # Eén voorbeeldtest
├── Unit/                   # Eén voorbeeldtest
└── TestCase.php
```

Er zijn geen mappen voor services, policies, jobs, events, listeners, mailables of applicatiespecifieke notifications. De meeste domeinlogica staat rechtstreeks in controllers.

## 3. Authenticatie en gebruikersrollen

### Authenticatie

- Authenticatie is custom en sessiegebaseerd; Laravel's standaard `Auth`-guard wordt niet gebruikt voor de requestcontrole.
- `POST /api/auth/login` zoekt een actieve gebruiker op e-mailadres en controleert `wachtwoord_hash` met `password_verify`.
- Bij een geslaagde login worden `userId` en `rol` in de sessie gezet.
- `RequireAuth` controleert alleen of `userId` in de sessie aanwezig is.
- `RequireAdmin` en `RequireManagerOrAdmin` vertrouwen op de rolwaarde die bij login in de sessie is opgeslagen.
- Sessies gebruiken standaard de database-driver, een levensduur van 120 minuten, een HttpOnly-cookie en `SameSite=Lax`. Encryptie staat in `.env.example` uit. `secure` is omgevingsafhankelijk en heeft geen expliciete standaardwaarde.
- De `sessions`-tabel wordt door de eerste migration aangemaakt.
- Inloggen regenereert het sessie-ID niet; uitloggen gebruikt `flush()` maar geen expliciete `invalidate()` en CSRF-tokenregeneratie.

### Rollen en feitelijke rechten

| Rol | Feitelijke server-side rechten |
|---|---|
| `admin` | Alle geauthenticeerde functies, gebruikers aanmaken/wijzigen/deactiveren en algemene chatkanalen verwijderen. |
| `manager` | Alle algemene geauthenticeerde functies, projecten verwijderen en algemene chatkanalen aanmaken. |
| `lid` | Alle algemene geauthenticeerde functies, waaronder projecten aanmaken/wijzigen, taken, kalenderitems, notities en bijlagen beheren, converter gebruiken en chatten. |

De UI verbergt de instellingenpagina voor niet-admins. Dit is alleen presentatie; de relevante gebruikersmutaties zijn daarnaast correct met adminmiddleware beschermd. De rollenbeschrijving in de README is niet volledig gelijk aan de feitelijke routes: projectaanmaak en projectwijziging zijn momenteel voor iedere ingelogde gebruiker toegestaan.

Er zijn geen policies, permissions per record of aparte klantenservicerollen. Daardoor bestaat nog geen onderscheid tussen bijvoorbeeld agent, teamlead, alleen-lezen en beheerder.

## 4. Database, modellen en migrations

### Databaseconfiguratie

- Standaardverbinding: SQLite.
- MySQL, MariaDB, PostgreSQL en SQL Server staan in de standaard Laravel-configuratie, maar de projectdocumentatie noemt alleen SQLite en MySQL als beoogd.
- UUID's zijn de primaire sleutels van alle applicatiemodellen en beide pivottabellen.
- Eloquent wordt gecombineerd met Query Builder en handgeschreven SQL.
- Er is geen repository- of servicelaag.

### Bestaande modellen en tabellen

| Model/tabel | Doel en belangrijke relaties |
|---|---|
| `User` / `users` | Gebruikers, rollen, kleur, avatar en actiefstatus. Maakt projecten en notities aan. |
| `Project` / `projects` | Projectstatus, prioriteit en deadline; heeft taken, kalenderitems, notities, één projectkanaal en meerdere medewerkers. |
| `Task` / `tasks` | Kanbantaak met status, prioriteit, deadline en positie; behoort tot een project en heeft meerdere toegewezen gebruikers. |
| `CalendarItem` / `calendar_items` | Kalenderitem met type, begin/einddatum en optionele projectkoppeling. |
| `Note` / `notes` | Notitie gekoppeld aan een project en/of taak. |
| `Attachment` / `attachments` | Metadata voor uploads gekoppeld via losse nullable ID-kolommen aan project, taak, kalenderitem of notitie. |
| `ChatChannel` / `chat_channels` | Algemeen of projectgebonden kanaal. |
| `ChatThread` / `chat_threads` | Direct gesprek tussen twee gebruikers. |
| `ChatMessage` / `chat_messages` | Bericht in een kanaal of directe thread. |
| `Notification` / `notifications` | Ongelezen/gelezen mention of direct-message-melding. |

Aanvullende tabellen zijn `sessions`, `taak_gebruiker` en `project_gebruiker`.

### Migrations

Er zijn negen migrations:

1. gebruikers en sessies;
2. projecten;
3. taken;
4. kalenderitems;
5. notities;
6. bijlagen;
7. chattabellen en meldingen;
8. many-to-many toewijzing van taken;
9. many-to-many toewijzing van projecten.

Relevante observaties:

- De latere taakpivot-migration bouwt de `tasks`-tabel handmatig opnieuw op met SQLite-`PRAGMA`-instructies. Dat is niet portable naar MySQL en botst met de geclaimde MySQL-ondersteuning.
- `User::tasks()` verwijst nog naar de verwijderde kolom `toegewezen_aan`; deze relatie is verouderd.
- `ImportOldData` schrijft eveneens nog naar `tasks.toegewezen_aan` en is daardoor niet compatibel met de huidige tabelstructuur.
- De standaard `UserFactory` gebruikt Laravel-standaardvelden (`name`, `password`, `email_verified_at`) die niet bestaan in dit userschema. De factory is daardoor niet direct bruikbaar.
- De vier entiteitskolommen op `attachments` hebben geen foreign-key constraints; alleen `geupload_door` heeft een constraint.
- De databaseconfiguratie kiest standaard database-backed cache en queues, maar migrations voor `cache`, `jobs`, `job_batches` en `failed_jobs` ontbreken.
- De seeder bevat een vaste, voorspelbare standaardbeheerder. Die hoort niet buiten een strikt lokale ontwikkelcontext gebruikt te worden.
- Er is geen model of tabel voor klanten, tickets, ticketberichten, interne ticketnotities, activiteiten, kanaalaccounts, externe bericht-ID's, conceptantwoorden of orderkoppelingen.

## 5. Bestaande API-routes en controllers

Alle API-routes staan in `routes/web.php`. Alleen login en logout staan buiten `auth.custom`; de overige API-routes vallen onder sessie-authenticatie.

| Gebied/controller | Methode en route | Toegang/doel |
|---|---|---|
| Auth | `POST /api/auth/login` | Publiek; login. |
| Auth | `POST /api/auth/logout` | Publiek gedeclareerd; sessie leegmaken. |
| Auth | `GET /api/auth/me` | Ingelogde gebruiker ophalen. |
| Users | `GET /api/users` | Alle ingelogde gebruikers. |
| Users | `POST /api/users` | Alleen admin. |
| Users | `PUT /api/users/{id}` | Alleen admin. |
| Users | `DELETE /api/users/{id}` | Alleen admin; feitelijk deactiveren. |
| Projects | `GET /api/projects` | Lijst met voortgang en medewerkers. |
| Projects | `POST /api/projects` | Project plus projectchatkanaal aanmaken. |
| Projects | `PUT /api/projects/{id}` | Project en medewerkers wijzigen. |
| Projects | `DELETE /api/projects/{id}` | Manager/admin. |
| Tasks | `GET /api/tasks` | Filteren op project, status, prioriteit, medewerker, deadline en zoektekst. |
| Tasks | `POST /api/tasks` | Taak aanmaken. |
| Tasks | `PUT /api/tasks/reorder/batch` | Kanbanstatus en positie batchgewijs wijzigen. |
| Tasks | `PUT /api/tasks/{id}` | Taak wijzigen. |
| Tasks | `DELETE /api/tasks/{id}` | Taak verwijderen. |
| Calendar | `GET /api/calendar` | Filteren op periode, type en project. |
| Calendar | `POST /api/calendar` | Kalenderitem aanmaken. |
| Calendar | `PUT /api/calendar/{id}` | Kalenderitem wijzigen. |
| Calendar | `DELETE /api/calendar/{id}` | Kalenderitem verwijderen. |
| Notes | `GET /api/notes` | Filteren op project, taak en zoektekst. |
| Notes | `POST /api/notes` | Notitie aanmaken. |
| Notes | `PUT /api/notes/{id}` | Notitie wijzigen. |
| Notes | `DELETE /api/notes/{id}` | Notitie verwijderen. |
| Attachments | `GET /api/attachments` | Filteren op gekoppelde entiteit. |
| Attachments | `POST /api/attachments` | Bestand tot 10 MB uploaden. |
| Attachments | `DELETE /api/attachments/{id}` | Bestand en metadata verwijderen. |
| Converter | `POST /api/convert/webp` | Afbeeldingen naar WebP converteren. |
| Converter | `GET /api/convert/download/{filename}` | Conversieresultaat downloaden. |
| Dashboard | `GET /api/dashboard/stats` | Project-, taak- en kalenderstatistieken. |
| Chat | `GET /api/chat/channels` | Kanalenoverzicht. |
| Chat | `GET /api/chat/channels/{id}` | Eén kanaal ophalen. |
| Chat | `POST /api/chat/channels` | Manager/admin; algemeen kanaal aanmaken. |
| Chat | `DELETE /api/chat/channels/{id}` | Alleen admin; algemeen kanaal verwijderen. |
| Chat | `GET /api/chat/channels/{id}/messages` | Kanaalberichten pagineren. |
| Chat | `POST /api/chat/channels/{id}/messages` | Kanaalbericht en mentionmeldingen aanmaken. |
| Chat | `DELETE /api/chat/messages/{id}` | Eigen bericht of als admin verwijderen. |
| Chat | `GET /api/chat/direct` | Eigen directe threads ophalen. |
| Chat | `GET /api/chat/direct/{userId}` | Directe thread zoeken of aanmaken. |
| Chat | `GET /api/chat/threads/{threadId}/messages` | Berichten uit een eigen thread. |
| Chat | `POST /api/chat/threads/{threadId}/messages` | Direct bericht plus melding aanmaken. |
| Chat | `GET /api/chat/poll` | Nieuwe kanaal-/directe berichten en unread count pollen. |
| Notifications | `GET /api/notifications` | Laatste 50 eigen meldingen. |
| Notifications | `GET /api/notifications/unread-count` | Eigen ongelezen aantal. |
| Notifications | `PUT /api/notifications/{id}/read` | Eén eigen melding gelezen maken. |
| Notifications | `PUT /api/notifications/read-all` | Alle eigen meldingen gelezen maken. |
| Notifications | `PUT /api/notifications/read-channel/{channelId}` | Meldingen voor kanaal gelezen maken. |
| Notifications | `PUT /api/notifications/read-thread/{threadId}` | Meldingen voor thread gelezen maken. |

Daarnaast bestaan:

- `GET /uploads/{filename}` binnen `auth.custom`, voor het rechtstreeks serveren van uploads;
- een SPA-fallback `GET /{any?}` die `public/index.html` teruggeeft;
- de Laravel healthroute `/up`, geconfigureerd in `bootstrap/app.php`.

Controllers retourneren rechtstreeks JSON en bevatten query-, validatie- en workflowlogica. Er zijn geen API Resources, Form Requests, services of gedeelde response-objecten.

## 6. Frontend-architectuur en navigatie

### Feitelijk actieve frontend

- `public/index.html` is de statische SPA-shell met login, vaste sidebar, lege viewcontainers, één globale modal en een toastcontainer.
- `public/js/app.js` beheert globale state, sessiecontrole, fetch-wrapper, navigatie, modal/toasts, bijlagen, gebruikersselectie en emoji's.
- Iedere hoofdsectie heeft een globaal geladen script: `dashboard.js`, `projects.js`, `tasks.js`, `calendar.js`, `notes.js`, `chat.js`, `converter.js` en `settings.js`.
- Navigatie is client-side via `data-view` en `navigateTo(view)`; er is geen router, URL-state of code splitting.
- Data wordt met de browser-`fetch` API opgehaald. HTML wordt grotendeels met template strings en `innerHTML` opgebouwd.
- Chat gebruikt polling; de globale notification count wordt elke 30 seconden bijgewerkt wanneer chat niet actief is.
- `public/css/style.css` bevat circa 1.667 regels handgeschreven CSS, design tokens, desktop/mobile layout en componentstijlen.

### Navigatie

De sidebar bevat:

1. Dashboard;
2. Projecten;
3. Taken;
4. Kalender;
5. Notities;
6. Berichten;
7. Converter;
8. Instellingen, alleen zichtbaar voor admins.

### Tweede, grotendeels losstaande frontendopzet

Vite verwerkt `resources/css/app.css` en `resources/js/app.js`. Die bestanden importeren Tailwind en Axios, maar `public/index.html` laadt de resulterende Vite-assets of een Blade/Vite-entry niet. De actieve applicatie gebruikt rechtstreeks `/css/style.css` en `/js/*.js`. Hierdoor bestaan twee frontendpaden en is `npm run build` op dit moment niet duidelijk gekoppeld aan de actieve UI.

## 7. Herbruikbare functionaliteit voor klantenservice

| Bestaand onderdeel | Herbruikbare waarde | Benodigde aanpassing |
|---|---|---|
| Gebruikers en rollen | Agenttoewijzing en zichtbare avatars/kleuren. | Klantenservicerollen en server-side recordrechten definiëren. |
| Project-/taaktoewijzing | Many-to-many medewerkerselectie en badges. | Ticket assignment met één primaire behandelaar en eventueel watchers/teams. |
| Taakstatussen en filters | Patronen voor status, prioriteit, zoeken, deadlines en kanban. | Ticketstatusmachine, SLA-data en server-side paginatie. |
| Chatberichten | Berichttijdlijn, afzenderweergave, paginering en polling. | Apart ticketberichtmodel; klantberichten mogen niet als interne chat worden gemodelleerd. |
| Notities | Tekstinvoer en zoekpatroon. | Aparte interne ticketnotities met zichtbaarheid en audittrail. |
| Meldingen | Unread count, polling en per gebruiker gelezen-status. | Nieuwe meldingstypen en verwijzing naar tickets/assignments/SLA. |
| Bijlagen | Upload-UI, metadata, opslag en downloaden. | Striktere validatie, malwarecontrole, private opslag en ticket-/berichtkoppeling. |
| Dashboard | Statcards en compacte werklijsten. | Inboxstatistieken, achterstanden, SLA en workload. |
| Modals/toasts/design tokens | Consistente interactie en vormgeving. | Voor een driedelige inbox is waarschijnlijk een volwaardige detailview nodig naast modals. |
| API-helper | Centrale JSON-fetch en 401-afhandeling. | Robuustere fouttypen, abort/retry en conflictstatussen zoals HTTP 409/412. |
| UUID's | Veilige interne identifiers en toekomstige externe integraties. | Daarnaast leesbare ticketnummers en unieke externe kanaal-ID's toevoegen. |

Hergebruik moet vooral op UX- en infrastructuurpatronen plaatsvinden. ChatMessage, Note en Attachment zonder verdere scheiding rechtstreeks als klantcommunicatie hergebruiken zou interne en externe gegevensstromen te sterk vermengen.

## 8. Logging en foutafhandeling

### Logging

- Laravel gebruikt standaard een Monolog `stack`, volgens `.env.example` met `single` als onderliggend kanaal en niveau `debug`.
- `single` schrijft naar `storage/logs/laravel.log`; een `daily`-kanaal met 14 dagen retentie is beschikbaar maar niet standaard geselecteerd.
- `composer dev` start Laravel Pail voor live logweergave.
- Er zijn geen expliciete `Log::...`, `logger()`, metrics, tracing of auditlog-aanroepen in de applicatiecode.
- Er is geen activiteitenlog voor functionele acties zoals toewijzen, status wijzigen, lezen, verwijderen of berichten versturen.

### Foutafhandeling

- `bootstrap/app.php` bevat geen aangepaste exception rendering of reporting.
- Een deel van de controllers gebruikt `$request->validate()` en `findOrFail()`, waardoor Laravel standaardresponses levert.
- Andere controllers voeren handmatige controles uit en retourneren `{ "error": "..." }` met 400, 401, 403 of 404.
- Niet alle update- en batchendpoints valideren invoer volledig.
- De converter vangt exceptions per bestand af, maar neemt de exceptiontekst op in de API-response; dit kan interne details lekken.
- De frontend-API-helper gaat uit van JSON, toont fouten als toast en stuurt bij 401 naar het loginscherm. Er is geen centrale afhandeling voor netwerkfouten, time-outs, 409-conflicten of niet-JSON-responses.
- Meerstapsbewerkingen gebruiken geen database-transacties. Voorbeelden zijn project plus chatkanaal, taak plus toegewezen gebruikers, en bericht plus meldingen. Een gedeeltelijke fout kan daardoor inconsistente data achterlaten.

Voor klantenservice is een append-only activiteitenlog nodig met actor, actie, ticket, relevante oude/nieuwe waarden, timestamp en correlatie-ID. Geheimen, volledige berichtinhoud en persoonsgegevens horen niet onbeperkt in technische logs.

## 9. Teststructuur en beschikbare testcommando's

### Huidige tests

- `tests/Unit/ExampleTest.php` bevat alleen `assertTrue(true)`.
- `tests/Feature/ExampleTest.php` controleert alleen dat `GET /` status 200 retourneert.
- Er zijn geen tests voor authenticatie, rollen, CRUD, validatie, uploads, chat, notificaties, migrations of concurrency.
- De featuretest gebruikt geen `RefreshDatabase`.

`phpunit.xml` configureert:

- aparte Unit- en Feature-suites;
- SQLite in-memory voor tests;
- array-drivers voor sessie, cache en mail;
- een synchrone queue;
- lagere bcryptkosten.

### Beschikbare commando's

```bash
composer test
php artisan test
vendor/bin/phpunit
```

`composer test` wist eerst de configuratiecache en start daarna `php artisan test`. Pint is geïnstalleerd, maar er bestaat geen Composer-script voor linting; handmatig is bijvoorbeeld `vendor/bin/pint --test` beschikbaar. Frontend-unit- of end-to-endtests en een `npm test`-script ontbreken.

Tijdens deze audit zijn geen tests uitgevoerd, omdat de opdracht uitsluitend het auditdocument als wijziging toestaat en database- of cachemutaties expliciet uitsluit.

## 10. Lokale ontwikkelcommando's

### Installatie volgens README en Composer

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan storage:link
npm run build
```

Voor SQLite moet vooraf `database/database.sqlite` bestaan. Op Windows zijn de README-commando's `cp` en `touch` niet rechtstreeks PowerShell-native.

### Ontwikkeling

```bash
php artisan serve
npm run dev
composer dev
```

`composer dev` start gelijktijdig de Laravel-server, queue listener, Pail en Vite. Omdat migrations voor de standaard databasequeue ontbreken en de actieve SPA niet via Vite wordt geladen, moet deze samengestelde workflow worden gevalideerd voordat hij als voorkeursworkflow geldt.

### Overige aanwezige commando's

```bash
php artisan import:old-data /pad/naar/oude/database.sqlite
npm run build
vendor/bin/pint
```

Het importcommando is momenteel niet veilig inzetbaar op het actuele taskschema, omdat het naar een verwijderde kolom schrijft.

## 11. Deployment-aanpak voor zover afleidbaar

De repository bevat geen Dockerfile, Compose-configuratie, Procfile, webserverconfiguratie, CI-workflow of platformspecifiek deploymentmanifest. Er is ook geen gedocumenteerde productieomgeving.

Alleen de volgende conventionele Laravel-aanpak is afleidbaar:

- webroot instellen op `public/` en requests via `public/index.php` afhandelen;
- PHP/Composer-dependencies installeren;
- een productie-`.env` buiten Git beheren;
- applicatiesleutel, database, sessies, cache, queue, mail en storage configureren;
- migrations gecontroleerd uitvoeren;
- eventueel Vite-assets bouwen;
- schrijfbare `storage/`- en `bootstrap/cache/`-mappen verzorgen;
- zo nodig een queue worker als apart proces draaien;
- HTTPS en secure session cookies afdwingen.

Onzekerheden:

- `npm run build` bouwt assets die niet door de huidige SPA worden geladen.
- Uploads en conversies staan lokaal op disk; bij meerdere instances is gedeelde/object storage nodig.
- SQLite is niet geschikt als vanzelfsprekende keuze voor gelijktijdige ticketverwerking op meerdere appinstances.
- Er is geen release-, rollback-, backup-, monitoring- of secretsmanagementproces zichtbaar.
- Er is geen CI die tests, Pint, dependency-audits of frontendbuild afdwingt.

## 12. Beste plek voor een klantenservicemodule

Voeg de module toe als een begrensd onderdeel naast de bestaande modules, zonder bestaande project-, chat- of taakfunctionaliteit te herstructureren.

### Backend

Aanbevolen locaties:

```text
app/Http/Controllers/Api/CustomerService/
app/Models/                         # Ticket, Customer, TicketMessage enz. volgens huidig modelpatroon
app/Services/CustomerService/       # Workflow, locking, outbound en kanaaladapters
app/Policies/                       # Fijnmazige toegang tot klant- en ticketdata
database/migrations/                # Alleen nieuwe, voorwaartse migrations
```

Een logische eerste set tabellen/modellen:

- `customers`;
- `tickets`;
- `ticket_assignments` of één primaire `assigned_to` plus een watcher-pivot;
- `ticket_messages`, met richting `inbound`/`outbound` en kanaal;
- `ticket_internal_notes`;
- `ticket_activities` als append-only auditlog;
- `ticket_attachments` of een bewust ontworpen polymorfe mediarelatie;
- later `channel_accounts`, externe message/thread identifiers en `order_links`.

Routes kunnen onder één groep komen:

```text
/api/customer-service/tickets
/api/customer-service/customers
/api/customer-service/tickets/{ticket}/messages
/api/customer-service/tickets/{ticket}/notes
/api/customer-service/tickets/{ticket}/activities
```

De controllers moeten dun blijven. Statusovergangen, claimen, optimistic locking, idempotency en outbound delivery horen in services en transacties. Dit is een gerichte uitbreiding die nodig is voor concurrency en externe integraties, geen algemene herstructurering van de bestaande app.

### Frontend

Binnen de bestaande architectuur is de kleinste passende uitbreiding:

- een sidebaritem `Klantenservice` in `public/index.html`;
- een `view-customer-service`-container;
- een nieuw `public/js/customer-service.js`;
- aanvullende componentstijlen in `public/css/style.css`;
- een nieuwe case in `navigateTo()`.

Voor de inbox past een driedelige layout het beste: filter-/wachtrijpaneel, ticketlijst en ticketdetail met tijdlijn/composer. De bestaande chatlayout is visueel herbruikbaar, maar de data en regels moeten gescheiden blijven.

Voordat veel nieuwe frontendcode wordt toegevoegd, moet worden besloten of het actieve `public/`-pad wordt gehandhaafd of dat de app bewust naar Vite-modules verhuist. Doe geen impliciete migratie als onderdeel van de eerste klantenservicefase.

## 13. Technische en beveiligingsrisico's

### Hoog

1. **Sessiegebaseerde API zonder CSRF-validatie.** `api/*` is volledig uitgezonderd terwijl authenticatie op een cookie/sessie rust. `SameSite=Lax` beperkt maar elimineert het risico niet.
2. **Onvoldoende autorisatie.** Er zijn geen policies of record-level checks voor projecten, taken, notities, kalenderitems en bijlagen. Iedere ingelogde gebruiker kan veel records lezen, wijzigen of verwijderen.
3. **Onveilige basis voor persoonsgegevens.** Er zijn geen gegevensclassificatie, retentie, verwijder-/anonimiseerflow, toegangslog of audittrail.
4. **Uploadrisico's.** Alleen bestand en grootte worden gevalideerd. Extensie en MIME komen van de client; er is geen allowlist, malwarecontrole, inhoudsinspectie of quarantaine. Bestanden worden via een response-route inline beschikbaar gemaakt.
5. **Geen concurrencybescherming.** Er zijn geen versienummers, row locks, claim leases, idempotency keys of conflictresponses. Dit voldoet niet aan de eis om dubbele antwoorden te voorkomen.
6. **Geen transacties voor meerstapsacties.** Ticketachtige workflows zouden bij de huidige aanpak gedeeltelijk kunnen slagen.
7. **Voorspelbare standaardbeheerder in de seeder.** Dit is alleen acceptabel voor strikt lokale testdata en mag niet als productiestandaard bestaan.

### Middel

8. **Sessiehardening ontbreekt.** Geen sessieregeneratie bij login en geen expliciete invalidatie/tokenregeneratie bij logout; login heeft geen rate limiting of lockout.
9. **Verouderde codepaden.** `User::tasks()`, de user factory en `ImportOldData` passen niet bij het actuele schema.
10. **Databaseportabiliteit.** Een migration bevat SQLite-specifieke DDL en `PRAGMA`; MySQL-compatibiliteit is niet aangetoond.
11. **Ontbrekende queue/cachetabellen.** Configuratie en `composer dev` veronderstellen database-backed infrastructuur waarvoor migrations ontbreken.
12. **Mogelijke detaillekken.** De converter retourneert exceptionteksten aan de client. Debug staat in `.env.example` aan en moet in productie uit staan.
13. **Frontend-injectierisico.** Veel UI wordt met `innerHTML` opgebouwd. Veel waarden worden geëscapet, maar niet iedere dynamische waarde of toastboodschap doorloopt aantoonbaar dezelfde veilige route.
14. **Geen betrouwbare npm-lock.** Zonder `package-lock.json` kunnen builds verschillende dependencyversies opleveren.
15. **Afhankelijkheid van CDN's.** FullCalendar, SortableJS en Font Awesome worden runtime extern geladen zonder zichtbare Subresource Integrity of Content Security Policy.
16. **Polling en schaalbaarheid.** Chat pollt periodiek en queries zijn niet ontworpen voor grote ticketvolumes, meerdere kanalen of veel gelijktijdige agents.
17. **Inconsistente validatie en responses.** Niet alle velden, enums, foreign IDs, batchgroottes of paginatielimieten worden streng gevalideerd.
18. **Tijdzone.** Laravel staat op UTC terwijl de organisatie in Nederland werkt; opslag, SLA-berekening en presentatie moeten expliciet worden afgesproken.

### Lager, maar relevant vóór groei

19. **Twee frontend-buildpaden.** De actieve `public/`-SPA en Vite/Tailwind kunnen uiteenlopen.
20. **Minimale tests en geen CI.** Regressies in bestaande functies en nieuwe klantcommunicatie worden niet automatisch gedetecteerd.
21. **Datamodelconstraints ontbreken.** Voor chatthreads is bijvoorbeeld geen unieke, genormaliseerde gebruikerscombinatie afgedwongen; attachments missen relaties naar de gekoppelde entiteiten.
22. **Geen operationele observability.** Geen metrics voor foutpercentages, queueachterstand, kanaalwebhooks, SLA of outbound delivery.

## 14. Ontbrekende informatie vóór de bouw kan starten

### Product en workflow

- Definitieve ticketstatussen, toegestane overgangen en betekenis van open/gesloten/heropend.
- Prioriteiten, SLA's, openingstijden, escalaties en wachtrijen/teams.
- Regels voor automatisch versus handmatig toewijzen, overnemen en vrijgeven.
- Exacte definitie van “dubbele antwoorden voorkomen”: harde lock, waarschuwing, presence, conceptclaim of optimistic concurrency.
- Wie interne notities, klantdata, verwijderde tickets en activiteiten mag zien.
- Benodigde zoekvelden, filters, sortering, bulkacties en rapportages.
- Ticketnummerformaat en regels voor samenvoegen, splitsen en duplicaten.

### Klant- en privacybeleid

- Welke persoonsgegevens minimaal nodig zijn en welke juist niet opgeslagen mogen worden.
- Bewaartermijnen, export, correctie, verwijdering/anonimisering en wettelijke grondslag.
- Scheiding tussen test-, acceptatie- en productiegegevens.
- Toegangsreview, geheimhoudingsniveau en auditvereisten.
- Beleid voor bijlagen, malwarecontrole en maximaal formaat/type.

### E-mail en WhatsApp

- Gekozen e-mailprovider/protocol, mailboxen, aliases en threadingregels.
- Gekozen WhatsApp Business-provider, accountstructuur en templatebeleid.
- Webhookbeveiliging, retries, volgorde, deduplicatie en idempotency.
- Outbound approval: wie mag verzenden en wanneer is een concept definitief.
- Verwachte volumes, piekbelasting, rate limits en maximaal acceptabele vertraging.

### Orders en AI

- Bronsysteem voor orders, API-mogelijkheden, identifiers en toegangsrechten.
- Of orderdata gekopieerd of alleen live geraadpleegd mag worden.
- Toegestane AI-provider, dataverwerking, logging, bewaarbeleid en menselijke goedkeuring.
- Welke bronnen AI mag gebruiken en hoe hallucinaties/onjuiste klantclaims worden voorkomen.

### Techniek en operations

- Werkelijke productiehosting, webserver, database, queue, cache en object storage.
- CI/CD-, secrets-, backup-, restore-, rollback- en monitoringproces.
- Browserondersteuning en gewenste realtime-techniek: polling, SSE of WebSockets.
- Keuze voor de actieve frontendstrategie: huidige publieke scripts of Vite-modules.
- Niet-functionele eisen voor beschikbaarheid, performance, herstel en securitytesting.

## 15. Voorstel voor fasering van de klantenservicemodule

### Fase 0 — Besluiten en veiligheidsbasis

- Openstaande product-, rol-, privacy-, SLA- en infrastructuurvragen beantwoorden.
- Authenticatie hardenen, CSRF-strategie bepalen en rate limiting toevoegen.
- Klantenservicepermissies/policies ontwerpen.
- Teststrategie, CI-basis en gescheiden testdata vastleggen.
- Actief frontend-buildpad en doelproductiedatabase kiezen.

**Resultaat:** goedgekeurd datamodel, workflow, threat model en Definition of Done.

### Fase 1 — Handmatige ticketfoundation

- Migrations en modellen voor klanten, tickets, berichten, interne notities, activiteiten en veilige bijlagen.
- API voor inbox, detail, status, prioriteit, zoeken/filteren en toewijzing.
- Klantenserviceview in de bestaande SPA, met alleen lokale testtickets.
- Policies, Form Requests/strikte validatie en featuretests per rol.
- Nog geen echte e-mail, WhatsApp of AI.

**Resultaat:** medewerkers kunnen veilig met handmatige testtickets werken.

### Fase 2 — Samenwerking en dubbele-antwoordenpreventie

- Optimistic locking met versieveld en HTTP 409/412 bij conflicten.
- Ticket claim/lease, actieve-editorindicatie en conceptstatus.
- Transacties voor status, assignment, messages en activities.
- Meldingen, watchers, unread-status en volledige activiteitenhistorie.
- Concurrency-, autorisatie- en hersteltests.

**Resultaat:** meerdere medewerkers kunnen aantoonbaar samenwerken zonder stil gegevensverlies of dubbele antwoorden.

### Fase 3 — E-mailintegratie in sandbox

- Provideradapter, inbound webhook/poller en queue jobs.
- Externe message/thread-ID's, idempotency en veilige HTML/plain-text-normalisatie.
- Uitgaande mail uitsluitend via expliciete menselijke verzendactie.
- Lokale/testomgeving blijft op `log` of `array`; nooit echte verzending vanuit lokale ontwikkeling.
- Delivery status, retries, dead-letter-afhandeling en monitoring.

**Resultaat:** gecontroleerde e-mailverwerking in een niet-productieomgeving, daarna beperkte productiepilot.

### Fase 4 — WhatsAppintegratie

- Provideradapter volgens hetzelfde kanaalcontract.
- Webhookhandtekening, templates, 24-uursvenster, media en delivery receipts.
- Kanaalspecifieke UI-status zonder ticketworkflow te dupliceren.
- Pilot met beperkte gebruikers en meetbare rollbackmogelijkheid.

**Resultaat:** e-mail en WhatsApp komen samen in één ticketinbox.

### Fase 5 — Orderkoppeling en operationele rapportage

- Read-only orderlookup met minimaal benodigde data en duidelijke bronvermelding.
- Bewuste caching/retentie en autorisatie voor orderdata.
- SLA-, volume-, workload- en kwaliteitsrapportages.
- Performance-optimalisatie, server-side paginatie en relevante indexen.

**Resultaat:** agents hebben context en teamleads kunnen de operatie sturen.

### Fase 6 — AI-antwoordvoorstellen

- Alleen conceptvoorstellen; nooit autonoom verzenden.
- Toegestane kennisbronnen, prompt-/outputfiltering en menselijke goedkeuring.
- Privacybeoordeling, audittrail en evaluatieset met niet-productieve of geanonimiseerde data.
- Meten van acceptatie, correcties, responstijd en fouttypen vóór bredere uitrol.

**Resultaat:** controleerbare assistentie met de medewerker als eindverantwoordelijke.

## Onderzochte bronnen

Voor deze audit zijn read-only onderzocht:

- `AGENTS.md`, `CLAUDE.md`, `PROJECT_CONTEXT.md` en `README.md`;
- `composer.json`, `composer.lock`, `package.json`, `vite.config.js` en `phpunit.xml`;
- Laravel bootstrap- en configuratiebestanden, zonder `.env` te lezen;
- alle routes, custom middleware, modellen, migrations, controllers, factory, seeder en het importcommando;
- `public/index.html`, alle scripts onder `public/js`, de actieve stylesheet en de Vite-entries onder `resources`;
- alle bestaande tests;
- repositorystructuur en aanwezigheid van deployment-/CI-bestanden;
- lokale toolversies en geïnstalleerde top-level dependencyversies, zonder installaties of updates uit te voeren.

Niet uitgevoerd: tests, builds, migrations, seeders, databasequeries, dependency-installaties, commits en pushes.

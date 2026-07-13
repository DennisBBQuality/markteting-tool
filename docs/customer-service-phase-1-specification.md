# Klantenservicemodule — Fase 1 Specificatie

Documentversie: 1.0
Datum: 13 juli 2026
Status: definitief ontwerp voor implementatie
Doelgroep: Codex (implementatie in kleine stappen), reviewers en het BBQuality-team

Dit document is het volledige functionele en technische ontwerp voor fase 1 van de
klantenservicemodule van BBQuality The Pitboard. Het is gebaseerd op de bestaande
codebase (Laravel 12, custom sessie-authenticatie, Vanilla JavaScript SPA in
`public/`) en op `docs/current-system-audit.md`. Fase 1 werkt uitsluitend met
handmatige, lokale testtickets; er is geen enkele externe communicatie.

---

## 1. Doel van fase 1

Fase 1 legt het fundament van de klantenservicemodule:

- een eigen, afgeschermd datamodel voor tickets, ticketberichten, interne
  notities en een append-only activiteitenlog, volledig gescheiden van de
  bestaande chat-, notitie- en taakmodellen;
- een JSON-API onder `/api/customer-service/...` met strikte validatie,
  transacties en optimistic locking;
- een driedelige ticketinbox in de bestaande SPA (filters, ticketlijst,
  ticketdetail);
- aantoonbare bescherming tegen gelijktijdige wijzigingen en dubbele
  antwoorden;
- volledige featuretest- en unit-testdekking van de nieuwe workflow.

Het resultaat: meerdere medewerkers kunnen tegelijk veilig met handmatige
testtickets werken, van aanmaken tot afhandelen, zonder stille dataverlies- of
dubbel-antwoordproblemen. Alle latere fases (e-mail, WhatsApp, orders, AI)
bouwen hierop voort zonder dat het fase 1-datamodel hoeft te worden herzien.

## 2. Exacte scope

### In scope

1. Handmatig aanmaken van testtickets met onderwerp, fictieve klantnaam,
   fictief klant-e-mailadres, prioriteit en een eerste (inkomend) testbericht.
2. Centrale ticketinbox met server-side paginering.
3. Driedelige interface: filterpaneel, ticketlijst, ticketdetail.
4. Eén primaire behandelaar per ticket (`behandelaar_id`).
5. Ticket claimen en vrijgeven.
6. Statuswijzigingen volgens een vaste statusmachine.
7. Prioriteitswijzigingen.
8. Interne notities (alleen zichtbaar voor medewerkers).
9. Handmatige testberichten in de tijdlijn: inkomend (gesimuleerde klant) en
   uitgaand (gesimuleerd antwoord; wordt nergens echt verzonden).
10. Append-only activiteitenhistorie per ticket.
11. Zoeken op ticketnummer, onderwerp, klantnaam en klant-e-mailadres.
12. Filteren op status, prioriteit, behandelaar en "niet toegewezen".
13. Sorteren op laatste bericht, aanmaakdatum en prioriteit.
14. Lokale, fictieve testdata via een expliciet aan te roepen seeder.
15. Optimistic locking met een `versie`-veld en HTTP 409-conflictresponses.
16. Idempotente berichtcreatie via `client_message_id` (voorkomt dubbele
    antwoorden door dubbelklikken, retries of race conditions).
17. Featuretests en unit-tests voor alle bovenstaande functionaliteit.

### Nadrukkelijk niet in scope (zie ook hoofdstuk 25)

- echte e-mailintegratie;
- WhatsApp-integratie;
- orderkoppelingen;
- AI-antwoordvoorstellen;
- echte uitgaande klantcommunicatie (er wordt niets verstuurd);
- SLA-functionaliteit;
- rapportages;
- automatische tickettoewijzing;
- aparte klantenservicerollen.

## 3. Gebruikersworkflow van nieuw tot afgehandeld ticket

1. **Aanmaken.** Een medewerker klikt in de inbox op "Nieuw testticket", vult
   onderwerp, fictieve klantnaam, fictief e-mailadres, prioriteit en de inhoud
   van het eerste klantbericht in. Het ticket krijgt status `nieuw`, een uniek
   ticketnummer (bijv. `CS-2026-00001`) en géén behandelaar.
2. **Signaleren.** Het ticket verschijnt bovenaan de inbox. Iedere medewerker
   ziet in de lijst status, prioriteit, klantnaam, onderwerp, behandelaar
   (leeg) en het tijdstip van het laatste bericht.
3. **Claimen.** Een medewerker opent het ticket en klikt "Claim". De medewerker
   wordt primaire behandelaar en de status gaat automatisch van `nieuw` naar
   `in_behandeling`. Als een collega het ticket in dezelfde seconde claimt,
   krijgt precies één van beiden succes; de ander krijgt een 409-conflict met
   de actuele tickettoestand.
4. **Behandelen.** De behandelaar leest de tijdlijn, voegt eventueel interne
   notities toe (iedereen mag dit) en schrijft een uitgaand testbericht.
   Uitgaande berichten mogen alleen door de huidige behandelaar worden
   toegevoegd; dit is de kern van de dubbel-antwoordpreventie.
5. **Wachten op klant.** Verwacht de behandelaar een reactie van de klant, dan
   zet hij de status op `wachten_op_klant`. Komt er (handmatig gesimuleerd) een
   nieuw inkomend bericht binnen, dan springt de status automatisch terug naar
   `in_behandeling` (of naar `nieuw` als er geen behandelaar is).
6. **Vrijgeven (optioneel).** Kan de behandelaar het ticket niet afronden, dan
   klikt hij "Vrijgeven". De behandelaar wordt leeggemaakt; stond het ticket op
   `in_behandeling`, dan gaat het terug naar `nieuw` zodat het weer zichtbaar
   in de wachtrij staat. Daarna kan iedere collega het claimen.
7. **Afhandelen.** Is de vraag beantwoord, dan zet de behandelaar de status op
   `afgehandeld`. `afgehandeld_op` wordt gevuld. Het ticket verdwijnt uit het
   standaard inboxfilter maar blijft vindbaar via het filter "Afgehandeld" en
   via zoeken.
8. **Heropenen (uitzondering).** Blijkt het ticket toch niet klaar, dan kan
   iedere medewerker het vanaf `afgehandeld` terugzetten naar
   `in_behandeling`. Heeft het ticket op dat moment geen behandelaar, dan wordt
   degene die heropent automatisch behandelaar.

Iedere stap hierboven schrijft een regel in de activiteitenhistorie.

## 4. Betekenis van iedere ticketstatus

Er zijn uitsluitend deze vier statussen:

| Status | Betekenis |
|---|---|
| `nieuw` | Binnengekomen en nog door niemand opgepakt. Het ticket heeft geen behandelaar en staat in de wachtrij. |
| `in_behandeling` | Een medewerker (de behandelaar) is actief met het ticket bezig. |
| `wachten_op_klant` | Er is een antwoord naar de klant gestuurd (gesimuleerd in fase 1) en het team wacht op een reactie van de klant. Er is geen actie van het team nodig. |
| `afgehandeld` | Het ticket is klaar. Er is geen aparte status "opgelost" of "gesloten"; `afgehandeld` is de eindtoestand. |

Er bestaan géén statussen `open`, `wachten_op_intern`, `opgelost` of
`gesloten`, ook niet als alias of weergavelabel.

## 5. Toegestane statusovergangen

De statusmachine wordt server-side afgedwongen in
`app/Services/CustomerService/TicketWorkflowService`. Iedere andere overgang
levert HTTP 409 met code `invalid_status_transition` op.

| Van | Naar | Toegestaan via | Voorwaarde |
|---|---|---|---|
| `nieuw` | `in_behandeling` | claim (automatisch) of statusendpoint | Ticket moet een behandelaar hebben of krijgen; bij claim wordt de claimer behandelaar. Via het statusendpoint alleen toegestaan als er al een behandelaar is. |
| `nieuw` | `afgehandeld` | statusendpoint | Toegestaan zonder behandelaar (bijv. spam of geen actie nodig). |
| `in_behandeling` | `wachten_op_klant` | statusendpoint | — |
| `in_behandeling` | `afgehandeld` | statusendpoint | — |
| `in_behandeling` | `nieuw` | uitsluitend als effect van vrijgeven | Niet rechtstreeks via het statusendpoint aan te vragen. |
| `wachten_op_klant` | `in_behandeling` | statusendpoint, of automatisch bij een nieuw inkomend bericht als het ticket een behandelaar heeft | — |
| `wachten_op_klant` | `nieuw` | automatisch bij een nieuw inkomend bericht als het ticket géén behandelaar heeft | Niet rechtstreeks via het statusendpoint aan te vragen. |
| `wachten_op_klant` | `afgehandeld` | statusendpoint | — |
| `afgehandeld` | `in_behandeling` | statusendpoint (heropenen) | Heeft het ticket geen behandelaar, dan wordt de heropener automatisch behandelaar. |

Expliciet verboden: `afgehandeld → nieuw`, `afgehandeld → wachten_op_klant`,
`nieuw → wachten_op_klant`, en iedere overgang naar de huidige status (no-op
wordt geweigerd met `invalid_status_transition`).

## 6. Werking van ticket claimen en vrijgeven

### Claimen (`POST /api/customer-service/tickets/{id}/claim`)

- Alleen mogelijk als het ticket géén behandelaar heeft. Een ticket van een
  collega "overnemen" bestaat niet in fase 1; de collega moet eerst vrijgeven.
- De claim gebeurt atomair binnen een transactie met een conditionele update:

  ```sql
  UPDATE cs_tickets
     SET behandelaar_id = :userId,
         status = CASE WHEN status = 'nieuw' THEN 'in_behandeling' ELSE status END,
         versie = versie + 1,
         updated_at = :now
   WHERE id = :ticketId
     AND behandelaar_id IS NULL
     AND versie = :verwachteVersie
  ```

  Raakt deze update 0 rijen, dan wordt het ticket opnieuw geladen en volgt een
  409 (`claim_conflict` als er inmiddels een behandelaar is, anders
  `version_conflict`).
- Claimen van een `afgehandeld` ticket is niet toegestaan (409,
  `claim_conflict`).
- Claimen op status `wachten_op_klant` is toegestaan (bijv. na vrijgeven);
  de status blijft dan `wachten_op_klant`.
- Bij succes: activiteit `ticket_geclaimd` wordt gelogd.

### Vrijgeven (`POST /api/customer-service/tickets/{id}/release`)

- Alleen de huidige behandelaar kan zijn eigen claim vrijgeven. Een andere
  gebruiker krijgt 409 (`claim_conflict`).
- `behandelaar_id` wordt `NULL`. Stond het ticket op `in_behandeling`, dan
  wordt de status `nieuw`; stond het op `wachten_op_klant`, dan blijft die
  status staan.
- Vrijgeven van een `afgehandeld` ticket is niet toegestaan.
- Bij succes: activiteit `ticket_vrijgegeven` wordt gelogd.

## 7. Gedrag wanneer meerdere medewerkers hetzelfde ticket openen

- Lezen is altijd vrij: meerdere medewerkers kunnen tegelijk hetzelfde
  ticketdetail openen. Er zijn geen leesvergrendelingen.
- Het detailpaneel toont altijd prominent wie de behandelaar is (naam plus
  kleurbadge) of "Niet toegewezen".
- Het detailpaneel pollt elke 15 seconden
  `GET /api/customer-service/tickets/{id}`. Is de server-`versie` hoger dan de
  lokaal bekende versie, dan:
  - zonder onverzonden invoer in een composer: de tijdlijn en ticketkop worden
    stil ververst;
  - met onverzonden invoer: er verschijnt een gele banner "Dit ticket is
    zojuist door een collega bijgewerkt. Vernieuw voordat je verdergaat." met
    een knop "Vernieuwen". De invoer blijft in het tekstveld staan.
- Alle mutaties (status, prioriteit, claim, vrijgave, berichten) sturen de
  lokaal bekende `versie` mee. Wie op basis van een verouderde weergave
  handelt, krijgt een 409 en ziet de actuele toestand; er gaat nooit stil een
  wijziging van een collega verloren (last-write-wins is uitgesloten).
- De composer voor uitgaande berichten is uitgeschakeld (met uitleg) wanneer de
  ingelogde gebruiker niet de behandelaar is.

## 8. Bescherming tegen dubbele antwoorden en verouderde wijzigingen

Vier elkaar aanvullende mechanismen, alle server-side afgedwongen:

1. **Optimistic locking met `versie`.** Iedere muterende actie vereist het
   veld `versie` in de request body. De service voert de update uit met
   `WHERE id = ? AND versie = ?` en verhoogt `versie` met 1. Raakt de update 0
   rijen, dan antwoordt de API met 409 `version_conflict` inclusief de actuele
   tickettoestand, zodat de frontend kan verversen.
2. **Behandelaarregel voor uitgaande berichten.** Een uitgaand bericht
   (`richting = uitgaand`) mag uitsluitend worden toegevoegd door de huidige
   behandelaar. Iedere andere gebruiker krijgt 409 `not_assignee`. Twee
   medewerkers kunnen dus nooit tegelijk namens het team antwoorden: er is
   maximaal één behandelaar en claims zijn atomair.
3. **Idempotente berichtcreatie.** De frontend genereert per verzendpoging een
   `client_message_id` (UUID, `crypto.randomUUID()`), dat pas wijzigt nadat een
   bericht succesvol is geplaatst. De databank heeft een unieke constraint op
   `(ticket_id, client_message_id)`. Een dubbele submit (dubbelklik, timeout
   plus retry) levert 409 `duplicate_message` op, met het reeds opgeslagen
   bericht in de response, en maakt nooit een tweede databankrij aan.
4. **Transacties.** Iedere mutatie plus bijbehorende activiteitregel plus
   afgeleide veldwijzigingen (bijv. `laatste_bericht_op`, automatische
   statusovergang) zit in één `DB::transaction`, zodat een deelfout nooit een
   halfbijgewerkt ticket achterlaat.

## 9. Datamodel

Vier nieuwe modellen in de namespace `App\Models\CustomerService`, plus één
hulptabel zonder model. Bestaande modellen (`ChatMessage`, `Note`,
`Attachment`, enz.) worden niet hergebruikt en niet gewijzigd.

| Model | Tabel | Doel |
|---|---|---|
| `App\Models\CustomerService\Ticket` | `cs_tickets` | Het ticket zelf, inclusief klant-snapshotvelden, status, prioriteit, behandelaar en versieveld. |
| `App\Models\CustomerService\TicketMessage` | `cs_ticket_messages` | Berichten in de klanttijdlijn (inkomend/uitgaand, in fase 1 altijd kanaal `handmatig`). |
| `App\Models\CustomerService\TicketNote` | `cs_ticket_notes` | Interne notities, nooit klantcommunicatie. |
| `App\Models\CustomerService\TicketActivity` | `cs_ticket_activities` | Append-only activiteitenlog. |
| — (Query Builder) | `cs_ticket_counters` | Jaarteller voor leesbare ticketnummers. |

Ontwerpkeuzes:

- **Geen aparte klantentabel in fase 1.** Klantnaam en klant-e-mailadres staan
  als snapshotvelden op het ticket (`klant_naam`, `klant_email`). Fase 1 bevat
  uitsluitend fictieve testdata, dus er is geen migratierisico; een
  genormaliseerde `cs_customers`-tabel volgt in de fase waarin echte kanalen
  worden gekoppeld.
- **Prefix `cs_`** op alle tabellen houdt de module herkenbaar gescheiden van
  de bestaande portaltabellen.
- Alle modellen gebruiken `HasUuids` en UUID primary keys, conform de rest van
  de applicatie.
- `TicketActivity` heeft geen `updated_at` en geen update/delete-paden
  (append-only).
- Alle relaties naar `users` verwijzen naar het bestaande `User`-model; het
  `User`-model zelf wordt niet gewijzigd.

Relaties:

- `Ticket hasMany TicketMessage / TicketNote / TicketActivity`
- `Ticket belongsTo User` als `behandelaar` (`behandelaar_id`) en als
  `aangemaaktDoor` (`aangemaakt_door`)
- `TicketMessage / TicketNote / TicketActivity belongsTo Ticket` en
  `belongsTo User` als `auteur`/`gebruiker` (nullable)

## 10. Tabellen, velden, datatypes en relaties

### `cs_tickets`

| Kolom | Type | Nullable | Default | Toelichting |
|---|---|---|---|---|
| `id` | uuid, primary | nee | — | |
| `ticketnummer` | string(20), unique | nee | — | Formaat `CS-JJJJ-NNNNN`, zie hoofdstuk 12. |
| `onderwerp` | string(255) | nee | — | |
| `klant_naam` | string(255) | nee | — | Fictief in fase 1. |
| `klant_email` | string(255) | nee | — | Fictief in fase 1. |
| `status` | enum(`nieuw`,`in_behandeling`,`wachten_op_klant`,`afgehandeld`) | nee | `nieuw` | |
| `prioriteit` | enum(`laag`,`normaal`,`hoog`,`urgent`) | nee | `normaal` | Zelfde reeks als `tasks.prioriteit`. |
| `behandelaar_id` | uuid, FK `users.id` `nullOnDelete` | ja | `NULL` | Primaire behandelaar. |
| `aangemaakt_door` | uuid, FK `users.id` `nullOnDelete` | ja | — | Medewerker die het testticket aanmaakte. |
| `versie` | unsignedInteger | nee | `1` | Optimistic-lockingveld; +1 bij iedere mutatie. |
| `laatste_bericht_op` | timestamp | ja | `NULL` | Denormalisatie voor sorteren; bijgewerkt bij ieder nieuw bericht. |
| `afgehandeld_op` | timestamp | ja | `NULL` | Gevuld bij overgang naar `afgehandeld`; geleegd bij heropenen. |
| `created_at` / `updated_at` | timestamps | — | — | |

### `cs_ticket_messages`

| Kolom | Type | Nullable | Default | Toelichting |
|---|---|---|---|---|
| `id` | uuid, primary | nee | — | |
| `ticket_id` | uuid, FK `cs_tickets.id` `cascadeOnDelete` | nee | — | |
| `richting` | enum(`inkomend`,`uitgaand`) | nee | — | `inkomend` = gesimuleerde klant, `uitgaand` = gesimuleerd antwoord. |
| `kanaal` | enum(`handmatig`) | nee | `handmatig` | Enum wordt in latere fases uitgebreid met `email`, `whatsapp`. |
| `auteur_id` | uuid, FK `users.id` `nullOnDelete` | ja | — | Medewerker die het (test)bericht invoerde. |
| `inhoud` | text | nee | — | Max 10.000 tekens (validatie). |
| `client_message_id` | uuid | nee | — | Idempotencysleutel, aangeleverd door de client. |
| `created_at` / `updated_at` | timestamps | — | — | |

### `cs_ticket_notes`

| Kolom | Type | Nullable | Default | Toelichting |
|---|---|---|---|---|
| `id` | uuid, primary | nee | — | |
| `ticket_id` | uuid, FK `cs_tickets.id` `cascadeOnDelete` | nee | — | |
| `auteur_id` | uuid, FK `users.id` `nullOnDelete` | ja | — | |
| `inhoud` | text | nee | — | Max 10.000 tekens. |
| `created_at` / `updated_at` | timestamps | — | — | |

### `cs_ticket_activities`

| Kolom | Type | Nullable | Default | Toelichting |
|---|---|---|---|---|
| `id` | uuid, primary | nee | — | |
| `ticket_id` | uuid, FK `cs_tickets.id` `cascadeOnDelete` | nee | — | |
| `gebruiker_id` | uuid, FK `users.id` `nullOnDelete` | ja | — | `NULL` bij systeemacties (bijv. automatische statusovergang). |
| `actie` | string(50) | nee | — | Zie hoofdstuk 13 voor de vaste lijst. |
| `details` | json | ja | `NULL` | Oude/nieuwe waarden, bijv. `{"van":"nieuw","naar":"in_behandeling"}`. |
| `created_at` | timestamp | nee | — | Geen `updated_at`: append-only. |

### `cs_ticket_counters`

| Kolom | Type | Toelichting |
|---|---|---|
| `jaar` | unsignedInteger, primary | Kalenderjaar, bijv. `2026`. |
| `laatste_nummer` | unsignedInteger, default `0` | Laatst uitgegeven volgnummer binnen het jaar. |

## 11. Database-indexen en unieke constraints

| Tabel | Index/constraint | Reden |
|---|---|---|
| `cs_tickets` | unique(`ticketnummer`) | Ticketnummers zijn uniek en opzoekbaar. |
| `cs_tickets` | index(`status`) | Inboxfilters. |
| `cs_tickets` | index(`prioriteit`) | Inboxfilters. |
| `cs_tickets` | index(`behandelaar_id`) | Filter "mijn tickets" / "niet toegewezen". |
| `cs_tickets` | index(`laatste_bericht_op`) | Standaardsortering. |
| `cs_tickets` | index(`created_at`) | Sortering op aanmaakdatum. |
| `cs_ticket_messages` | unique(`ticket_id`,`client_message_id`) | Idempotentie; hard voorkomen van dubbele berichten. |
| `cs_ticket_messages` | index(`ticket_id`,`created_at`) | Tijdlijn ophalen in volgorde. |
| `cs_ticket_notes` | index(`ticket_id`,`created_at`) | Notities in volgorde. |
| `cs_ticket_activities` | index(`ticket_id`,`created_at`) | Historie in volgorde. |
| `cs_ticket_counters` | primary(`jaar`) | Eén tellerrij per jaar. |

Zoeken op `onderwerp`/`klant_naam`/`klant_email` gebeurt in fase 1 met
`LIKE '%...%'` zonder extra indexen; bij lokale testvolumes is dat toereikend.
Full-text search is expliciet geen fase 1-onderwerp.

## 12. Leesbaar ticketnummerformaat

- Formaat: `CS-JJJJ-NNNNN`, bijv. `CS-2026-00001`.
- `JJJJ` = kalenderjaar van aanmaken; `NNNNN` = volgnummer binnen dat jaar,
  vijf posities met voorloopnullen. Boven de 99.999 groeit het nummer gewoon
  door (`CS-2026-100000`); het formaat is niet hard begrensd.
- Uitgifte via `app/Services/CustomerService/TicketNumberService`, binnen
  dezelfde transactie als de ticketcreatie:
  1. `INSERT` de jaarrij in `cs_ticket_counters` als die nog niet bestaat
     (insert-or-ignore).
  2. Lees de tellerrij met `lockForUpdate()` en verhoog `laatste_nummer` met 1.
  3. Formatteer het ticketnummer.
- De unieke constraint op `cs_tickets.ticketnummer` is het vangnet: mocht er
  ooit toch een botsing optreden, dan faalt de transactie en wordt niets
  half opgeslagen.
- Het ticketnummer is puur een leesbare identifier voor mensen; alle relaties
  en URL's gebruiken de UUID.

## 13. Activiteitenlog en geregistreerde acties

`cs_ticket_activities` is append-only: er bestaan geen update- of
delete-endpoints en de service biedt alleen een `log()`-methode. Iedere regel
bevat ticket, gebruiker (of `NULL` voor systeemacties), actie, details-JSON en
timestamp.

Vaste lijst met acties in fase 1:

| `actie` | Wanneer | `details`-voorbeeld |
|---|---|---|
| `ticket_aangemaakt` | Bij creatie van het ticket. | `{"prioriteit":"normaal"}` |
| `status_gewijzigd` | Bij iedere statusovergang, ook automatische. | `{"van":"nieuw","naar":"in_behandeling","automatisch":false}` |
| `prioriteit_gewijzigd` | Bij prioriteitswijziging. | `{"van":"normaal","naar":"hoog"}` |
| `ticket_geclaimd` | Bij succesvolle claim. | `{"behandelaar_id":"<uuid>"}` |
| `ticket_vrijgegeven` | Bij vrijgave. | `{"behandelaar_id":"<uuid>"}` |
| `bericht_toegevoegd` | Bij ieder nieuw tijdlijnbericht. | `{"bericht_id":"<uuid>","richting":"uitgaand"}` |
| `notitie_toegevoegd` | Bij iedere interne notitie. | `{"notitie_id":"<uuid>"}` |

Automatische statusovergangen (bijv. `wachten_op_klant → in_behandeling` door
een inkomend bericht) loggen `status_gewijzigd` met `"automatisch": true` en
`gebruiker_id` van de medewerker die het inkomende testbericht invoerde.

De volledige berichtinhoud wordt niet in `details` gedupliceerd; alleen ID's en
metadata. Persoonsgegevens blijven zo buiten het log.

## 14. API-endpoints

Alle routes staan in `routes/web.php` binnen de bestaande
`auth.custom`-middlewaregroep, in een eigen blok
`// Customer Service`. Prefix: `/api/customer-service`.

| Methode | Route | Doel |
|---|---|---|
| `GET` | `/api/customer-service/tickets` | Inboxlijst met filters, zoeken, sorteren en paginering. |
| `POST` | `/api/customer-service/tickets` | Testticket aanmaken (incl. eerste inkomend bericht). |
| `GET` | `/api/customer-service/tickets/{id}` | Ticketdetail (kopgegevens + behandelaar). |
| `POST` | `/api/customer-service/tickets/{id}/claim` | Ticket claimen. |
| `POST` | `/api/customer-service/tickets/{id}/release` | Ticket vrijgeven. |
| `PUT` | `/api/customer-service/tickets/{id}/status` | Status wijzigen. |
| `PUT` | `/api/customer-service/tickets/{id}/priority` | Prioriteit wijzigen. |
| `GET` | `/api/customer-service/tickets/{id}/messages` | Tijdlijnberichten (oplopend op `created_at`). |
| `POST` | `/api/customer-service/tickets/{id}/messages` | Handmatig testbericht toevoegen. |
| `GET` | `/api/customer-service/tickets/{id}/notes` | Interne notities. |
| `POST` | `/api/customer-service/tickets/{id}/notes` | Interne notitie toevoegen. |
| `GET` | `/api/customer-service/tickets/{id}/activities` | Activiteitenhistorie (aflopend op `created_at`). |

Controllers (dun; alle logica in services) onder
`app/Http/Controllers/Api/CustomerService/`:

- `TicketController` — `index`, `store`, `show`
- `TicketClaimController` — `claim`, `release`
- `TicketStatusController` — `update`
- `TicketPriorityController` — `update`
- `TicketMessageController` — `index`, `store`
- `TicketNoteController` — `index`, `store`
- `TicketActivityController` — `index`

Er zijn bewust géén update- of delete-endpoints voor tickets, berichten of
notities in fase 1: berichten en notities zijn onveranderlijk zodra geplaatst,
en tickets worden nooit verwijderd. Dat houdt de historie betrouwbaar.

### Queryparameters voor `GET /tickets`

| Parameter | Type | Betekenis |
|---|---|---|
| `status` | string, enumwaarde | Filter op één status. Ontbreekt de parameter, dan worden alle niet-afgehandelde tickets getoond. `status=alle` toont alles inclusief afgehandeld. |
| `prioriteit` | string, enumwaarde | Filter op prioriteit. |
| `behandelaar_id` | uuid | Tickets van één behandelaar. |
| `niet_toegewezen` | `1` | Alleen tickets zonder behandelaar. Sluit `behandelaar_id` uit. |
| `zoek` | string, max 255 | Zoekt (LIKE, case-insensitive) in `ticketnummer`, `onderwerp`, `klant_naam`, `klant_email`. |
| `sorteer` | `laatste_bericht` (default) \| `aangemaakt` \| `prioriteit` | Sorteersleutel, zie hoofdstuk 19. |
| `richting` | `asc` \| `desc` (default `desc`) | Sorteerrichting. |
| `page` | integer ≥ 1 | Paginanummer. |
| `per_page` | integer 1–100, default 25 | Paginagrootte. |

De lijstresponse gebruikt (anders dan de bestaande, ongepagineerde endpoints)
een expliciete envelop: `{ "data": [...], "meta": { "page": 1, "per_page": 25,
"total": 87, "last_page": 4 } }`.

## 15. Request- en responsevoorbeelden

### Ticket aanmaken

`POST /api/customer-service/tickets`

```json
{
  "onderwerp": "Vraag over levering picanha",
  "klant_naam": "Test Klant",
  "klant_email": "testklant@example.test",
  "prioriteit": "normaal",
  "eerste_bericht": "Wanneer wordt mijn bestelling geleverd?"
}
```

Response `201 Created`:

```json
{
  "id": "0197a1e2-...",
  "ticketnummer": "CS-2026-00001",
  "onderwerp": "Vraag over levering picanha",
  "klant_naam": "Test Klant",
  "klant_email": "testklant@example.test",
  "status": "nieuw",
  "prioriteit": "normaal",
  "behandelaar": null,
  "aangemaakt_door": { "id": "…", "naam": "Dennis", "kleur": "#3B82F6" },
  "versie": 1,
  "laatste_bericht_op": "2026-07-13T09:15:00.000000Z",
  "afgehandeld_op": null,
  "created_at": "2026-07-13T09:15:00.000000Z",
  "updated_at": "2026-07-13T09:15:00.000000Z"
}
```

### Ticketdetail

`GET /api/customer-service/tickets/{id}` → `200 OK` met hetzelfde
ticketobject als hierboven (behandelaar als `{id, naam, kleur}` of `null`).

### Claimen

`POST /api/customer-service/tickets/{id}/claim`

```json
{ "versie": 1 }
```

Succes `200 OK`: het bijgewerkte ticketobject (`status` eventueel
`in_behandeling`, `versie` verhoogd).

Conflict `409 Conflict` (collega was eerder):

```json
{
  "error": "Dit ticket is al geclaimd door een collega.",
  "code": "claim_conflict",
  "ticket": { "…": "actuele tickettoestand, incl. behandelaar en versie" }
}
```

### Status wijzigen

`PUT /api/customer-service/tickets/{id}/status`

```json
{ "status": "wachten_op_klant", "versie": 3 }
```

Succes `200 OK`: bijgewerkt ticketobject. Ongeldige overgang `409`:

```json
{
  "error": "Statusovergang van 'afgehandeld' naar 'wachten_op_klant' is niet toegestaan.",
  "code": "invalid_status_transition",
  "ticket": { "…": "actuele tickettoestand" }
}
```

Verouderde versie `409`:

```json
{
  "error": "Het ticket is intussen gewijzigd. Vernieuw en probeer opnieuw.",
  "code": "version_conflict",
  "ticket": { "…": "actuele tickettoestand" }
}
```

### Bericht toevoegen

`POST /api/customer-service/tickets/{id}/messages`

```json
{
  "richting": "uitgaand",
  "inhoud": "Uw bestelling wordt morgen bezorgd.",
  "client_message_id": "6f1c2a34-9b0d-4e5f-8a7b-1c2d3e4f5a6b",
  "versie": 4
}
```

Succes `201 Created`:

```json
{
  "bericht": {
    "id": "0197a1f0-...",
    "ticket_id": "0197a1e2-...",
    "richting": "uitgaand",
    "kanaal": "handmatig",
    "auteur": { "id": "…", "naam": "Dennis", "kleur": "#3B82F6" },
    "inhoud": "Uw bestelling wordt morgen bezorgd.",
    "created_at": "2026-07-13T09:30:00.000000Z"
  },
  "ticket": { "…": "bijgewerkt ticketobject met verhoogde versie" }
}
```

Niet-behandelaar probeert te antwoorden `409`:

```json
{
  "error": "Alleen de behandelaar kan een antwoord toevoegen. Claim het ticket eerst.",
  "code": "not_assignee",
  "ticket": { "…": "actuele tickettoestand" }
}
```

Dubbele submit `409`:

```json
{
  "error": "Dit bericht is al geplaatst.",
  "code": "duplicate_message",
  "bericht": { "…": "het eerder opgeslagen bericht" },
  "ticket": { "…": "actuele tickettoestand" }
}
```

### Validatiefout

`422 Unprocessable Entity` (Laravel-standaard):

```json
{
  "message": "The onderwerp field is required.",
  "errors": { "onderwerp": ["The onderwerp field is required."] }
}
```

## 16. Validatieregels

Alle validatie via Form Requests onder `app/Http/Requests/CustomerService/`
(nieuw; het bestaande patroon met inline `$request->validate()` blijft in de
oude controllers ongewijzigd, maar de nieuwe module gebruikt Form Requests
voor strikte, herbruikbare regels).

| Endpoint | Veld | Regels |
|---|---|---|
| `POST /tickets` | `onderwerp` | `required\|string\|max:255` |
| | `klant_naam` | `required\|string\|max:255` |
| | `klant_email` | `required\|email\|max:255` |
| | `prioriteit` | `sometimes\|in:laag,normaal,hoog,urgent` (default `normaal`) |
| | `eerste_bericht` | `required\|string\|max:10000` |
| `POST .../claim`, `POST .../release` | `versie` | `required\|integer\|min:1` |
| `PUT .../status` | `status` | `required\|in:nieuw,in_behandeling,wachten_op_klant,afgehandeld` |
| | `versie` | `required\|integer\|min:1` |
| `PUT .../priority` | `prioriteit` | `required\|in:laag,normaal,hoog,urgent` |
| | `versie` | `required\|integer\|min:1` |
| `POST .../messages` | `richting` | `required\|in:inkomend,uitgaand` |
| | `inhoud` | `required\|string\|max:10000` |
| | `client_message_id` | `required\|uuid` |
| | `versie` | `required\|integer\|min:1` |
| `POST .../notes` | `inhoud` | `required\|string\|max:10000` |
| | `versie` | `required\|integer\|min:1` |
| `GET /tickets` | `status` | `sometimes\|in:nieuw,in_behandeling,wachten_op_klant,afgehandeld,alle` |
| | `prioriteit` | `sometimes\|in:laag,normaal,hoog,urgent` |
| | `behandelaar_id` | `sometimes\|uuid` |
| | `niet_toegewezen` | `sometimes\|boolean` |
| | `zoek` | `sometimes\|string\|max:255` |
| | `sorteer` | `sometimes\|in:laatste_bericht,aangemaakt,prioriteit` |
| | `richting` | `sometimes\|in:asc,desc` |
| | `page` | `sometimes\|integer\|min:1` |
| | `per_page` | `sometimes\|integer\|min:1\|max:100` |

Aanvullend, in de services afgedwongen (geen invoervalidatie maar
toestandsvalidatie, altijd 409): statusmachine, claimregels, behandelaarregel
voor uitgaande berichten, versievergelijking en idempotency.

## 17. HTTP-foutcodes en conflictresponses

| Code | Wanneer | Responsevorm |
|---|---|---|
| `200` | Geslaagde lees- of muteeractie. | Object of envelop. |
| `201` | Geslaagde creatie (ticket, bericht, notitie). | Aangemaakt object (bij berichten/notities incl. bijgewerkt ticket). |
| `401` | Niet ingelogd (bestaande `RequireAuth`). | `{"error":"Niet ingelogd"}` |
| `404` | Ticket-UUID bestaat niet (`findOrFail`). | Laravel-standaard JSON. |
| `409` | Toestandsconflict. | `{"error": "<mens-leesbaar, NL>", "code": "<machine-code>", "ticket": {…actueel…}}`; bij `duplicate_message` aangevuld met `"bericht"`. |
| `422` | Invoervalidatie gefaald. | Laravel-standaard `message` + `errors`. |
| `500` | Onverwachte fout. | Laravel-standaard; geen interne details naar de client. |

Machinecodes bij `409` (vaste lijst): `version_conflict`, `claim_conflict`,
`invalid_status_transition`, `not_assignee`, `duplicate_message`,
`ticket_afgehandeld` (bericht of notitie toevoegen aan een afgehandeld ticket
is niet toegestaan; eerst heropenen).

De frontend behandelt iedere 409 uniform: actuele tickettoestand uit de
response verwerken, tijdlijn verversen en de `error`-tekst als toast of banner
tonen.

## 18. Driedelige frontendopbouw

De module volgt exact het bestaande SPA-patroon (geen framework, geen build,
geen router):

1. **`public/index.html`** — één nieuw sidebaritem tussen "Berichten" en
   "Converter":

   ```html
   <a href="#" data-view="customer-service" class="nav-item">
     <i class="fas fa-headset"></i><span>Klantenservice</span>
   </a>
   ```

   plus één nieuwe viewcontainer
   `<div id="view-customer-service" class="view hidden"></div>` en één extra
   scripttag `<script src="/js/customer-service.js"></script>`.
2. **`public/js/app.js`** — één nieuwe case in `navigateTo()`:
   `case 'customer-service': renderCustomerService(); break;` en een
   `cleanup`-aanroep bij het verlaten van de view (stopt de detailpolling),
   naar analogie van `cleanupChat()`.
3. **`public/js/customer-service.js`** — nieuw bestand met alle modulelogica.
4. **`public/css/style.css`** — nieuwe componentklassen met prefix `cs-`
   (`.cs-layout`, `.cs-filters`, `.cs-list`, `.cs-detail`, …), toegevoegd
   onderaan het bestand; bestaande regels blijven onaangeraakt.

### Indeling binnen `#view-customer-service`

```
┌────────────┬──────────────────────┬───────────────────────────────┐
│ Filters    │ Ticketlijst          │ Ticketdetail                  │
│ (vast,     │ (scrollbaar)         │ (scrollbaar)                  │
│ ~200px)    │ (~340px)             │ (rest)                        │
├────────────┼──────────────────────┼───────────────────────────────┤
│ Zoekveld   │ Per ticket:          │ Kop: ticketnummer, onderwerp, │
│ Status-    │  nummer, onderwerp,  │  klant, status- en prioriteit-│
│  filters   │  klantnaam, status-  │  selectie, claim/vrijgeef-    │
│ Prioriteit │  badge, prioriteit-  │  knop, behandelaarbadge       │
│ Behandelaar│  badge, behandelaar- │ Tabs: Tijdlijn | Notities |   │
│ "Mijn      │  badge, tijd laatste │  Historie                     │
│  tickets"  │  bericht             │ Tijdlijn: berichten links     │
│ "Niet toe- │ Sorteerkeuze boven   │  (inkomend) / rechts          │
│  gewezen"  │  de lijst            │  (uitgaand) + composer        │
│ Knop       │ Paginering onder     │ Notities: lijst + composer    │
│ "Nieuw     │  de lijst            │ Historie: activiteitenlog     │
│  ticket"   │                      │  (alleen-lezen)               │
└────────────┴──────────────────────┴───────────────────────────────┘
```

- "Nieuw testticket" opent de bestaande globale modal (`openModal()`).
- Statusbadges gebruiken vaste kleuren per status; prioriteitsbadges volgen de
  bestaande prioriteitsweergave van taken.
- De composer voor uitgaande berichten heeft een richtingkeuze
  (inkomend/uitgaand testbericht), een tekstveld en een verzendknop; de
  uitgaand-optie is uitgeschakeld met tooltip wanneer de gebruiker niet de
  behandelaar is.
- Alle dynamische waarden gaan door de bestaande `escHtml()`-helper.
- Op mobiel (bestaande responsive breakpoints) stapelen de drie panelen:
  lijstweergave eerst, detail als overlay bij selectie.

## 19. Zoek-, filter- en sorteermogelijkheden

### Zoeken

Eén zoekveld; server-side `LIKE`-zoekopdracht (case-insensitive) over
`ticketnummer`, `onderwerp`, `klant_naam` en `klant_email`. Zoeken combineert
met actieve filters. Debounce van 300 ms in de frontend.

### Filteren

- Status: Alles (niet-afgehandeld, default) / Nieuw / In behandeling /
  Wachten op klant / Afgehandeld / Alle (inclusief afgehandeld).
- Prioriteit: alle / laag / normaal / hoog / urgent.
- Behandelaar: dropdown met actieve gebruikers (hergebruik
  `userSelectOptions()`), plus snelkoppelingen "Mijn tickets" en
  "Niet toegewezen".
- Filters zijn combineerbaar en worden als queryparameters meegestuurd; de
  teller per statusfilter is geen fase 1-vereiste.

### Sorteren

| Sleutel | Gedrag |
|---|---|
| `laatste_bericht` (default) | `laatste_bericht_op` aflopend, `NULL` als laatste; secundair `created_at` aflopend. |
| `aangemaakt` | `created_at`, richting instelbaar. |
| `prioriteit` | Vaste volgorde urgent → hoog → normaal → laag (via `CASE`-expressie), secundair `laatste_bericht_op` aflopend. |

## 20. Lege staten, loading states en foutmeldingen

| Situatie | Gedrag |
|---|---|
| Lijst wordt geladen | Skeleton/spinner in het lijstpaneel; filters blijven bedienbaar. |
| Geen tickets (geen filters) | Lege staat met illustratieve tekst "Nog geen tickets. Maak een testticket aan om te beginnen." plus knop "Nieuw testticket". |
| Geen resultaten (met filters/zoekterm) | "Geen tickets gevonden voor deze filters." plus knop "Filters wissen". |
| Geen ticket geselecteerd | Detailpaneel toont neutrale placeholder "Selecteer een ticket uit de lijst." |
| Detail wordt geladen | Spinner in het detailpaneel; lijst blijft bedienbaar. |
| Netwerk-/serverfout bij laden | Foutmelding in het betreffende paneel met knop "Opnieuw proberen"; bestaande `toast()` voor secundaire meldingen. |
| 409-conflict | Banner in het detailpaneel met de `error`-tekst van de server en knop "Vernieuwen"; ingevoerde composertekst blijft behouden. |
| 422-validatiefout | Veldgerichte melding in het formulier/modal; geen toast-spam. |
| Verzenden bezig | Verzendknop disabled met spinner; voorkomt dubbelklikken als eerste verdedigingslinie (de `client_message_id` is de tweede). |
| Sessie verlopen (401) | Bestaand gedrag van de `api()`-helper: terug naar het loginscherm. |

## 21. Beveiligings- en privacymaatregelen binnen fase 1

- Alle routes staan binnen de bestaande `auth.custom`-middlewaregroep; er is
  géén anonieme toegang. Er zijn geen extra rolchecks: iedere ingelogde
  gebruiker heeft dezelfde functionele rechten binnen de module (bewust besluit
  voor fase 1).
- Uitsluitend fictieve, lokale testdata. De seeder gebruikt herkenbaar neppe
  namen en e-mailadressen op `example.test`; er worden nooit echte
  klantgegevens ingevoerd of geïmporteerd.
- Er wordt niets extern verzonden: geen mail, geen WhatsApp, geen webhooks.
  `kanaal` kent alleen de waarde `handmatig`.
- Strikte servervalidatie via Form Requests (hoofdstuk 16); enumwaarden en
  UUID's worden altijd gecontroleerd.
- Alle frontend-rendering van dynamische waarden loopt via `escHtml()` tegen
  XSS; berichtinhoud wordt als platte tekst met behoud van regeleinden
  weergegeven (geen HTML-rendering).
- Geen delete- of edit-endpoints voor berichten, notities en activiteiten:
  de historie is niet manipuleerbaar.
- Het activiteitenlog bevat ID's en metadata, geen gekopieerde berichtinhoud.
- Foutresponses lekken geen interne details (geen exceptionteksten naar de
  client; Laravel-standaardgedrag met `APP_DEBUG=false` buiten lokaal).
- Bekende, geaccepteerde restrisico's van het bestaande platform (CSRF-
  uitzondering op `api/*`, ontbrekende sessieregeneratie, geen rate limiting)
  worden in fase 1 niet aangepakt; ze staan in `docs/current-system-audit.md`
  en horen bij het platformhardeningtraject, niet bij deze module. De module
  voegt geen nieuwe varianten van deze risico's toe.

## 22. Benodigde transacties

Iedere actie die meer dan één record raakt draait in één `DB::transaction`:

| Actie | Records binnen de transactie |
|---|---|
| Ticket aanmaken | Tellerrij (lock + increment), ticket, eerste `TicketMessage`, activiteit `ticket_aangemaakt`, activiteit `bericht_toegevoegd`. |
| Claimen | Conditionele ticketupdate (behandelaar, evt. status, versie), activiteit(en) `ticket_geclaimd` en evt. `status_gewijzigd`. |
| Vrijgeven | Ticketupdate (behandelaar `NULL`, evt. status, versie), activiteiten `ticket_vrijgegeven` en evt. `status_gewijzigd`. |
| Status wijzigen | Ticketupdate (status, evt. `afgehandeld_op`, evt. behandelaar bij heropenen, versie), activiteit `status_gewijzigd`. |
| Prioriteit wijzigen | Ticketupdate (prioriteit, versie), activiteit `prioriteit_gewijzigd`. |
| Bericht toevoegen | `TicketMessage`-insert, ticketupdate (`laatste_bericht_op`, evt. automatische status, versie), activiteiten `bericht_toegevoegd` en evt. `status_gewijzigd`. |
| Notitie toevoegen | `TicketNote`-insert, ticketupdate (versie), activiteit `notitie_toegevoegd`. |

De versiecontrole (`WHERE versie = ?`) zit ín de transactie; bij 0 geraakte
rijen wordt de transactie afgebroken en volgt een 409. SQLite en MySQL worden
beide ondersteund door uitsluitend portable schemabuilder- en
querybuilderconstructies te gebruiken (geen `PRAGMA`, geen databankspecifieke
SQL behalve de via de querybuilder opgebouwde `CASE`-sortering).

## 23. Benodigde featuretests en unit-tests

Testinfrastructuur: PHPUnit met SQLite in-memory (bestaande `phpunit.xml`),
`RefreshDatabase` op alle nieuwe featuretests. De bestaande `UserFactory` past
niet op het werkelijke userschema (zie audit) en wordt als onderdeel van
CS-001 gecorrigeerd zodat tests ingelogde gebruikers kunnen aanmaken; er komt
een testhelper `actingAsUser()` die een user aanmaakt en de sessie vult met
`userId` en `rol`, zoals de custom middleware verwacht.

### Unit-tests (`tests/Unit/CustomerService/`)

1. `TicketNumberServiceTest` — eerste nummer van een jaar is `CS-JJJJ-00001`;
   opeenvolgende nummers tellen op; jaarwissel start een nieuwe reeks;
   formattering met voorloopnullen.
2. `TicketWorkflowServiceTest` — volledige statusovergangsmatrix (toegestaan
   én verboden, inclusief no-op); claim op geclaimd ticket faalt; release door
   niet-behandelaar faalt; vrijgeven zet `in_behandeling` terug naar `nieuw`;
   heropenen zet behandelaar bij ontbreken; `afgehandeld_op` wordt gezet en
   geleegd; versie wordt bij iedere mutatie verhoogd; verouderde versie geeft
   conflictexception.
3. `TicketMessageServiceTest` — uitgaand bericht door niet-behandelaar faalt;
   inkomend bericht op `wachten_op_klant` triggert de juiste automatische
   status; `laatste_bericht_op` wordt bijgewerkt; duplicaat
   `client_message_id` levert het bestaande bericht op zonder nieuwe rij.

### Featuretests (`tests/Feature/CustomerService/`)

1. `TicketAuthTest` — alle twaalf endpoints geven 401 zonder sessie.
2. `TicketCrudTest` — ticket aanmaken (201, ticketnummer, eerste bericht,
   twee activiteiten); detail ophalen; 404 bij onbekende UUID; 422 bij ieder
   ontbrekend/ongeldig veld.
3. `TicketListTest` — defaultfilter verbergt afgehandelde tickets; alle
   filters, zoeken (per zoekveld), alle sorteringen, paginering incl.
   `meta`-envelop, `per_page`-limiet.
4. `TicketClaimTest` — claim geslaagd (status `nieuw → in_behandeling`,
   activiteiten); claim op geclaimd ticket → 409 `claim_conflict`; claim op
   afgehandeld ticket → 409; twee opeenvolgende claims met dezelfde
   uitgangsversie → precies één succes; release door behandelaar
   (status terug naar `nieuw`); release door ander → 409.
5. `TicketStatusTest` — alle toegestane overgangen via het endpoint; alle
   verboden overgangen → 409 `invalid_status_transition`; verouderde versie →
   409 `version_conflict` met actuele tickettoestand in de response;
   heropenen wijst behandelaar toe.
6. `TicketPriorityTest` — wijzigen geslaagd; activiteit gelogd; versieconflict.
7. `TicketMessageTest` — inkomend en uitgaand bericht; uitgaand door
   niet-behandelaar → 409 `not_assignee`; duplicaat `client_message_id` → 409
   `duplicate_message` met bestaand bericht; bericht op afgehandeld ticket →
   409 `ticket_afgehandeld`; automatische statusovergang bij inkomend bericht;
   tijdlijnvolgorde.
8. `TicketNoteTest` — notitie toevoegen door willekeurige ingelogde
   gebruiker; activiteit gelogd; notitie op afgehandeld ticket → 409.
9. `TicketActivityTest` — historie-endpoint geeft alle acties in aflopende
   volgorde; activiteiten zijn niet muteerbaar (geen routes bestaan; test dat
   PUT/DELETE 404/405 geven).

Alle bestaande tests (`tests/Feature/ExampleTest`, `tests/Unit/ExampleTest`)
moeten na iedere taak blijven slagen. Frontend-unit-tests zijn buiten scope
(er is geen JS-testinfrastructuur); frontendtaken worden handmatig
geverifieerd volgens de acceptatiecriteria.

## 24. Acceptatiecriteria per functionaliteit

**Ticket aanmaken**
- Een ingelogde gebruiker kan via de modal een testticket aanmaken met
  onderwerp, klantnaam, klant-e-mail, prioriteit en eerste bericht.
- Het ticket krijgt status `nieuw`, geen behandelaar, `versie` 1, een uniek
  ticketnummer in het formaat `CS-JJJJ-NNNNN` en verschijnt direct in de lijst.
- Het eerste bericht staat als inkomend bericht in de tijdlijn;
  `laatste_bericht_op` is gevuld.

**Inbox / lijst**
- De lijst toont per ticket: ticketnummer, onderwerp, klantnaam, statusbadge,
  prioriteitsbadge, behandelaarbadge (of "—") en tijdstip laatste bericht.
- Standaard zijn afgehandelde tickets verborgen; het statusfilter kan ze tonen.
- Paginering werkt server-side; de `meta`-informatie klopt met het totaal.

**Claimen en vrijgeven**
- Claimen maakt de gebruiker behandelaar en zet `nieuw` om naar
  `in_behandeling`; de knop verandert in "Vrijgeven".
- Gelijktijdige claims leveren exact één winnaar op; de verliezer ziet een
  duidelijke melding en de actuele behandelaar.
- Vrijgeven kan alleen door de behandelaar; een `in_behandeling`-ticket valt
  terug naar `nieuw`.

**Status en prioriteit wijzigen**
- Alleen toegestane overgangen zijn in de UI beschikbaar én worden server-side
  afgedwongen; een verboden overgang via de API geeft 409.
- `afgehandeld` vult `afgehandeld_op`; heropenen leegt het veld weer.
- Iedere wijziging verhoogt `versie` en logt een activiteit.

**Berichten**
- Inkomende en uitgaande testberichten verschijnen chronologisch in de
  tijdlijn, visueel onderscheiden per richting, met auteur en tijdstip.
- Alleen de behandelaar kan uitgaande berichten plaatsen; anderen zien een
  uitgeschakelde composer met uitleg.
- Dubbelklikken of herhalen van een verzendactie leidt nooit tot een tweede
  bericht (aantoonbaar via de unieke constraint en featuretest).
- Een inkomend bericht op `wachten_op_klant` zet de status automatisch terug.

**Interne notities**
- Iedere ingelogde gebruiker kan notities toevoegen; notities zijn duidelijk
  visueel gescheiden van klantberichten (eigen tab, eigen kleur).
- Notities zijn na plaatsen niet te bewerken of te verwijderen.

**Activiteitenhistorie**
- Alle acties uit hoofdstuk 13 verschijnen met actor, omschrijving en tijdstip
  in de tab Historie, nieuwste bovenaan.

**Zoeken, filteren, sorteren**
- Zoeken vindt tickets op nummer, onderwerp, klantnaam en klant-e-mail.
- Alle filters en sorteringen uit hoofdstuk 19 werken en zijn combineerbaar.

**Concurrency**
- Werkt gebruiker B op een verouderde weergave, dan resulteert iedere mutatie
  in een 409 met verversmogelijkheid; er gaat geen wijziging van gebruiker A
  verloren.
- De detailpolling signaleert wijzigingen van collega's binnen 15 seconden.

**Testdata**
- `php artisan db:seed --class=CustomerServiceTestSeeder` vult lokaal een
  herkenbare set fictieve tickets in alle statussen en prioriteiten; de seeder
  draait niet mee in de standaard `DatabaseSeeder`.

## 25. Expliciete onderdelen buiten scope

De volgende onderwerpen zijn in fase 1 bewust afwezig en mogen door Codex in
geen enkele taak "alvast" worden meegebouwd:

- echte e-mailintegratie (inbound én outbound), mailboxen, IMAP/SMTP,
  providers, webhooks;
- WhatsApp-integratie in welke vorm dan ook;
- orderkoppelingen of verwijzingen naar ordersystemen;
- AI-antwoordvoorstellen of andere AI-functionaliteit;
- echte uitgaande klantcommunicatie (niets verlaat het systeem);
- SLA's, deadlines, escalaties, openingstijden;
- rapportages, dashboards, statistieken over tickets;
- automatische tickettoewijzing of routering;
- aparte klantenservicerollen, policies of per-record-rechten (iedere
  ingelogde gebruiker heeft dezelfde rechten binnen de module);
- bijlagen bij tickets of ticketberichten;
- notificaties/meldingen over ticketgebeurtenissen;
- een aparte klantentabel (`cs_customers`) en klantbeheer;
- bewerken of verwijderen van tickets, berichten en notities;
- samenvoegen, splitsen of dupliceren van tickets;
- bulkacties;
- realtime techniek anders dan de beschreven eenvoudige detailpolling;
- wijzigingen aan bestaande portalonderdelen (chat, notities, taken, enz.);
- migratie van de frontend naar Vite/Tailwind of een ander buildpad;
- CSRF-, sessie- en rate-limitinghardening van het bestaande platform;
- nieuwe Composer- of npm-dependencies.

## 26. Migratie- en rollbackstrategie

- Alle schemawijzigingen gaan via nieuwe Laravel migrations onder
  `database/migrations/`, met datumprefix na de bestaande negen migrations.
  Bestaande migrations en bestaande tabellen worden niet aangeraakt.
- Twee migrationbestanden:
  1. `create_cs_tickets_tables` — `cs_ticket_counters` en `cs_tickets`;
  2. `create_cs_ticket_detail_tables` — `cs_ticket_messages`,
     `cs_ticket_notes`, `cs_ticket_activities`.
- Iedere migration heeft een volledige `down()` die uitsluitend de eigen
  tabellen dropt, in omgekeerde volgorde van aanmaak (eerst kind-, dan
  oudertabellen).
- Omdat de module puur additief is, is rollback risicoloos voor de rest van de
  applicatie: `php artisan migrate:rollback --step=2` verwijdert alleen de
  klantenservicetabellen. Er zijn geen wijzigingen aan bestaande tabellen, dus
  geen datamigraties en geen herstelscenario's voor bestaande data.
- Alleen portable schemabuilderconstructies (geen raw SQL, geen `PRAGMA`),
  zodat SQLite én MySQL werken.
- De seeder (`CustomerServiceTestSeeder`) is idempotent opgezet (verwijdert
  eerst eventuele eerdere testtickets op basis van het herkenbare
  `example.test`-domein of vult alleen aan) en wordt nooit automatisch
  uitgevoerd.
- Uitvoeren van migrations en seeders is een handmatige, lokale actie van de
  ontwikkelaar; geen enkele taak in dit document voert ze zelfstandig op een
  gedeelde omgeving uit.

## 27. Implementatievolgorde voor Codex

Op hoofdlijnen (volledig uitgewerkt in het volgende hoofdstuk):

1. **Testfundament** — factory/testhelperreparatie zodat featuretests met de
   custom sessie-authenticatie kunnen werken (CS-001).
2. **Datamodel** — migrations en modellen, zonder API (CS-002, CS-003).
3. **Domeinservices** — ticketnummers, workflow/statusmachine, berichten- en
   activiteitenservice, met unit-tests (CS-004, CS-005, CS-006).
4. **API** — routes, Form Requests en dunne controllers, endpoint voor
   endpoint, met featuretests (CS-007 t/m CS-011).
5. **Testdata** — seeder (CS-012).
6. **Frontend** — pas nadat datamodel en API volledig getest zijn: skelet,
   lijst, detail, acties, conflictafhandeling, filters en states (CS-013 t/m
   CS-016).
7. **Integrale afronding** — volledige testrun, Pint, handmatige QA-checklist
   (CS-017).

Iedere taak is afzonderlijk te bouwen, te testen, te reviewen en te committen;
de frontend start pas na afronding van CS-011.

---

# Codex Implementation Plan

Algemene regels voor iedere taak:

- Werk op de branch `feature/customer-service-foundation` (of een child-branch
  per taak als het team dat verkiest) en maak per taak één commit.
- Voer vóór afronding van iedere taak `composer test` uit; alle tests moeten
  slagen. Voer `vendor/bin/pint --dirty` uit op de gewijzigde PHP-bestanden.
- Installeer geen dependencies, wijzig geen package-versies, voer geen
  migrations uit op gedeelde omgevingen en raak geen bestaande functionele
  bestanden aan behalve waar een taak dit expliciet noemt.
- Alle Nederlandse veld- en enumnamen exact zoals in deze specificatie.

---

## CS-001 — Testfundament voor de klantenservicemodule

- **Doel:** featuretests kunnen ingelogde gebruikers aanmaken en de custom
  sessie-authenticatie gebruiken.
- **Scope:** corrigeer `database/factories/UserFactory.php` zodat de factory
  het werkelijke schema vult (`naam`, `email`, `wachtwoord_hash`, `rol`,
  `kleur`, `actief`); voeg aan `tests/TestCase.php` een helper toe, bijv.
  `protected function actingAsUser(array $attributes = []): User`, die een
  user aanmaakt en de sessie vult met `userId` en `rol` zoals `RequireAuth`
  en de rolmiddleware verwachten.
- **Bestanden (verwacht):** `database/factories/UserFactory.php` (gewijzigd),
  `tests/TestCase.php` (gewijzigd), `tests/Feature/AuthHelperTest.php` (nieuw).
- **Afhankelijkheden:** geen.
- **Acceptatiecriteria:** `User::factory()->create()` slaagt tegen het echte
  schema; een test met `actingAsUser()` krijgt 200 op `GET /api/auth/me`;
  zonder helper geeft hetzelfde endpoint 401.
- **Tests:** nieuwe `AuthHelperTest` (beide gevallen hierboven) plus de
  bestaande testsuite groen.
- **Niet in deze taak:** klantenservicetabellen, modellen, routes of
  frontendwijzigingen; geen aanpassingen aan middleware of `AuthController`.

## CS-002 — Migrations en model voor tickets en ticketnummerteller

- **Doel:** de tabellen `cs_ticket_counters` en `cs_tickets` plus het
  `Ticket`-model bestaan.
- **Scope:** migration `create_cs_tickets_tables` exact volgens hoofdstuk 10
  en 11 (kolommen, defaults, FK's, indexen, unique op `ticketnummer`);
  model `App\Models\CustomerService\Ticket` met `HasUuids`,
  `protected $table = 'cs_tickets'`, `$fillable`, casts
  (`laatste_bericht_op`/`afgehandeld_op` als datetime, `versie` als integer)
  en relaties `behandelaar()`, `aangemaaktDoor()`.
- **Bestanden (verwacht):**
  `database/migrations/2026_07_XX_000001_create_cs_tickets_tables.php` (nieuw),
  `app/Models/CustomerService/Ticket.php` (nieuw),
  `tests/Unit/CustomerService/TicketModelTest.php` (nieuw).
- **Afhankelijkheden:** CS-001.
- **Acceptatiecriteria:** migration draait op SQLite in-memory op én af
  (`migrate` + `migrate:rollback`); een `Ticket` is via het model aan te maken
  met alle defaults (`status` `nieuw`, `prioriteit` `normaal`, `versie` 1);
  dubbele `ticketnummer`-waarden worden door de databank geweigerd.
- **Tests:** `TicketModelTest` (creatie, defaults, unique constraint,
  relaties); volledige suite groen.
- **Niet in deze taak:** berichten-, notitie- en activiteitentabellen; geen
  services, routes, controllers of frontend; geen seeder.

## CS-003 — Migrations en modellen voor berichten, notities en activiteiten

- **Doel:** `cs_ticket_messages`, `cs_ticket_notes` en `cs_ticket_activities`
  bestaan met bijbehorende modellen en relaties.
- **Scope:** migration `create_cs_ticket_detail_tables` volgens hoofdstuk 10
  en 11 (incl. unique(`ticket_id`,`client_message_id`) en het ontbreken van
  `updated_at` op activiteiten); modellen `TicketMessage`, `TicketNote`,
  `TicketActivity` (laatste met `public $timestamps = false` en handmatige
  `created_at`, of `const UPDATED_AT = null`); hasMany-relaties op `Ticket`.
- **Bestanden (verwacht):**
  `database/migrations/2026_07_XX_000002_create_cs_ticket_detail_tables.php`,
  `app/Models/CustomerService/TicketMessage.php`,
  `app/Models/CustomerService/TicketNote.php`,
  `app/Models/CustomerService/TicketActivity.php`,
  `tests/Unit/CustomerService/TicketRelationsTest.php` (nieuw).
- **Afhankelijkheden:** CS-002.
- **Acceptatiecriteria:** migrate/rollback werken; relaties leveren de juiste
  records in de juiste volgorde; de unieke constraint op
  (`ticket_id`,`client_message_id`) wordt afgedwongen; een activiteit heeft
  geen `updated_at`.
- **Tests:** `TicketRelationsTest`; volledige suite groen.
- **Niet in deze taak:** services, routes, controllers, frontend, seeder.

## CS-004 — TicketNumberService

- **Doel:** betrouwbare, transactieveilige uitgifte van leesbare
  ticketnummers.
- **Scope:** `app/Services/CustomerService/TicketNumberService` met één
  publieke methode `next(): string` conform hoofdstuk 12
  (insert-or-ignore van de jaarrij, `lockForUpdate`, increment, formattering).
  De methode gaat ervan uit dat hij binnen een lopende transactie wordt
  aangeroepen.
- **Bestanden (verwacht):**
  `app/Services/CustomerService/TicketNumberService.php` (nieuw),
  `tests/Unit/CustomerService/TicketNumberServiceTest.php` (nieuw).
- **Afhankelijkheden:** CS-002.
- **Acceptatiecriteria:** eerste nummer `CS-<huidig jaar>-00001`; oplopend bij
  herhaald aanroepen; formattering met voorloopnullen; nieuwe jaarreeks bij
  ander jaar (testbaar door de jaarparameter injecteerbaar te maken, bijv.
  `next(?int $jaar = null)`).
- **Tests:** `TicketNumberServiceTest` (alle criteria); volledige suite groen.
- **Niet in deze taak:** workflowservice, controllers, routes.

## CS-005 — TicketWorkflowService: statusmachine, claim, release, prioriteit

- **Doel:** alle toestandslogica van hoofdstuk 5 en 6 op één plek, volledig
  getest, onafhankelijk van HTTP.
- **Scope:** `app/Services/CustomerService/TicketWorkflowService` met methodes
  `claim(Ticket $ticket, User|string $user, int $versie)`,
  `release(...)`, `changeStatus(...)`, `changePriority(...)`; een eigen
  exceptionklasse `app/Services/CustomerService/TicketConflictException.php`
  met een machinecode (`version_conflict`, `claim_conflict`,
  `invalid_status_transition`, `ticket_afgehandeld`) en de actuele
  tickettoestand; alle mutaties in `DB::transaction` met conditionele
  `WHERE versie = ?`-updates en activiteitregistratie via een
  `TicketActivityLogger` (zelfde taak, `app/Services/CustomerService/`).
- **Bestanden (verwacht):**
  `app/Services/CustomerService/TicketWorkflowService.php`,
  `app/Services/CustomerService/TicketActivityLogger.php`,
  `app/Services/CustomerService/TicketConflictException.php`,
  `tests/Unit/CustomerService/TicketWorkflowServiceTest.php`.
- **Afhankelijkheden:** CS-003, CS-004.
- **Acceptatiecriteria:** volledige overgangsmatrix uit hoofdstuk 5 gedraagt
  zich exact zoals gespecificeerd; claim-/releaseregels uit hoofdstuk 6;
  `versie` +1 bij iedere geslaagde mutatie; verouderde versie werpt
  `TicketConflictException` met code `version_conflict`; `afgehandeld_op`
  wordt correct gezet/geleegd; iedere mutatie logt de juiste activiteit(en).
- **Tests:** `TicketWorkflowServiceTest` (zie hoofdstuk 23); volledige suite
  groen.
- **Niet in deze taak:** berichtenlogica, HTTP-laag, frontend.

## CS-006 — TicketMessageService en notitielogica

- **Doel:** tijdlijnberichten en notities met idempotentie, behandelaarregel
  en automatische statusovergangen.
- **Scope:** `app/Services/CustomerService/TicketMessageService` met
  `addMessage(Ticket, User, array $data)` (richting, inhoud,
  `client_message_id`, versie) en `addNote(Ticket, User, string $inhoud,
  int $versie)`. Regels: uitgaand alleen door behandelaar (`not_assignee`);
  duplicaat `client_message_id` retourneert het bestaande bericht via een
  eigen exception of resultobject (`duplicate_message`); berichten/notities op
  `afgehandeld` weigeren (`ticket_afgehandeld`); inkomend bericht werkt
  `laatste_bericht_op` bij en voert de automatische statusovergang uit
  (hoofdstuk 5); alles transactioneel met versiecontrole en activiteiten.
- **Bestanden (verwacht):**
  `app/Services/CustomerService/TicketMessageService.php`,
  `tests/Unit/CustomerService/TicketMessageServiceTest.php`.
- **Afhankelijkheden:** CS-005.
- **Acceptatiecriteria:** alle regels hierboven aantoonbaar; bij duplicaat
  ontstaat geen tweede rij; automatische overgangen loggen
  `status_gewijzigd` met `"automatisch": true`.
- **Tests:** `TicketMessageServiceTest`; volledige suite groen.
- **Niet in deze taak:** HTTP-laag, frontend.

## CS-007 — Routes, Form Requests en TicketController (lijst, detail, aanmaken)

- **Doel:** de eerste drie endpoints werken end-to-end.
- **Scope:** routeblok `// Customer Service` in `routes/web.php` binnen de
  bestaande `auth.custom`-groep (alle twaalf routes van hoofdstuk 14 mogen in
  deze taak al gedeclareerd worden mits de nog niet gebouwde controllers pas
  in latere taken volgen — declareer anders alleen de drie ticketroutes);
  `TicketController` (`index`, `store`, `show`) onder
  `app/Http/Controllers/Api/CustomerService/`; Form Requests
  `StoreTicketRequest` en `IndexTicketRequest` onder
  `app/Http/Requests/CustomerService/`; een private/gedeelde
  transformatiemethode die het ticketobject van hoofdstuk 15 opbouwt
  (behandelaar en aangemaakt_door als `{id, naam, kleur}`); `index` met alle
  filters, zoeken, sortering en `{data, meta}`-envelop; `store` gebruikt
  `TicketNumberService`, `TicketMessageService`/activiteiten binnen één
  transactie.
- **Bestanden (verwacht):** `routes/web.php` (alleen het nieuwe blok),
  `app/Http/Controllers/Api/CustomerService/TicketController.php`,
  `app/Http/Requests/CustomerService/StoreTicketRequest.php`,
  `app/Http/Requests/CustomerService/IndexTicketRequest.php`,
  `tests/Feature/CustomerService/TicketAuthTest.php`,
  `tests/Feature/CustomerService/TicketCrudTest.php`,
  `tests/Feature/CustomerService/TicketListTest.php`.
- **Afhankelijkheden:** CS-006.
- **Acceptatiecriteria:** de drie endpoints gedragen zich exact volgens
  hoofdstuk 14–17; 401 zonder sessie; 422-validaties per veld; lijstfilters,
  zoeken, sorteringen en paginering volgens hoofdstuk 19.
- **Tests:** de drie genoemde featuretestbestanden; volledige suite groen.
- **Niet in deze taak:** claim-, status-, prioriteit-, bericht-, notitie- en
  activiteitenendpoints; frontend.

## CS-008 — Claim- en release-endpoints

- **Doel:** claimen en vrijgeven via de API.
- **Scope:** `TicketClaimController` (`claim`, `release`), routes, Form
  Request voor `versie`; mapping van `TicketConflictException` naar de
  409-responsevorm van hoofdstuk 17 (implementeer die mapping als kleine
  gedeelde helper of basecontrollermethode zodat CS-009/CS-010 hem
  hergebruiken).
- **Bestanden (verwacht):**
  `app/Http/Controllers/Api/CustomerService/TicketClaimController.php`,
  `app/Http/Requests/CustomerService/TicketVersionRequest.php`,
  `routes/web.php` (twee routes),
  `tests/Feature/CustomerService/TicketClaimTest.php`.
- **Afhankelijkheden:** CS-007.
- **Acceptatiecriteria:** alle claim-/releasescenario's van hoofdstuk 6 en de
  voorbeelden van hoofdstuk 15 kloppen, inclusief 409-bodies met `code` en
  actuele `ticket`.
- **Tests:** `TicketClaimTest`; volledige suite groen.
- **Niet in deze taak:** status-/prioriteitsendpoints, frontend.

## CS-009 — Status- en prioriteitsendpoints

- **Doel:** status- en prioriteitswijziging via de API.
- **Scope:** `TicketStatusController`, `TicketPriorityController`, Form
  Requests (`UpdateTicketStatusRequest`, `UpdateTicketPriorityRequest`),
  routes; hergebruik van de 409-mapping uit CS-008.
- **Bestanden (verwacht):** twee controllers, twee Form Requests,
  `routes/web.php` (twee routes),
  `tests/Feature/CustomerService/TicketStatusTest.php`,
  `tests/Feature/CustomerService/TicketPriorityTest.php`.
- **Afhankelijkheden:** CS-008.
- **Acceptatiecriteria:** volledige overgangsmatrix via HTTP; verboden
  overgangen en versieconflicten geven de gespecificeerde 409-bodies;
  heropenen wijst behandelaar toe; activiteiten worden gelogd.
- **Tests:** beide featuretestbestanden; volledige suite groen.
- **Niet in deze taak:** berichten-/notitie-endpoints, frontend.

## CS-010 — Berichten-endpoints (tijdlijn)

- **Doel:** tijdlijn ophalen en handmatige testberichten plaatsen.
- **Scope:** `TicketMessageController` (`index`, `store`),
  `StoreTicketMessageRequest`, routes; `index` levert berichten oplopend op
  `created_at` met auteur als `{id, naam, kleur}`; `store` retourneert
  `{bericht, ticket}` conform hoofdstuk 15 en mapt alle conflicten
  (`not_assignee`, `duplicate_message`, `ticket_afgehandeld`,
  `version_conflict`).
- **Bestanden (verwacht):**
  `app/Http/Controllers/Api/CustomerService/TicketMessageController.php`,
  `app/Http/Requests/CustomerService/StoreTicketMessageRequest.php`,
  `routes/web.php` (twee routes),
  `tests/Feature/CustomerService/TicketMessageTest.php`.
- **Afhankelijkheden:** CS-009.
- **Acceptatiecriteria:** alle scenario's uit hoofdstuk 23, punt 7; het
  duplicaatscenario maakt aantoonbaar geen tweede databankrij aan.
- **Tests:** `TicketMessageTest`; volledige suite groen.
- **Niet in deze taak:** notities/activiteiten-endpoints, frontend.

## CS-011 — Notitie- en activiteiten-endpoints

- **Doel:** interne notities en de activiteitenhistorie via de API.
- **Scope:** `TicketNoteController` (`index`, `store`),
  `StoreTicketNoteRequest`, `TicketActivityController` (`index`, aflopend
  gesorteerd, met gebruiker als `{id, naam, kleur}` of `null`), routes.
- **Bestanden (verwacht):** twee controllers, één Form Request,
  `routes/web.php` (drie routes),
  `tests/Feature/CustomerService/TicketNoteTest.php`,
  `tests/Feature/CustomerService/TicketActivityTest.php`.
- **Afhankelijkheden:** CS-010.
- **Acceptatiecriteria:** notities plaatsbaar door iedere ingelogde gebruiker
  behalve op afgehandelde tickets; historie compleet en chronologisch
  aflopend; er bestaan geen muterende routes voor activiteiten.
- **Tests:** beide featuretestbestanden; volledige suite groen. Hiermee is de
  API compleet en gaat de frontend van start.
- **Niet in deze taak:** seeder, frontend.

## CS-012 — Lokale testdataseeder

- **Doel:** met één commando een realistische lokale testset.
- **Scope:** `database/seeders/CustomerServiceTestSeeder.php`: circa 15–20
  fictieve tickets verspreid over alle statussen en prioriteiten, met
  wisselende behandelaars (bestaande users), meerdere berichten per ticket in
  beide richtingen, notities en een kloppend activiteitenlog (bouw de data via
  de services, niet met directe inserts, zodat versies, tellers en activiteiten
  consistent zijn). Klantnamen herkenbaar fictief, e-mail op `example.test`.
  Idempotent; niet opgenomen in `DatabaseSeeder`.
- **Bestanden (verwacht):**
  `database/seeders/CustomerServiceTestSeeder.php` (nieuw),
  `tests/Feature/CustomerService/TestSeederTest.php` (nieuw; draait de seeder
  in-memory en controleert de invarianten).
- **Afhankelijkheden:** CS-011.
- **Acceptatiecriteria:** seeder draait foutloos en idempotent; alle tickets
  hebben geldige ticketnummers, versies ≥ 1 en een sluitend activiteitenlog;
  `DatabaseSeeder` is ongewijzigd.
- **Tests:** `TestSeederTest`; volledige suite groen.
- **Niet in deze taak:** frontend; geen uitvoering van de seeder buiten tests.

## CS-013 — Frontendskelet: navigatie, view en lijstweergave

- **Doel:** de module is zichtbaar in de SPA en toont de ticketlijst.
- **Scope:** sidebaritem, viewcontainer en scripttag in `public/index.html`
  (hoofdstuk 18); nieuwe case plus cleanup-aanroep in `navigateTo()` in
  `public/js/app.js`; nieuw `public/js/customer-service.js` met
  `renderCustomerService()`, de driedelige layoutopbouw, het laden en renderen
  van de ticketlijst (status-/prioriteits-/behandelaarbadges, tijdstip
  laatste bericht) en paginering; `cs-`-componentstijlen onderaan
  `public/css/style.css`; loading- en lege staten van hoofdstuk 20 voor het
  lijstpaneel; alle output via `escHtml()`.
- **Bestanden (verwacht):** `public/index.html` (drie kleine toevoegingen),
  `public/js/app.js` (case + cleanup), `public/js/customer-service.js`
  (nieuw), `public/css/style.css` (toevoegingen onderaan).
- **Afhankelijkheden:** CS-011 (API compleet); CS-012 aanbevolen voor
  handmatige verificatie.
- **Acceptatiecriteria:** navigatie-item werkt; lijst laadt met paginering;
  lege staat en laadstatus zichtbaar; geen consolefouten; bestaande views
  blijven volledig functioneel.
- **Tests:** `composer test` groen (regressie); handmatige verificatie in de
  browser met de seederdata volgens de acceptatiecriteria van hoofdstuk 24
  ("Inbox / lijst").
- **Niet in deze taak:** detailpaneel, acties, filters/zoeken (alleen de
  default lijst), polling.

## CS-014 — Ticketdetail: kop, tijdlijn, notities, historie (alleen-lezen)

- **Doel:** een ticket aanklikken toont het volledige detailpaneel.
- **Scope:** in `public/js/customer-service.js`: detail laden
  (ticket + messages + notes + activities), kopweergave (nummer, onderwerp,
  klant, status-/prioriteitsbadge, behandelaar), tabs Tijdlijn/Notities/
  Historie, chronologische tijdlijn met richtingweergave, placeholder- en
  laadstatus van het detailpaneel; bijbehorende CSS.
- **Bestanden (verwacht):** `public/js/customer-service.js`,
  `public/css/style.css`.
- **Afhankelijkheden:** CS-013.
- **Acceptatiecriteria:** detailweergave klopt met de seederdata; tabs
  schakelen zonder herladen van de pagina; XSS-veilig (testbericht met
  `<script>` wordt als tekst getoond).
- **Tests:** `composer test` groen; handmatige verificatie.
- **Niet in deze taak:** muterende acties, composers, polling, conflicten.

## CS-015 — Acties: nieuw ticket, claimen/vrijgeven, status, prioriteit, berichten, notities

- **Doel:** alle muterende workflows werken vanuit de UI, inclusief
  conflictafhandeling.
- **Scope:** modal "Nieuw testticket"; claim-/vrijgeefknop; status- en
  prioriteitsselectie die alleen toegestane overgangen aanbiedt; composer voor
  inkomende/uitgaande testberichten met `client_message_id`
  (`crypto.randomUUID()`), disabled-status plus uitleg voor niet-behandelaars
  en dubbelkliksbescherming; notitiecomposer; uniforme 409-afhandeling
  (banner + "Vernieuwen", composertekst blijft staan); toasts bij succes;
  na iedere actie ticketkop, lijstregel en relevante tab verversen op basis
  van de serverrespons.
- **Bestanden (verwacht):** `public/js/customer-service.js`,
  `public/css/style.css`.
- **Afhankelijkheden:** CS-014.
- **Acceptatiecriteria:** alle acceptatiecriteria van hoofdstuk 24 voor
  aanmaken, claimen/vrijgeven, status/prioriteit, berichten en notities;
  handmatig conflict naspelen met twee browsersessies toont de 409-banner en
  verliest geen data.
- **Tests:** `composer test` groen; handmatig tweesessiescenario.
- **Niet in deze taak:** filters/zoeken/sorteren, detailpolling.

## CS-016 — Filters, zoeken, sorteren en detailpolling

- **Doel:** de inbox is volledig doorzoek- en filterbaar en signaleert
  wijzigingen van collega's.
- **Scope:** filterpaneel (status, prioriteit, behandelaar, "Mijn tickets",
  "Niet toegewezen"), zoekveld met 300 ms debounce, sorteerkeuze; alle
  parameters server-side via de bestaande lijst-API; lege staat "geen
  resultaten" met "Filters wissen"; detailpolling elke 15 seconden met de
  banner-/stille-verversingslogica van hoofdstuk 7; polling stopt bij het
  verlaten van de view (cleanup uit CS-013).
- **Bestanden (verwacht):** `public/js/customer-service.js`,
  `public/css/style.css`.
- **Afhankelijkheden:** CS-015.
- **Acceptatiecriteria:** acceptatiecriteria hoofdstuk 24 ("Zoeken, filteren,
  sorteren" en "Concurrency"); geen polling-timers lekken bij navigeren
  tussen views.
- **Tests:** `composer test` groen; handmatige verificatie inclusief
  tweesessie-pollingtest.
- **Niet in deze taak:** nieuwe API-wijzigingen; notificatiebadges.

## CS-017 — Integrale afronding en kwaliteitscontrole

- **Doel:** de volledige fase 1 is aantoonbaar af en regressievrij.
- **Scope:** volledige testrun (`composer test`); `vendor/bin/pint --test`
  op alle nieuwe/gewijzigde PHP-bestanden (en fix waar nodig); handmatige
  QA-doorloop van de volledige workflow uit hoofdstuk 3 met de seederdata;
  controle dat alle punten van hoofdstuk 24 zijn afgevinkt en dat niets uit
  hoofdstuk 25 is meegebouwd; eventuele kleine fixes die uit de QA komen
  (uitsluitend binnen de module).
- **Bestanden (verwacht):** alleen fixbestanden binnen de module, indien
  nodig.
- **Afhankelijkheden:** CS-016.
- **Acceptatiecriteria:** alle tests groen; Pint schoon; QA-checklist volledig
  afgevinkt; working tree schoon na de afsluitende commit.
- **Tests:** volledige suite plus handmatige QA.
- **Niet in deze taak:** nieuwe functionaliteit van welke aard dan ook.

---

Einde specificatie. Vragen of afwijkingen tijdens de bouw: leg ze vast als
opmerking bij de betreffende CS-taak en escaleer scopewijzigingen vóór
implementatie.

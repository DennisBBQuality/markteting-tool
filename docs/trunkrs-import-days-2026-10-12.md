# Trunkrs importdagen — 12 oktober 2026

## Opdracht en resultaat

Dennis heeft expliciet opgedragen zondag en maandag over te slaan en uitsluitend dinsdag–zaterdag automatisch te importeren. Kimberley hoeft hierover geen mail te krijgen. De eerdere open beleidsvraag over deze importdagen is daarmee beantwoord; historische lege maandagmails zijn geen reden om de automatische maandagimport te behouden.

De wijziging staat op de afzonderlijke lokale branch `codex/trunkrs-tuesday-saturday`, worktree `trunkrs-import-days`, vanaf actuele `origin/main` (`4cd58f6ad48925421d0f076e78052ef0c2ff1ebc`). PR #42 en de afzonderlijke weekenddiagnose zijn niet meegenomen. De overige open PR #48 is niet aangeraakt.

## Beheer van de planning

| Onderdeel | Bron | Wijziging |
| --- | --- | --- |
| Actieve cloudroute | `.github/workflows/trunkrs-sync.yml` op GitHub-defaultbranch `main` | Beide UTC-crons beperkt tot `2-6`; bestaande minuten en uren behouden |
| Bewaking bij vertraagde GitHub-start | `scripts/trunkrs-cloud-check.py` op dezelfde branch | Geplande start op actuele zondag/maandag in Europe/Amsterdam stopt zonder identiteit, mailboxaanroep of rapportverificatie; de bestaande grens vóór 06:00 blijft |
| Optionele hostingroute | `routes/console.php`, uitgevoerd door Laravel-scheduler als hosting die aanroept | Cron `15,25,35,45,55 6-9 * * 2-6`, timezone Europe/Amsterdam, bestaande overlapbeveiliging |
| Dashboard | `TrunkrsDashboard` op de applicatiehosting | Verwacht de bezorgdatum behorend bij de laatste dinsdag–zaterdag waarvan de 06:30-deadline is verstreken; geen extra zondag-/maandagrapport verwacht |

Alle UTC-starturen vallen in zomer en winter op dezelfde Nederlandse kalenderdag. De extra lokale-dagcontrole dekt uitgestelde GitHub-starts. Handmatige `workflow_dispatch`, de webknop en direct `trunkrs:sync` blijven beschikbaar voor expliciet herstel. Import- en rapportverificatievoorwaarden blijven daarbij intact. Een lege CSV blijft ongedateerd; er worden geen historische rapporten aangepast. De importer mag bij een volgende toegestane of handmatige scan bestaande oudere mail verwerken volgens zijn bestaande werkwijze.

## Tests

Gerichte tests controleren alle weekdagen, zomer/winter, beide DST-weekenden, de Nederlandse middernacht, de bestaande ochtendgrens, automatische skips zonder externe aanroep of rapportfout, handmatig herstel op zondag/maandag en dinsdag–zaterdag met echte controleflow. De hostingtest leest de geregistreerde Laravel-scheduler. Dashboardtests bewaken zondag/maandag, dinsdag vóór en vanaf 06:30, en een daadwerkelijk te oud rapport dat een waarschuwing moet houden.

- Volledige PHP-suite: **438 tests / 6.254 assertions**, geslaagd met `php -d memory_limit=512M vendor/bin/phpunit` (40,908 seconden).
- Gerichte Trunkrs-selectie: **59 tests / 683 assertions** geslaagd.
- Python-runner: **8 tests** geslaagd.
- JavaScript: alle **134 tests** geslaagd met uitsluitend een lokale loopback-testserver.
- Pint op gewijzigde PHP-bestanden en `git diff --check`: geslaagd.

Tests gebruiken synthetische gegevens, SQLite `:memory:` en nagebootste externe verzoeken; geen bestaande database of `.env` gebruikt. De hogere geheugenlimiet geldt alleen voor het PHP-testproces.

## Activeringsstatus en resterende stap

**Nog uitsluitend lokaal; de nieuwe importdagen zijn niet actief in productie.** GitHub `main` bevat bij deze controle nog de dagelijkse crons. De bestaande GitHub-route heeft aantoonbare geplande runs; de daadwerkelijke activatie van de optionele hosting-cron is niet uitgelezen. Alleen de Laravel-broncode bewijst die hostingactivatie niet.

Publicatie van uitsluitend deze branch en een gerichte PR naar `main` zijn nog nodig. De bekende merge naar `main` start ook de bestaande automatische live-uitrol; die consequential handeling is in deze lokale opdracht niet uitgevoerd. Geen brede merge van PR #42, geen workflow-rerun, hostingconfiguratie, credentials, securitywijziging of databasewijziging. Na afzonderlijke toestemming voor de gerichte publicatie/merge/uitrol: controleer de daadwerkelijk gepubliceerde workflow en gedeployde scheduler; een volgende normale dinsdag–zaterdag-run moet de import bevestigen. Zondag/maandag geen geplande GitHub-run verwachten. Een toekomstige vertraagde start moet expliciet als overgeslagen eindigen en mag geen succesvolle import claimen.

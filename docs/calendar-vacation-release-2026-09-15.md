# Dashboardkalender: aanmaken en vakantie

## Wijziging

- Knop Nieuw item en klikken op een leeg tijdvak in de dashboardkalender.
- Vinkje Vakantie in het gedeelde aanmaak- en bewerkformulier.
- Expliciete keuze wordt bewaard; oude vakantietitels blijven herkenbaar zolang geen keuze is opgeslagen.
- Vakantie staat in de strook Afwezig, zonder bestaande datums of tijden aan te passen.
- Dubbel opslaan wordt geblokkeerd; mislukte aanvragen laten het formulier open.

## Veilige uitrol

Deze release heeft één nieuwe nullable boolean-kolom nodig. Dennis heeft op 15 september bevestigd dat GitHub automatisch de live-uitrol start. Gebruik die bestaande route (zie AGENTS.md), geen nieuwe hosting. De meegeleverde migratie is het enige nieuwe databaseschema in deze release. Als de automatische uitrol deze niet uitvoert, is dit het gerichte herstelcommando voor de bevoegde hostingbeheerder, na een herstelbare productieback-up:

```sh
php artisan migrate --path=database/migrations/2026_09_15_080000_add_is_vacation_to_calendar_items.php --force
```

Gebruik geen fresh, refresh, seed, lokale databasekopie of import. Deze migratie voegt uitsluitend `calendar_items.is_vacation` toe en doet geen backfill, update of verwijdering van bestaande records. Een gewone terugrol van applicatiecode kan de nieuwe kolom laten staan.

Verifieer dat de kolom bestaat en de migratie geregistreerd is; vergelijk bestaande kalenderwaarden en taken, projecten en notities met de back-up. Controleer daarna de uitgeleverde assetversies `20260915-1`. Plaats geen fictieve afspraken in productie.

## Uitgevoerde controles

- 250 Laravel-tests (2588 assertions) en 49 JavaScript-tests geslaagd.
- Productie-build, PHP-opmaak en diff-controle geslaagd.
- Migratietest vergelijkt bestaande kalenderwaarden en overige planningsgegevens voor/na de upgrade; een tweede uitvoering verandert niets.
- Lokale SQLite-database vóór migratie privé geback-upt; bestaande gegevens na migratie exact gelijk.
- Geïsoleerde browsertest: aanmaken, vakantievinkje opslaan, terugopenen, uitvinken en herladen geslaagd.
- Klik op woensdag 10:00 opent het formulier met 10:00–11:00, zonder naar de aparte kalender te navigeren.

## Nog niet uitgevoerd

De hostingroute is bevestigd: de bestaande automatische GitHub-uitrol. Productiemigratie en live-activering moeten na de release apart worden geverifieerd voor zover de beschikbare toegang dit toelaat. De lokale controles wijzigen geen productiegegevens.

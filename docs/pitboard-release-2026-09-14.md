# Pitboard-release — 14 september 2026

## Wijzigingen

- Persoonlijk instelbare dashboardtegels: kleinere weekkalender, open taken per persoon, projecten zonder voortgangsbalk, persoonlijke notities en Trunkrs-status.
- De overbodige knop Kalender openen rechtsboven is verwijderd. Kalender blijft bereikbaar via het bestaande menu.
- Modelkeuze voor productfoto's, gecontroleerde modelontdekking en verbeterde bereidings-/stoofprofielen.
- Klantenservicenavigatie gepauzeerd zonder bestaande ticketgegevens te verwijderen.
- Trunkrs-serververwerking voorbereid maar standaard uitgeschakeld; geen mailboxactivering bij deze release.

## Gegevensbehoud

Deze release is uitsluitend applicatiecode. Geen lokale database, uploads, API-sleutels, `.env`, sessies of cachebestanden publiceren. Productie-APP_KEY, databaseverbinding en opslag blijven ongewijzigd. Er zijn geen wijzigingen aan bestaande migrations, seeders, installatie- of deployscripts.

Drie aanvullende migrations voegen uitsluitend vier nieuwe tabellen toe:

1. `2026_09_11_100000_create_product_image_model_settings_table.php`: `product_image_model_settings`.
2. `2026_09_11_160000_create_trunkrs_reports_tables.php`: `trunkrs_connections`, `trunkrs_reports`.
3. `2026_09_14_140000_create_dashboard_preferences_table.php`: `dashboard_preferences`.

Geen reset, refresh, wipe, seed, gegevensimport of rollback uitvoeren. Oude reeds uitgevoerde migrations niet opnieuw uitvoeren. Bij onverwachte andere pending migrations afzonderlijk beoordelen. Bij terugzetten van code de aanvullende tabellen behouden; geen database-rollback uitvoeren.

De behoudtest bouwt uitsluitend een in-memory database met fictieve projecten, taken, kalenderitems, notities, gebruikers en toewijzingen. Na de uitbreidingen worden inhoud, tijdstempels, relaties en tabeldefinities exact vergeleken; een tweede migratieronde is eveneens zonder wijzigingen. Dit is geen controle of back-up van de werkelijke productiedatabase.

## Verificatie vóór publicatie

- 247 PHP-tests, 2552 assertions geslaagd.
- 39 JavaScript-tests geslaagd, inclusief ontbreken van de verwijderde kalenderknop.
- Frontend-build, syntaxis en diffcontrole geslaagd.
- Dashboard eerder interactief getest op desktop en mobiel met uitsluitend fictieve gegevens.
- GitHub-basis: `0d3689cdc894adbc8e1913622a2f48e004d8fe36`.

De opdrachtgever heeft de bestaande GitHub-uitrolroute expliciet aangewezen. Rechtstreekse hostingtoegang, productieback-up en exacte productie-inhoud zijn niet beschikbaar voor verificatie. Na uitrol de publieke assets controleren; de eigen ingelogde sessie blijft nodig voor volledige gebruikersacceptatie. Er worden geen fictieve records aangemaakt in productie.

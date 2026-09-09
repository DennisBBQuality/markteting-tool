# Live-update Productstudio — veilige voorbereiding

Status bij releasevoorbereiding: de opdrachtgever heeft `https://planning.bbquality.nl/` bevestigd en opdracht gegeven deze versie via de bestaande GitHub-pushroute te publiceren. Alleen code wordt gepubliceerd; geen serverconfiguratie of productiegegevens worden vervangen. Rechtstreekse servertoegang en een productieback-up zijn niet beschikbaar geverifieerd. De controle is daarom code-/migratiegericht, met een read-only UI-vergelijking; geen claim van een volledige productie-databasesnapshot.

## Randvoorwaarde van BBQuality

De bestaande productiegegevens in Projecten, Taken en Kalender moeten volledig behouden blijven, inclusief beschrijvingen, status, datums, volgorde, gekoppelde gebruikers en onderlinge relaties. Ook gebruikers, bijlagen en overige bestaande bedrijfsgegevens niet vervangen door lokale data.

Dit is een code-update op de bestaande omgeving, geen verhuizing van de lokale database. Geen testaccounts, lokale dossiers, uploads, sessies, cachebestanden, API-sleutels of `.env` meesturen. De bestaande productie-`APP_KEY` moet behouden blijven, onder meer vanwege de versleutelde AI-koppeling.

## Lokale controle op 9 september

- Repository: `DennisBBQuality/markteting-tool`.
- Bestaande remote `main`: `3ac7857ee6ce3528a1415a521150ffae70341ace` op het moment van controle. Dit is **niet** automatisch bewijs van de versie die live draait.
- Nieuwe code staat lokaal op `feature/product-dossier-test`; nog niet gepubliceerd.
- Project-, taak- en kalendercontrollers, modellen en afzonderlijke frontendmodules zijn niet gewijzigd ten opzichte van deze basis. Er zijn wel wijzigingen in gedeelde navigatie, foutafhandeling en CSS; die vereisen regressiecontrole.
- Er zijn zes nieuwe Productstudio-migrations. Hun `up()`-routes raken uitsluitend `product_dossiers`, `product_dossier_options` en `product_dossier_assets`, plus de Laravel-migratieregistratie.
- Extra test `ProductStudioMigrationPreservationTest` bouwt een aparte in-memory testdatabase op, vult bestaande projecten, taken, kalenderitems, gebruikers en toewijzingen en voert de zes nieuwe migrations uit. Inhoud, relaties én tabeldefinities worden vóór/na exact vergeleken. Ook opnieuw uitvoeren wordt getest. Er zijn geen productiegegevens gekopieerd naar deze tests.
- Verificatie vóór de release: **172 backendtests geslaagd (1.327 assertions), 8 JavaScript-tests geslaagd**, Pint en diffcontrole geslaagd. Twee extra regressies controleren deferred verwerking en privé-media na vervanging van een serverbestandssysteem. De zes uitbreidingen behouden in de migratietest bestaande planningsinhoud, relaties en schema's exact.
- De liveomgeving antwoordt met HTTP 200; de bestaande Kalender is ingelogd read-only bekeken. De browser laat nog geen Productstudio zien. Dit is geen vervanging voor een databaseback-up of controle van de serverversie.

## Exact te beoordelen nieuwe migrations

1. `2026_09_04_090000_create_product_dossiers_table.php`
2. `2026_09_04_100000_create_product_dossier_options_table.php`
3. `2026_09_04_110000_align_product_dossier_options_with_bbquality.php`
4. `2026_09_07_100000_add_generation_to_product_dossiers.php`
5. `2026_09_07_110000_add_expert_assets_to_product_dossiers.php`
6. `2026_09_09_100000_create_product_dossier_assets_table.php`

Controleer op de live server eerst de actuele migratieregistratie. Als daar andere, oudere migrations openstaan: stoppen en afzonderlijk beoordelen. De repository bevat bijvoorbeeld historische migrations die de oude chat verwijderen en een taaktoewijzingstabel herbouwen; die mogen niet blind opnieuw of als onbedoelde bijvangst worden uitgevoerd.

## Uitvoeringsvolgorde zodra toegang bevestigd is

1. Live URL, juiste applicatie, serverversie en deploy-trigger read-only vaststellen. Controleren of push/merge automatisch publiceert en welke commando's daarbij draaien.
2. Productiedatabase, uploads en noodzakelijke configuratie via de bestaande beveiligde back-upvoorziening veiligstellen. Back-up buiten de publieke webmap houden; herstelroute bevestigen. Geen productiegegevens naar Git of openbare artifacts kopiëren.
3. Beschermde gegevens vóór/na op de server vergelijken zonder persoonlijke inhoud naar de chat te sturen. Alleen aantallen zijn onvoldoende: controleer ook inhoud, relaties en tijdstempels. Houd rekening met gelijktijdige wijzigingen door medewerkers; gebruik waar nodig een afgestemd kort schrijfvenster.
4. Alleen de geteste applicatiecode publiceren. Bestaande databaseverbinding, `APP_KEY`, sleutels en opslag behouden. Geen databasebestanden, testseeding, verse installatie of gegevensimport gebruiken.
5. Alleen de hierboven beoordeelde, daadwerkelijk ontbrekende migrations uitvoeren. Geen reset, `migrate:fresh`, `migrate:refresh`, rollback, `db:wipe`, `db:seed` of `import:old-data`.
6. Productstudio gebruikt in productie standaard `deferred`, zoals de bestaande live beeldgeneratie. Er is geen nieuwe worker nodig. Een expliciete `PRODUCT_CONTENT_QUEUE_CONNECTION=database` heeft voorrang en vereist wel een worker voor `product-content` (540 seconden, retry-after groter dan timeout). Bestaande planning-workers en hostinginstellingen niet vervangen. Deferred bezet een webproces: bij groei is een aparte worker een operationele vervolgstap.
7. Nieuwe privé-media staan in een aparte databasetabel en zijn onafhankelijk van tijdelijke serveropslag; bestaande lokale concepten behouden hun bestandspaden. Beschikbare PHP/GD-WEBP-ondersteuning controleren.
8. Beschermde database-inhoud en bestaande Projecten/Taken/Kalender read-only verifiëren. Geen fictieve projecten, taken of agenda-afspraken in productie aanmaken.
9. Productstudio-routes, keuzelijsten, achtergrondverwerking en assets controleren. Onbedoelde mutaties, ontbrekende opslag of een onwerkende worker blokkeren de oplevering.

## Terugval

Bij een fout eerst terug naar de vorige codeversie via de bestaande hostingreleasefunctie. De nieuwe, aanvullende tabellen mogen blijven staan; een automatische database-rollback kan juist nieuwe dossiers of bedrijfsgegevens verliezen. Een volledige databaseherstelactie is alleen voor aantoonbare datacorruptie, na afstemming over eventuele nieuwe productie-invoer sinds de back-up.

## Grenzen van verificatie

De bestaande productie-back-upvoorziening, servercommando's en volledige database-inhoud zijn zonder hostingtoegang niet rechtstreeks te controleren. Een geslaagde lokale behoudtest en gelijke scherminhoud zijn geen herstelbare back-up. De bestaande GitHub-deployroute wordt op expliciet verzoek gebruikt; er worden geen reset-, seed-, import- of rollbackcommando's toegevoegd of uitgevoerd.

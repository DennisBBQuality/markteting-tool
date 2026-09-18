# Gerichte SEO-upgrade via de bestaande GitHub-uitrol

De release voegt aan Composer `post-install-cmd` één Laravel-command toe: `pitboard:upgrade-image-seo-storage`. Een normale `composer install` in de bestaande deployment voert daardoor uitsluitend de ontbrekende migratie `2026_09_18_100000_create_product_image_download_names_table.php` uit.

- Geen productieconfiguratie, hosting, credentials of workflow vervangen.
- Geen algemene `migrate`, seed, import, reset, rollback of gegevensbackfill.
- Geen publieke onderhoudsroute. Aanvulling na livecontrole: uitsluitend een geautoriseerde SEO-schrijfactie of SEO-achtergrondtaak mag dezelfde gerichte migratie uitvoeren, vóór de eigen schrijftransactie/AI-aanroep en onder een gedeelde database-cachelock. GET-verzoeken en het normale opstarten van de app migreren niets.
- Alleen de bestaande additieve Laravel-migratie; daarna controle van kolommen en migratieregistratie.
- Bestaande tabel + migratieregistratie: niets uitvoeren. Tegenstrijdige toestand: stoppen, niets repareren of verwijderen.
- Verse installatie zonder applicatietabellen: overslaan; de normale installatie blijft verantwoordelijk voor het beginschema.
- Mislukte verbinding of migratie levert geen vals succes.

Test: bestaande inhoud en schema van alle tabellen vóór/na vergelijken, inclusief fictieve planning, afbeeldingen en metadata; herhaalde uitvoering; andere openstaande migratie bewust niet uitvoeren; tegenstrijdige migratieregistratie afwijzen.

## Vastgestelde uitrolroute en vervolg

GitHub-webhookleveringen op 18 september zijn succesvol (HTTP 200) afgeleverd bij SpinupWP. Een merge naar main blijft de bestaande uitrolroute. Na de Composer-hookrelease meldde Live nog steeds de ontbrekende tabel; alleen een push/afgeleverde webhook bewijst geen uitgevoerde installatiestap. Daarom mogen geautoriseerde SEO-schrijfacties dezelfde exact afgebakende migratie aanroepen. Zowel Composer als SEO gebruiken dezelfde database-lock. Een inconsistente migratieregistratie wordt nooit automatisch herschreven.

Een link `?image_request=<uuid>` opent een bestaande fotoset via de bestaande eigenaarscontrole. Dit start geen AI-aanvraag en verandert geen foto's of SEO. Hiermee is een mislukte set terug te openen wanneer het eerdere tabblad is gesloten.

Bronnen: [Composer script events](https://getcomposer.org/doc/articles/scripts.md) en [Laravel migrations](https://laravel.com/docs/12.x/migrations). Of de externe deployment Composer met scripts uitvoert, wordt pas bewezen door de live opslagcontrole na deze release. Een push alleen is geen voltooiingsbewijs.

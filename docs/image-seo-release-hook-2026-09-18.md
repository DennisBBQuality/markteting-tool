# Gerichte SEO-upgrade via de bestaande GitHub-uitrol

De release voegt aan Composer `post-install-cmd` één Laravel-command toe: `pitboard:upgrade-image-seo-storage`. Een normale `composer install` in de bestaande deployment voert daardoor uitsluitend de ontbrekende migratie `2026_09_18_100000_create_product_image_download_names_table.php` uit.

- Geen productieconfiguratie, hosting, credentials of workflow vervangen.
- Geen algemene `migrate`, seed, import, reset, rollback of gegevensbackfill.
- Geen schemawijziging tijdens een webrequest, geen publieke onderhoudsroute.
- Alleen de bestaande additieve Laravel-migratie; daarna controle van kolommen en migratieregistratie.
- Bestaande tabel + migratieregistratie: niets uitvoeren. Tegenstrijdige toestand: stoppen, niets repareren of verwijderen.
- Verse installatie zonder applicatietabellen: overslaan; de normale installatie blijft verantwoordelijk voor het beginschema.
- Mislukte verbinding of migratie levert geen vals succes.

Test: bestaande inhoud en schema van alle tabellen vóór/na vergelijken, inclusief fictieve planning, afbeeldingen en metadata; herhaalde uitvoering; andere openstaande migratie bewust niet uitvoeren; tegenstrijdige migratieregistratie afwijzen.

Bronnen: [Composer script events](https://getcomposer.org/doc/articles/scripts.md) en [Laravel migrations](https://laravel.com/docs/12.x/migrations). Of de externe deployment Composer met scripts uitvoert, wordt pas bewezen door de live opslagcontrole na deze release. Een push alleen is geen voltooiingsbewijs.

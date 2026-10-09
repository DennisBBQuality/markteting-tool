# Pitboard → WordPress: fase 1 (alleen media)

De meegeleverde **BBQuality Pitboard Media Bridge 1.0.0** is een zelfstandige, beperkte uitbreiding naast BBQuality Connect 0.1.1. De bestaande plugin wordt niet overschreven. Deze nieuwe brug gebruikt `/wp-json/bbquality-connect/v2`, niet het oudere, niet-atomaire v1-uploadpad.

## Installatie en veilige activering

1. Installeer de map `pitboard-media-bridge` in WordPress `wp-content/plugins` en activeer de plugin. Eerst uitsluitend op `https://bbquality.test`.
2. Geef de speciaal aangemaakte gebruiker `pitboard` de rol **Pitboard — alleen media**. Deze heeft alleen `read`, `upload_files` en de specifieke bridge-capability. Geen beheerder/editor, geen producten/orders/pagina's. Bestaande eigen rechten/plugins kunnen aanvullend gedrag veroorzaken; controleer dit op de doelsite.
3. Maak **zelf** een WordPress-applicatiewachtwoord op het profiel van die gebruiker; niet het gewone inlogwachtwoord. Gebruik een aparte gebruiker/sleutel per installatie.
4. In het lokale Pitboard: **Instellingen → WordPress — mediatheek**. Vul gebruikersnaam en applicatiewachtwoord in, bevestig de uitleg en sla op. Wachtwoord is versleuteld in de database, nooit in Git of teruggestuurd in een API-response. Beveilig APP_KEY en databaseback-ups afzonderlijk.
5. Klik **Verbinding en rechten testen**. De test doet alleen GET `/connection` en valideert identiteit, doelsite, protocol en rechten; dit is géén uploadtest.
6. Na geslaagde test: vink uploadknoppen activeren aan en sla opnieuw op. Wijzigen van gebruiker/wachtwoord of een mislukte verbindingstest schakelt uploads uit.
7. Gebruik eerst een kunstmatige foto zonder bedrijfs-/persoonsgegevens. Optioneel maakt `php artisan pitboard:wordpress-media-fixture` zo'n foto voor het bestaande lokale testaccount Admin. De opdracht weigert buiten APP_ENV=local. Hij roept geen AI aan en verzendt niets.
8. Controleer foto/etiketten én SEO; keur de specifieke versie goed, kies **Naar mediatheek**, en controleer het resultaat in WordPress. Test daarna een SEO-wijziging op hetzelfde attachment en herhaling zonder dubbele upload.

De echte webshop blijft uitgeschakeld totdat deze lokale end-to-end-test geslaagd is en de live WordPress-installatie afzonderlijk is voorbereid. Uitrol van Pitboard installeert deze plugin **niet** automatisch op WordPress.

## Grenzen en beveiliging

- Lokale/test-apps hebben uitsluitend `https://bbquality.test` als doel; andere omgevingen uitsluitend `https://www.bbquality.nl`. Er zijn geen vrij invoerbare doelen, redirects of uitgeschakelde certificaatcontroles. Een live server kan geen `.test`-site op Dennis' Mac benaderen. Installeer een vertrouwde lokale CA waar nodig; gebruik nooit `verify=false`.
- Elke omgeving heeft een eigen verbinding, secret en transferhistorie. Een testupload telt nooit als live-upload. De verbindings-ID zit in de bronverwijzing; laat die bij back-up/herstel intact.
- Alleen admins beheren credentials; alleen de eigenaar van de fotoset kan goedkeuren/uploaden. Goedkeuring bindt foto-ID, versie, originele bytes, SEO-revisie en alle vijf SEO-velden. Veranderen daarvan vereist opnieuw goedkeuren.
- WEBP met kwaliteit 85, dimensies behouden; maximaal 15 MiB voor de bridge. Webserver/PHP uploadgrenzen moeten eveneens hoog genoeg zijn; deze code wijzigt die configuratie niet.
- Foto's zijn in de mediatheek en worden niet automatisch aan producten gekoppeld. **Op productie zijn media-URL's openbaar**, ook zonder productplaatsing.
- Geen productteksten, prijzen, voorraad, klanten, bestellingen, plaatsingen of publicaties via deze brug. Fase 2 is niet geïmplementeerd.
- V1 van BBQuality Connect blijft bestaan, maar de beperkte media-rol heeft geen `edit_posts`/`edit_post` om daar producten/concepten te wijzigen. Gebruik alleen V2 voor Pitboard.
- De in-browser batch verstuurt opeenvolgend en stopt na de eerste onzekere uitkomst. Sluiten van de browser stopt nog niet gestarte batch-items; een reeds verstuurd verzoek kan doorgaan.
- Een lokale claim blokkeert dubbele klikken. WordPress gebruikt een MySQL/MariaDB advisory lock op de vaste referentie `pitboard:{connection_uuid}:{photoset_uuid}:{asset_id}:v{image_version}`. Als de host GET_LOCK niet ondersteunt, faalt de brug gesloten, zonder upload.
- Elke herhaling zoekt dezelfde bronverwijzing op. De oorspronkelijke attachment-slug bevat ook een hash van deze verwijzing, zodat een crash vóór metadata-opslag geen tweede attachment creëert. Een crash vóór de attachment-INSERT kan een verweesd bestand achterlaten; er wordt niet automatisch verwijderd.
- SEO-wijzigingen updaten hetzelfde attachment met revisiecontrole. **Bestaande bestandsnaam/URL worden niet hernoemd**: WordPress kan bij de eerste upload ook een suffix toevoegen. De UI meldt het behoud hiervan. Een nieuwe beeldversie krijgt een nieuw attachment; oude bestanden blijven behouden.
- Handmatige SEO-wijzigingen in WordPress blokkeren een overschrijving; los het verschil expliciet op. Verwijderde/trash-attachments worden niet automatisch teruggezet zolang hun referentie nog aanwezig is. Definitieve verwijdering van een attachment of handmatige verwijdering van beide referenties kan niet door deze idempotentie worden herkend.
- Afgebroken verzoeken worden als **onbevestigd** getoond. Een herhaling controleert opnieuw; er is geen automatische blinde POST-retry en geen succesclaim op alleen HTTP 202.
- Lokale en remote status zijn momentopnamen. Een reeds begonnen upload kan nog eindigen nadat een beheerder de koppeling uitzet. Intrekken in WordPress blokkeert toekomstige authenticatie, niet een al geaccepteerd verzoek.

## Laravel-uitrol en tests

De bestaande Composer-uitrol roept `pitboard:upgrade-wordpress-media-storage` aan. Die past uitsluitend de nieuwe additieve migration toe en weigert een inconsistente gedeeltelijke schemahistorie. Geen seeding, fresh/refresh of activering. Gebruik de normale feature branch/PR-route; voeg het WordPress-pluginbestand **apart**, na de lokale acceptatietest, toe aan de live WordPress-installatie.

Tests: `php -d memory_limit=512M vendor/bin/phpunit`, `node --test tests/js/*.test.cjs`, `python3 -m unittest discover -s tests/python`, `php tests/wordpress/bridge-test.php`, `vendor/bin/pint --dirty --test`.
De laatste test gebruikt geïsoleerde WordPress-stubs, geen echte database; vervangt dus geen lokale integratietest met het echte WordPress-profiel, HTTPS en thumbnails.

## Lokale acceptatietest — 9 oktober 2026

- Applicatiewachtwoord door Dennis zelf ingevoerd; identiteit en rechten bevestigd met de beperkte `pitboard_media`-rol.
- Kunstmatig grijs testbeeld met ‘NIET PUBLICEREN’ via de Pitboard-knop naar `bbquality.test` verstuurd. In WordPress zichtbaar als echte WebP, 640 × 480 pixels, circa 3 KB, met alt, titel, bijschrift en beschrijving.
- De lokale installatie retourneert root-relative media-URL's. De client verwerkt deze nu als dezelfde vaste doelsite; protocol-relative/externe links blijven geweigerd.
- Na de aanvankelijk onbevestigde upload is dezelfde referentie herkend; opnieuw proberen hergebruikte attachment 143711. Vervolgens is de titel via Pitboard gewijzigd en op datzelfde attachment in WordPress gecontroleerd. Geen tweede attachment aangemaakt in deze test.
- SEO-wijziging maakte de goedkeuring ongeldig; de updateknop bleef uitgeschakeld totdat opnieuw werd goedgekeurd.
- De lokale testfoto is behouden als controlevoorbeeld. Geen echte productfoto, productplaatsing, klantgegevens of e-mail gebruikt. De live WordPress-site is niet aangepast en blijft een afzonderlijke, nog niet uitgevoerde activeringsstap na Dennis' akkoord.

Bronnen: [WordPress REST-authenticatie](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/) en [media_handle_sideload](https://developer.wordpress.org/reference/functions/media_handle_sideload/).

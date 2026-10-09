# Afbeeldings-SEO: doorlooptijd, opslag en herstel

## Aanleiding en aantoonbare oorzaken

Dennis meldt een fotoset met beschikbare foto's, ontbrekende SEO en een algemene beeldservice-time-out. Code-inspectie bevestigt:

- De standaard deferred-route voerde SEO serieel uit binnen de beeldtaak, tot zeven aanvragen van elk 120 seconden (dus maximaal veertien minuten extra netwerk-wachttijd).
- De foto-opdracht werd pas na deze lus als voltooid gemarkeerd. Een gestopte servertaak kon daardoor later een misleidende beeldservice-time-out krijgen.
- De gehele gegenereerde PNG-set bleef in geheugen; voor de SEO-lus werden alle database-afbeeldingen met base64-inhoud opnieuw geladen.
- Zowel WEBP als PNG waren in de interface afhankelijk van complete SEO; WEBP had daarnaast een serverblokkade.

De bestaande aangemelde tab bevestigde zeven aanwezige foto's en SEO 0/7, zonder specifieke providerfout in het geopende SEO-venster. Er zijn geen productieproceslogs beschikbaar om geheugenuitputting, hostingbeëindiging of een specifieke providerfout voor die historische uitvoering definitief aan te wijzen. De gevonden codeproblemen zijn geen bewijs van één exacte externe oorzaak.

## Implementatie

- Registreer alle SEO-claims en markeer de opgeslagen fotoset als voltooid vóór netwerkverwerking.
- Deferred: maximaal drie analyses tegelijkertijd, in drie begrensde groepen voor zeven foto's. Sla elk binnenkomend HTTP-resultaat onafhankelijk op; een timeout bij een buur wist het resultaat niet.
- Databasequeue: afzonderlijke bestaande jobs behouden. Geen wijziging aan productieconfiguratie, model, beeldkwaliteit of migraties.
- Geef de gegenereerde PNG-array vrij vóór SEO; laad alleen identifiers voor het voorbereiden van de jobs. Alleen de actieve groep laadt pixels.
- Houd volledige producttekst, actual-image-input, unieke SEO-namen, validatie en bescherming van handmatige teksten/beeldversies intact.
- Geen automatische betaalde retries. HTTP-timeout blijft 120 seconden. Verweesde processing-status verloopt bij uitlezen na vier minuten, queued na tien minuten. Expiratie controleert status, token en tijdstempel tegen een race met een startende job.
- Opgeslagen foto's blijven downloadbaar. Onvolledige SEO gebruikt een technische WEBP-herstelnaam en `X-Image-SEO-Ready: false`; de naam wordt nooit als SEO opgeslagen. PNG blijft origineel. Eigenaarscontrole en etiketcontrole blijven intact.
- Oude mislukte fotosets met opgeslagen resultaten krijgen een afzonderlijke SEO-status. Een mislukte poll bewaart de herstelverwijzing; herladen start geen nieuwe AI-aanvraag.
- Logs: veilige foutcategorie, HTTP-status en duur; geen producttekst, foto of sleutel.

## Verificatie

Tests gebruiken een geïsoleerde in-memory database, fictieve afbeeldingen en gesimuleerde HTTP-antwoorden. Expliciete tests voor zeven foto's, maximaal drie actieve analyses, verbindingstimeout, HTTP 429, behoud van de overige vijf resultaten, volledige producttekst, geen dubbele calls, late AI-antwoorden na handmatige correcties, oude fotosets, downloads zonder SEO en private routes. Bestaande naam-, bron-, versie- en validatietests blijven van toepassing.

Uitgevoerd: volledige PHP-suite (428 tests, 5925 assertions), alle 127 JavaScript-tests en zes Python-tests geslaagd. PHP-stijlcontrole en diff-whitespacecontrole geslaagd. Na de laatste beperking van databasequeries tot noodzakelijke velden is de volledige SEO-testsuite nogmaals uitgevoerd.

Dit bewijst foutafhandeling en technische werking, niet de inhoudelijke kwaliteit of werkelijke snelheid van de externe AI-dienst. Bij een hostingbeëindiging buiten de applicatie kan een analyse nog steeds mislukken; foto's blijven behouden en de SEO-fout is afzonderlijk herstelbaar. Geen wijziging aan Taken, Kalender, Notities, Projecten of echte communicatie.

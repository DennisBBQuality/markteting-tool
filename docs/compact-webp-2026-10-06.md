# Compacte productfoto-downloads — 6 oktober 2026

Opdracht: standaard WEBP op kwaliteitsstand 85, afmetingen en origineel behouden, aparte PNG-download en controle van besparing/beeldkwaliteit. Geen wijziging aan beeldgeneratie, SEO, productieconfiguratie of database.

Bronnen opnieuw gelezen op 6 oktober: actuele Drive-startdocumenten, Teamwerkwijze, richtlijn 07 AI & automatisering en Lokale testomgeving. Die laatste beschrijft de afzonderlijke WordPress-kopie; deze tests gebruiken uitsluitend de geïsoleerde Pitboard-testdatabase, synthetische beelden en bestaande lokale stijlvoorbeelden, geen klanten of externe koppelingen.

## Implementatie

- `ProductImageDelivery`: GD WEBP quality 85, geen resize/crop, behoud alpha en controle RIFF/WEBP-signatuur.
- `ProductImageController`: cacheprofiel plus bronhash, nieuwe en bestaande fotosets krijgen dezelfde export. Geen verwijdering van eerdere caches. Opslagfalen levert een fout op, geen geslaagde lege download. De dossierexport gebruikt al dezelfde route.
- `converter.js` en `index.html`: toelichting en extra Origineel PNG-knop met dezelfde SEO-/etiketcontrole; nieuwe assetversie. De bestaande private PNG-route levert de exacte bronbytes. Authenticatie en eigenaarscontrole blijven ongewijzigd.
- Bestaande foto's hoeven niet opnieuw gegenereerd te worden. Een al gedownload bestand verandert niet; download opnieuw voor de compactere kopie.

## Lokale beeldvergelijking

Alleen bestaande gebundelde voorbeeldbestanden uit `resources/product-image-styles`, zonder ze te wijzigen. Oude lossless-encoder vergeleken met de gewijzigde service, beide op exact dezelfde bronafmetingen. Besparing is tegenover lossless WEBP, niet tegenover PNG.

| Voorbeeld | Afmetingen | Lossless WEBP (bytes) | Quality 85 (bytes) | Kleiner |
| --- | --- | ---: | ---: | ---: |
| serveer-steak-rustiek | 840 × 630 | 447166 | 75972 | 83,0% |
| product-buiten-bbquality | 2048 × 1527 | 972508 | 93552 | 90,4% |
| bbq-outdoor-kamado | 840 × 630 | 564386 | 103280 | 81,7% |

Op 100%-detailuitsneden naast het origineel bekeken: vleesstructuur, contouren en grote etiketletters blijven herkenbaar; subtiele textuur wordt iets gladder. Geen garantie van nul kwaliteitsverlies of van leesbaarheid van iedere kleine etikettekst. Deze drie voorbeelden zijn geen universeel besparingspercentage en geen nieuwe productgeneratie. Kwaliteitsstand 85 blijft daarom een praktische standaard met menselijke beeldcontrole en behoud van de originele PNG.

## Regressiecontroles

Tests voor quality 85, geldige decodering/signatuur, transparantie, ongeldige invoer, alle productcategorieën en behouden afmetingen. Routetests voor oude cache, ongewijzigde bron/SEO, herhaald downloaden, gewijzigde fotoversie, SEO-gate en afgeschermde WEBP/PNG-downloads. Frontendtests voor beide knoppen, toelichting en behoud van SEO-/etiketcontrole.

Uitgevoerd: 421 PHP-tests (5519 assertions), 126 JavaScript-tests en 6 Python-tests geslaagd. Pint, PHP-/JavaScript-syntax, Vite-productiebuild en `git diff --check` geslaagd. De normale npm-launcher was niet beschikbaar op het ingestelde pad; dezelfde Vite-build is met de beschikbare Node-runtime rechtstreeks uitgevoerd. De twee eerdere tests die nog exact verliesvrije pixels en de oude assetversie eisten zijn aangepast aan de nieuwe uitvoerafspraak.

Live frontendversie alleen bewijst geen succesvolle backendconversie; rapporteer de daadwerkelijke livecontrole afzonderlijk. Geen wijzigingen aan de losse WEBP-converter (standaard 80).

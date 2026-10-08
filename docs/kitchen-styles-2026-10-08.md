# Vijf keukenstijlen per apparaat — 8 oktober 2026

## Scope en gedrag

Dennis heeft de vier voorbeeldrichtingen expliciet goedgekeurd voor Pan, Oven en Airfryer. Ieder apparaat heeft nu vijf actieve stijlen, in deze volgorde:

1. Licht modern: bestaand stijlvoorbeeld.
2. Warm modern: natuurlijk eiken, greige en warmgrijs steen; hogere diagonale compositie.
3. Landelijk: saliegroene kaderkastjes, eiken en crème tegels.
4. Donker eigentijds: diepblauwe kastjes, antraciet en walnoot; zacht zijlicht op het product.
5. Mediterraan huiselijk: terracotta, ivoor en eiken; ruimere bordpresentatie.

De bestaande rotatie per gebruiker en apparaat is behouden. Eén gekozen apparaat blijft één foto opleveren. Een nieuwe gebruiker begint bij de bestaande stijl; een gebruiker met een oude genummerde referentie gaat verder bij warm modern. Tegelijk ingediende opdrachten kunnen dezelfde stand lezen (bestaande beperking). De keuze staat vast in de opgeslagen aanvraagcontext. Oude referenties blijven allowlisted en aanwezig; oude aanvragen worden niet herschreven.

Pan blijft herkenbaar op een huishoudelijke kookplaat, Oven als huishoudoven en Airfryer als airfryer. Bij warm/donker ligt het eten waar passend in pan, op bakplaat of in mand. Mediterraan en landelijk-airfryer gebruiken een bord vóór het apparaat. Producten die niet geloofwaardig passen, worden ernaast gepresenteerd. Nooit een dubbele portie; vis behoudt soort/vorm, stoofvlees zachte structuur en jus. Het apparaat is een setting, geen geschiktheidsgarantie.

## Bronrollen en assets

Twaalf nieuwe PNG's onder `resources/product-image-styles/`, elk 1448 × 1086. De vier goedgekeurde previews zijn byte-ongewijzigd overgenomen:

| Asset | Originele generatie-ID |
|---|---|
| keuken-airfryer-warm.png | exec-d4ee06db-2662-4a29-a191-302ef072bd0b |
| keuken-oven-landelijk.png | exec-717def13-d187-44f5-942d-154836a19cf0 |
| keuken-pan-donker.png | exec-2f20e2da-69a9-49e6-a0d8-cdea2b6a2053 |
| keuken-airfryer-mediterraan.png | exec-7b564445-9a77-4c4d-bfa4-46c45fca799e |

Acht aanvullende varianten zijn met de ingebouwde beeldgenerator gemaakt, met het goedgekeurde voorbeeld van dezelfde keukenstijl als anker: pan-warm, pan-landelijk, pan-mediterraan, oven-warm, oven-donker, oven-mediterraan, airfryer-landelijk en airfryer-donker. Alle twaalf zijn visueel gecontroleerd op setting, herkenbaar apparaat, geloofwaardige opstelling en afwezigheid van logo's, tekst en extra hoofdproducten. Dit is beoordeling van de stijlreferenties, niet van toekomstige echte productresultaten.

### Gebruikte generatiebrief voor de aanvullende referenties

Fotorealistische liggende 4:3-foodfoto in een normale huiselijke keuken. Behoud materialen, kleuren, zacht natuurlijk licht en uitstraling van het bijgevoegde goedgekeurde stijlanker. Drie generieke bereide kipstukken als compositievoorbeeld; geen claim dat dit een bestaand BBQuality-product is. Het correcte huishoudelijke apparaat moet zichtbaar zijn. Product centraal, natuurlijk glansniveau, geen HDR, korrel, oranje filter, logo, tekst, personen of dubbele porties. Wijzig alleen apparaat en passende opstelling ten opzichte van het stijlanker.

Apparaataanvullingen: pan-warm op inductie; pan-landelijk op gas; pan-mediterraan eten op bord vóór lege pan. Oven-warm/donker eten op bakplaat met hittebestendige onderzetter vóór open lege huishoudoven; oven-mediterraan op bord vóór open oven. Airfryer-landelijk op bord vóór gesloten airfryer; airfryer-donker in open mand met apparaat erachter. De vier materiaalpaletten staan hierboven. Bij uiteindelijke productgeneratie wordt het voorbeeldeten nadrukkelijk niet overgenomen.

## SEO

Nieuwe aanvragen bewaren `kitchen_style_version: 2`. Alleen voor hun keukenresultaten vervalt het automatisch achter alle vijf mediavelden plakken van een bereidingsclaim. De SEO-analyzer ontvangt de actuele foto, volledige producttekst en gekozen scènevariant. Alt beschrijft de relevante zichtbare presentatie; titel, bijschrift en beschrijving gebruiken de brononderbouwde productfunctie en eigenschappen. Een apparaat op de achtergrond is geen bewijs van een uitgevoerde kookmethode. Stijlnamen, kastkleuren en tegels zijn geen verplichte keywords. Nabewerking volgt de nieuwe pixels, ook als een apparaat verdwenen is.

Bestaande sets behouden hun opgeslagen beleid; opgeslagen en handmatige metadata worden niet automatisch aangepast. Rauwe/BBQ-resultaten, andere categorieën en WEBP-export (1536 × 1152, kwaliteit 85) blijven ongewijzigd. Geen migratie of configuratiewijziging nodig.

## Verificatie

Gerichte tests controleren de vijfstappenrotatie, per-gebruiker/per-apparaat opslag, alle twaalf bestanden, legacyreferenties, 24 gesimuleerde providerverzoeken voor vlees/vis, behoud van productankers, stoofregels, beeldgeleide SEO, volledige producttekst en ongewijzigd oud SEO-beleid. Geen echte mails, productiedatawijzigingen of betaalde productgeneraties tijdens tests.

Resultaten vóór release:

- PHP: `php -d memory_limit=512M vendor/bin/phpunit` — 425 tests, 5824 assertions geslaagd. De volledige suite liep bij de lokale standaardlimiet van 128 MB tegen geheugengebruik aan; alleen het testproces kreeg extra ruimte. Geen productie-instelling aangepast.
- JavaScript: `node --test tests/js/*.test.cjs` — 127 geslaagd.
- Python: `python3 -m unittest discover -s tests/python` — 6 geslaagd.
- `vendor/bin/pint --dirty --test`, `git diff --check` en `npm run build` — geslaagd.

De eerste suitecontrole wees daarnaast terecht op het verouderde bibliotheekaantal (23); die verwachting is aangepast naar 35, inclusief alle twaalf nieuwe assets. Live-verificatie volgt na samenvoegen via de bestaande uitrolroute. Technische tests met nagebootste AI-antwoorden bewijzen geen semantische kwaliteit van toekomstige AI-uitvoer; controle vóór publicatie blijft nodig.

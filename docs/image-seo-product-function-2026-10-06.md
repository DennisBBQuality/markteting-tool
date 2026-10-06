# Afbeeldings-SEO: productfunctie — 6 oktober 2026

## Aanleiding en wijziging

Dennis leverde een gewenste brikettenstarter-uitvoer aan en bevestigde dat de volledige producttekst was ingevoerd. De oude uitvoer is niet bewaard. De instructies bevatten aantoonbaar een conflict: de volledige tekst werd wel doorgestuurd, maar het accessoireprofiel beperkte de beschrijving tot één zin over zichtbare kenmerken. Dit is geen bewezen reconstructie van de oorspronkelijke AI-uitvoer.

De gedeelde instructies selecteren nu eerst productidentiteit, functie, toepassing en relevante voordelen uit de bron. Alt blijft beeldgericht. Titel, bijschrift en beschrijving gebruiken ook niet-zichtbare, brononderbouwde productinformatie. Het accessoire-/saus-/rubprofiel bevat niet langer de conflicterende beperkingen. Het SEO-venster legt dezelfde veldverdeling uit. Bronconflicten, ontbrekende informatie en instructies in bronmateriaal blijven expliciet afgevangen in de prompt. Geen modelwissel of extra automatische AI-aanroepen.

## Echte AI-proef, naast gesimuleerde regressietests

Uitgevoerd met `php scripts/check-image-seo-product-function.php --run`. Dit is een expliciete, handmatige proef van precies drie betaalde tekst-/beeldanalyses; niet opgenomen in de automatische tests. De bestaande lokaal ingestelde API en hetzelfde productie-analysepad zijn gebruikt. Alleen fictieve productgegevens en lokaal getekende PNG-testbeelden, geen klantgegevens of echte producten. Geen databasewijziging, nieuwe productfoto of publicatie. Antwoorden inhoudelijk beoordeeld tegen de invoer; niet alleen op geldige JSON.

| Proef | Bron | Werkelijke uitvoer (selectie) | Beoordeling |
| --- | --- | --- | --- |
| TEST brikettenstarter, Proefmerk | Houtskool/briketten gelijkmatig aansteken; geen aanmaakvloeistof; aanmaakblokjes/houtwol; luchttoevoer | Bijschrift: “Steekt houtskool en briketten gelijkmatig aan zonder aanmaakvloeistof.” Beschrijving noemt luchttoevoer, aanmaakblokjes/houtwol en gelijkmatig aansteken. Alt benoemt de zwarte starter, handgreep en twee zichtbare openingen. | Productfunctie en bronfeiten komen terug; beeld en functie blijven onderscheiden. |
| TEST rub, Proefmerk | Gerookte paprika, knoflook, vóór het roosteren van groenten; rokerig/hartig | Bijschrift: “Kruid groenten vóór het roosteren voor een rokerige, hartige smaak.” Beschrijving noemt paprika, knoflook en het gebruik. Alt blijft “Pot TEST rub van Proefmerk”. | Broninformatie gebruikt zonder brikettenstarterfeiten uit het promptvoorbeeld over te nemen. |
| TEST kruidenpot, alleen naam | Geen aanvullende feiten | Alt: “TEST kruidenpot met zwarte deksel in vooraanzicht”. Beschrijving noemt een kruidenpot voor bewaren van kruiden, geen smaak, ingrediënten, merk of specifieke geschiktheid. | Algemene naamgebaseerde terugval; geen specifieke productclaims toegevoegd. |

Dit is een beperkte semantische steekproef, geen garantie op elke toekomstige AI-uitvoer of Google-resultaten. Echte foto's blijven controle vóór publicatie vragen. Lege bron, broninstructies, conflicten en alle categorieën zijn daarnaast via prompt-/doorgiftetests gedekt; dat laatste is geen afzonderlijke echte AI-kwaliteitsproef. Een realistisch productbeeld en de oorspronkelijke mislukte uitvoer zijn niet opnieuw getest.

## Bestaande gegevens en uitrol

Technische verificatie: 414 PHP-tests (5.462 assertions), 125 JavaScript-tests en 6 Python-tests geslaagd. Gerichte Pint-controle, `git diff --check` en productiebuild geslaagd. De brikettenstarter-regressietest controleert meerzinnige uitvoer door analyse, opslag en expliciete heranalyse heen, inclusief bescherming van handmatige SEO. De zes categorieën krijgen gedeelde bron- en veldregels.

Geen migraties of productieconfiguratie. Bestaande en handmatige SEO blijft behouden. Nieuwe analyses en expliciete heranalyses gebruiken de nieuwe regels en de bij de fotoset opgeslagen tekst. Een nieuw tekstveld bij een andere opdracht wijzigt die opgeslagen bron niet. Handmatige SEO vereist nog steeds expliciete bevestiging vóór vervangen. Standaardbeeldgeneratie, WEBP-download, unieke namen en vlees-/visbereidingsregels blijven ongewijzigd.

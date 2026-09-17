# Bereidingswijze in afbeeldings-SEO

Opdracht Dennis, 17 september 2026: vermeld de gekozen bereidingswijze in de SEO van elke BBQ-, Pan-, Oven- en Airfryer-foto.

- De opgeslagen variantstijl bepaalt de methode, niet de productnaam of een apparaat dat AI op de achtergrond herkent.
- AI krijgt die methode mee en verwerkt deze in alle vijf velden. Een servercontrole vult ontbrekende vermeldingen aan, ook bij handmatig opslaan en in basisvelden wanneer beeldanalyse nog ontbreekt.
- Bestandsnamen gebruiken een afzonderlijk koppeltekenwoord: `bbq`, `pan`, `oven` of `airfryer`. De vier tekstvelden noemen expliciet de bereiding.
- Beide BBQ-varianten vallen onder BBQ. De regels gelden voor vlees en vis; niet voor rauw, sauzen/rubs of pakketten.
- Historische algemene keukenvarianten hebben geen vastgelegde methode: de software raadt die niet. Bestaande opgeslagen SEO blijft bij lezen intact. Expliciet opslaan of opnieuw maken past de nieuwe regel toe waar de variant bekend is.
- Handmatige versies, revisiebeveiliging, bestandsnaam-uniciteit en downloads blijven behouden. Geen migratie, massabewerking van opgeslagen SEO of wijziging van productieconfiguratie.

Bronnen: actuele Drive-startdocumenten, Teamwerkwijze, 07 AI & automatisering en 04 SEO/GEO/content gelezen op 17 september 2026. De gekozen methode beschrijft de bedoelde gegenereerde serveersuggestie. Dit is geen bewijs van een echte bereidingstest, receptadvies of productgeschiktheid; zichtbare details en garnering blijven per foto controleplichtig.

Verificatie: PHP-tests voor alle variantplannen van vlees/vis, idempotentie, woordgrenzen, bestandsnaamlengte, onbekende oude keukenvarianten, basisvelden, AI-invoer, handmatig opslaan, downloadnaam en behoud van bestaande SEO. Providers worden gesimuleerd; er is geen betaalde beeldgeneratie of SEO-analyse gestart.

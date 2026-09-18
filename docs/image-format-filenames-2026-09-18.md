# Liggende productfoto’s en beschrijvende bestandsnamen

## Vastgesteld en gewijzigd

- Op 18 september 2026 heeft de productafbeelding op https://www.bbquality.nl/product/smokey-goodness-smokeys-finest-bbq-sauce/ natuurlijke afmetingen 840 × 630 en een weergegeven vak van 575 × 431,25: beide 4:3. De screenshot geeft dus de juiste verhouding aan, niet de vereiste uploadresolutie.
- Nieuwe generaties (Vlees, Vis, Saus/rub, Totaalpakket, alle varianten) en nabewerkingen vragen native 1536 × 1152. De officiële Image Edit-documentatie ondersteunt dit formaat voor GPT Image 2 en 2.5: https://developers.openai.com/api/reference/resources/images/methods/edit. Beide maten zijn deelbaar door 16. Hoge kwaliteit, modelkeuze en referenties blijven behouden.
- Eén gedeelde formaatregel gaat vóór opgeslagen vierkante promptinstructies; het hele product moet binnen beeld blijven. Verkeerde providerafmetingen geven een fout, geen stille crop/stretch of automatische betaalde nieuwe poging. WEBP-export is verliesvrij. Voorvertoningen gebruiken 4:3 en contain.
- SEO-analyse bekijkt de echte foto en levert maximaal vijf inhoudelijk passende alternatieve bestandsnamen. Geen cijfers, tellers, hashes of willekeurige codes; onderscheid op werkelijk zichtbare ondergrond, presentatie, achtergrond of garnering. Bereidingswijze blijft verplicht in alle vijf velden bij BBQ/Pan/Oven/Airfryer.
- De nieuwe tabel `product_image_download_names` reserveert namen over fotosets en gebruikers heen. De primaire sleutel voorkomt racecondities; bestaande metadata en historische versies worden ook geraadpleegd. Een eerdere naam van dezelfde bewaarde foto blijft gereserveerd na hernoemen. Dezelfde foto mag zijn eigen naam opnieuw opslaan.
- Handmatige dubbele/numerieke namen geven validatiefouten. Als alle AI-alternatieven bezet zijn, blijft de foto bewaard met een duidelijke melding. Er wordt niets verzonnen om toch een naam te kunnen opslaan. Alleen de vijf SEO-velden worden gepubliceerd; alternatieven blijven intern.
- WEBP-download wacht op een geldige beschrijvende SEO-naam. De knop opent zo nodig de SEO-editor; de bestaande etiketcontrole blijft verplicht. Oude numerieke SEO kan opnieuw worden gemaakt of handmatig aangepast.

## Veiligheid en testbewijs

- Alleen een nieuwe lege naamregistratietabel; geen wijziging of import van Taken, Kalender, Notities, Projecten of bestaande afbeeldingen/SEO.
- Lokale migratie toegepast na controle APP_ENV=local en lokale SQLite. Bestaande vier kern-tabellen bevatten vóór de migratie elk nul lokale records. Geen productie-instellingen, sleutels, databases of uploads in de patch.
- 300 PHP-tests slagen, 3928 assertions; 69 JavaScript-tests slagen. Gerichte PHP-formattering en git diff --check uitgevoerd.
- Tests omvatten formaat van alle producttypes, verliesvrije WEBP-dimensies/pixels, uitgaande generatie- en nabewerkingsparameters, weigering van vierkant providerantwoord zonder retry, cijfers inclusief Unicode/lange namen, globale naamconflicten, AI-alternatieven, historische namen, herhaald opslaan en behouden etiketcontrole.
- Alle providerantwoorden zijn gesimuleerd. Geen betaalde generatie gedaan; de eerste echte nieuwe fotoset moet nog visueel beoordeeld worden op compositie en inhoudelijke SEO-juistheid.
- De lokale browserbereikbaarheidscontrole toont het inlogscherm; geen ingelogde visuele resultaatcontrole gedaan. De download-/etiketinteractie is met JavaScript-tests gecontroleerd.

## Grenzen en uitrol

- Bestaande vierkante beelden worden niet vervormd of automatisch opnieuw gegenereerd. Nieuwe generatie/nabewerking maakt een nieuw 4:3-beeld.
- De naamregistratie geldt binnen Pitboard voor bewaarde foto's. Pitboard kent niet alle bestaande namen in de WordPress-mediabibliotheek. WordPress kan bij herhaald uploaden of een reeds bestaande externe naam zelf een suffix toevoegen; daarvoor is aparte controle in WordPress nodig.
- Geen productie-uitrol uitgevoerd in deze wijzigingsronde. Release via de bekende route: feature branch → geteste PR → main → bestaande automatische deployment, inclusief uitsluitend de additieve migratie en gebruikelijke workerherstart. Nooit fresh/refresh/seed/import.

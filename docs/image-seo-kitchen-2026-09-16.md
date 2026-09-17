# Beeldspecifieke SEO en lichte keukenvariant

## Scope en bron

Gebouwd op verzoek van Dennis: vijf SEO-velden gebaseerd op iedere afzonderlijke foto en voor Vlees/Vis een vijfde, bereide foto in een lichte moderne woonkeuken. Daarna uitgebreid met zelf te kiezen varianten (hieronder). Voor die uitbreiding zijn de actuele kennisbankstart, teamwerkwijze en 07 AI & Automatisering opnieuw via Drive gelezen. Beeldobservaties en productidentiteit worden gescheiden; serveersuggesties worden niet als verkochte ingrediënten beschreven.

## Uitbreiding: zelf kiezen tussen Rauw, BBQ, Pan, Oven en Airfryer

- Vlees en Vis bieden vijf aanvinkbare groepen: Rauw (2), BBQ (2), Pan (1), Oven (1), Airfryer (1). Standaard Rauw + BBQ + Pan, maximaal zeven foto's; minimaal één groep verplicht. De knop en voortgang volgen het echte aantal.
- De drie aangeleverde PNG-voorbeelden staan ongewijzigd in `resources/product-image-styles/keuken-{pan,oven,airfryer}.png`. Alleen het passende voorbeeld gaat mee, uitsluitend voor omgeving, licht, kleur en compositie. Nooit het voorbeeldgerecht, de hoeveelheid of de structuur kopiëren. De eigen productfoto blijft leidend.
- Pan toont pan/fornuis; Oven een huishoudoven; Airfryer een herkenbare airfryer. Alle drie in een lichte moderne woonkeuken. Geen impliciete garantie dat elk product geschikt is voor ieder apparaat. Geen extra voedsel in apparaten overnemen.
- Bestaande rauwe/BBQ-plannen blijven gelijk. Oude aanvragen zonder variantkeuze behouden hun eerdere vijfbeeldplan; saus/pakket blijven twee beelden. Iedere nieuwe foto behoudt afzonderlijke SEO en versiebeheer.
- Gewijzigd: generatorcontroller en job, promptbuilder, visprofiel, stijlbibliotheek en drie referentieassets; converter-JavaScript, styling en assetversies; queue-defaults en lokale starthelper; regressietests. Geen extra databasemigratie nodig voor deze keuzevelden.
- Fototaak-timeout 1200 seconden, gedeelde standaard database-retry 1320 seconden. Nieuwe keuze-aanvragen krijgen meer wachtrij-/verwerkingstijd voordat polling ze als vastgelopen markeert.

## Wijzigingen

- Promptbuilder: vijfde keukenplan, bestaande vier stijlen behouden, geen donker gebundeld stijlvoorbeeld voor keuken. Varkens-/runderwangen worden herkend als stoofproduct.
- Fototaken, frontend en polling: vijf foto's voor vlees/vis, twee voor saus/pakket; oude vierfotoresultaten blijven werken. Lokale worker-timeout en standaard database-retry aangepast.
- Nieuwe metadatatabel/model en SEO-analyzer/job/service: daadwerkelijke PNG, vijf velden, veilige meldingen, één analyse per foto/versie, downloadnamen, versieherstel, eigenaarscontrole en bescherming tegen late antwoorden.
- Controller/routes/dossierexport: afzonderlijk lezen/opslaan/analyseren, WEBP en export gebruiken dezelfde opgeslagen velden.
- Frontend: bewerkbare SEO met preview, kopiëren, opslaan, opnieuw maken en blijvende foutmelding.

## Verificatie

- Na de keuze-uitbreiding: **284 PHP-tests** en **68 JavaScript-tests** geslaagd. PHP-opmaakcontrole, JavaScript-syntaxcontrole en `git diff --check` geslaagd. Geen consolefouten tijdens de browserflow.
- PHP-feature-/unittests voor vijf foto's, behoud oude prompts, vis/vlees/stoof, daadwerkelijke pixels in analyseverzoek, foutieve providerrespons, limietmelding, handmatige correcties tijdens analyse, vervallen taken, versieherstel, naamconflicten en rechten.
- JavaScripttests voor oude/nieuwe aantallen, bewerken/kopiëren/opslaan, foutbehoud, bevestiging en late responses.
- Echte browserflow op afzonderlijke tijdelijke SQLite-testomgeving met fictieve gebruiker en fake-generator: vijf resultaten inclusief keuken; vijf SEO-velden opslaan; bestandsnaam normaliseert; sluiten/heropenen behoudt teksten; venster visueel gecontroleerd.
- Uitbreiding in echte browser gecontroleerd: alle groepen geven zeven resultaten met juiste labels; alleen Oven bij Vis geeft één resultaat; nul keuzes schakelt de maakknop uit. Tests dekken alle 31 combinaties voor zowel Vlees als Vis, ongeldige keuzes, behoud van upload/plakken, fouten bij starten en het juiste stijlvoorbeeld in het providerverzoek.
- Alleen de nieuwe migratie is ook op de lokale testdatabase toegepast. Geen productiegegevens of productieconfiguratie gewijzigd.

## Grenzen / uitrol

### Releasevoorbereiding 17 september 2026

Tien nieuwe screenshots toegevoegd aan de drie eerdere keukenvoorbeelden: vier Pan, vijf Oven, vier Airfryer. Per gebruiker en categorie een vaste cyclus, onafhankelijk van productnaam of wissel Vlees/Vis. Keuze server-side in de aanvraag opgeslagen; externe invoer kan geen bestandspad of vreemde categorie kiezen. Bestaande aanvragen veranderen niet. Gelijktijdige aanvragen kunnen dezelfde cyclusstand treffen. Een goedgekeurd productkwaliteitsanker vervangt bij keukenfoto's niet langer de geselecteerde achtergrond. Een aparte test verifieert beide referentierollen in het werkelijke providerverzoek met een gemockt antwoord.

Actuele kennisbankstart, Teamwerkwijze en 07 AI & Automatisering opnieuw via Drive gelezen. Wijzigingen zitten in controller, promptbuilder, stijlbibliotheek, provideradapter, de tien PNG's, frontendtoelichting/cacheversie en tests. Referentieprovenance staat bij de beelden. Teststand: **287 PHP-tests / 3753 assertions en 68 JavaScript-tests** geslaagd. PHP-opmaak, JavaScript-syntax en whitespacecontrole uitgevoerd. Geen betaalde generatie gestart; visuele outputkwaliteit moet nog met een echte proefset worden beoordeeld.

Veilige releasechecklist voor de bestaande GitHub-naar-main-route:

1. Deze feature branch bevat de eerdere SEO- en variantkeuzewijzigingen plus de nieuwe achtergrondrotatie. Alleen code, tests, documentatie en expliciete stijlassets opnemen; geen lokale database, uploads, logboeken, `.env` of privéoverdracht.
2. Vóór activering bestaande productieback-up/terugzetroute controleren. Gebruik uitsluitend gewone Laravel-migraties. De enige nieuwe migratie maakt `product_image_metadata`; geen bestaande taken, kalenderitems, notities of projecten worden gewijzigd. Nooit fresh/refresh/seed/import uitvoeren.
3. Bestaande uitrolprocedure gebruiken. Bij databaseworkers moet een expliciete retry_after groter zijn dan 1200 seconden; de nieuwe code-default is 1320. Workers na uitrol herstarten via de bestaande beheerroute. Bij deferred uitvoering moet de bestaande hostinglimiet voldoende zijn voor beeldgeneratie plus afzonderlijke SEO-analyses; dit is lokaal niet als live runtime bevestigd.
4. Na merge actuele frontend controleren, backendmigratiestatus afzonderlijk bevestigen, bestaande productiegegevens controleren en één echte proefset visueel beoordelen. Geen testorders of fictieve projecten in productie aanmaken.
5. Code kan terug naar de vorige release; laat de additieve metadatatabel bestaan om nieuwe SEO-gegevens te behouden. Geen database rollback uitvoeren die metadata verwijdert.

De opdracht vraagt klaarzetten voor live. Daarom wordt een pull request voorbereid, niet samengevoegd naar main. Een gepushte feature branch is geen live-uitrol.

Er is geen betaalde beeldgeneratie of echte AI-SEO-analyse uitgevoerd. De API-contracten zijn met gemockte antwoorden getest. Laat een eerste echte set beoordelen op keukenstijl, productbehoud en juistheid van zichtbare SEO-details.

Deze wijziging is nog niet via GitHub naar live uitgerold. Gebruik na uitrolautorisatie de bestaande PR-naar-main-route; alleen additieve migratie, nooit lokale databases/fixtures importeren. Bestaande projecten/taken/kalender/notities blijven intact. Controleer bij databaseworkers eventuele expliciete retry_after (>1200 seconden) en herstart workers. Meer foto's en aparte analyses betekenen extra verwerkingstijd/API-kosten.

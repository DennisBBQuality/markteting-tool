# Rustige weekkalender en compacte projecten

Gebaseerd op het eerste door Dennis gekozen dashboardontwerp, 14 september 2026.

## Uitvoering

- Alleen dashboardpresentatie; geen database-, API-, configuratie- of migratiewijziging.
- Bestaande expliciete afwezigheidstitels, zoals `Colin Vakantie` en `Britt vrij`, worden in de horizontale afwezigheidsstrook getoond. De herkenning accepteert een afsluitend `vakantie`, `verlof`, `afwezig`, `vrij`, `vrije dag/dagen`, of een prefix `Vakantie: naam` (ook streepje). Een meerdaagse afspraak of het woord vakantie midden in een titel is niet voldoende. Dit is beperkte naamherkenning, geen AI-classificatie; anders benoemde afwezigheden blijven gewone afspraken.
- Originele objecten en begin-/eindtijden blijven behouden in de bestaande editor. Einddatums om middernacht blijven exclusief; bij een latere eindtijd is de laatste dag zichtbaar. Bij afwezigheid binnen één dag worden de oorspronkelijke tijden aan de strooktitel toegevoegd.
- Maximaal twee zichtbare afwezigheden per dag; extra items blijven via de bestaande kalenderpopover bereikbaar. Tijdafspraken overlappen visueel niet meer en hebben een eigen overflow.
- Standaard urenbereik 08–18 uur. De weergave wordt automatisch uitgebreid als geladen afspraken eerder/later vallen; een meerdaagse tijdafspraak toont 00–24 uur. Bij kleine tegelhoogte blijft de urenlijst scrollbaar. Lange afspraaknamen krijgen een ellipsis en blijven volledig in tooltip, toegankelijke naam en editor beschikbaar.
- Actieve projecten: compacte tabel met projectnaam, deadline, team en werkelijk aantal open taken (`aantal_taken - taken_klaar`). Geen beschrijving of voortgangsbalk. Ontbrekende telling is onbekend, niet nul. Alle actieve projecten blijven in de scrollbare lijst bereikbaar, gesorteerd op deadline en vervolgens naam.
- De bestaande persoonlijke tegelvolgorde, formaten, zichtbaarheid, takenfilter, persoonlijke notities en Trunkrs-module blijven intact.

## Integratiekeuzes ten opzichte van de afbeelding

De kalender gebruikt de bestaande FullCalendar-component: de dagkop staat boven de afwezigheidsstrook zodat datums en stroken direct uitlijnen. Bestaande opgeslagen tegelhoogtes worden niet naar de mock gedwongen; compactere tegels kunnen verticaal scrollen. Bestaand echt logo en FontAwesome-iconen worden hergebruikt. Geen fictieve locaties of extra gebruikersvelden toegevoegd.

## Controle

- 247 Laravel-tests (2553 assertions), 45 JavaScript-tests, Vite-build en PHP-syntaxcheck.
- Tests voor behoud bronobject, exclusieve einddatum, ontbrekende/ongeldige eindtijd, halve dag, zomer-/wintertijd, jaargrens, meerdaagse niet-afwezigheid, veilige projecttekst en echte tellingen.
- Geïsoleerde browserfixture op `127.0.0.1:8001`, zonder Laravel/database/mailkoppeling. Voorbeeldvakanties met overlap, projecten en taken; geen productiekopie.
- Browser: meer-popover, originele afwezigheidsdatums openen, project openen, week vooruit/terug naar vandaag, tegelverplaatsing en formaatwijziging annuleren. Geen consolefouten. Mobiele documentbreedte 390 bij viewport 390; brede tabellen en kalender scrollen alleen binnen hun eigen tegel.
- Geen live-deploy, GitHub-push of productiegegevenswijziging in deze ronde.

## Opslaan hersteld — 15 september 2026

- De geïsoleerde browserfixture gaf op `/api/auth/csrf` alleen JSON terug, zonder de beveiligingscookie die de echte frontend vereist. Daardoor strandde opslaan vóór de PUT-aanvraag.
- De fixture geeft nu een sessiegebonden cookie met HTTP 204 terug en controleert de token bij wijzigingen. Productieauthenticatie, CSRF-middleware en dashboardvoorkeuren-API zijn ongewijzigd.
- Nieuwe integratietest start een eigen lokale PHP-fixture met tijdelijke testsessies en gebruikt de echte frontend-API-helper en indelingsmodule. Bevestigd: opslaan, herladen, volgorde/formaat/zichtbaarheid, weigering zonder token, herstel verlopen token en conflict met een oud tabblad. De test reproduceerde vóór de fix exact de gemelde foutmelding.
- Browsercontrole: kalender Half/Hoog en taken naar voren opgeslagen; na herladen opnieuw dezelfde breedte, hoogte en volgorde. Geen consolefouten.
- Uitrolscope: uitsluitend dashboard-JavaScript, dashboard-CSS, assetversies, tests en dit document. Geen migrations, seeders, productieconfiguratie, lokale databases, uploads of privéwerkaantekeningen in de release. Bestaande taken, kalenderitems, notities en projecten worden niet via deze wijziging geschreven.
- Live-uitrol is door Dennis geautoriseerd na herstel. Deploymentbevestiging volgt na merge en controle van de gepubliceerde bestanden; zonder hostingtoegang is dit geen inhoudelijke vergelijking of back-up van de productiedatabase.

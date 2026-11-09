# Google-app: concept voor productie

Status 9 november 2026: de opdrachtgever heeft de drie teksten expliciet goedgekeurd voor publicatie en de verantwoordelijkheid voor de beschreven gegevensbeheerafspraken op zich genomen. Bij voorbereiding staat de Google-app nog in Testing. Publish app is uitgeschakeld en verwijst naar onvolledige Branding. Homepage, privacy- en voorwaardenlinks ontbreken. De gekoppelde YouTube-accountkeuze is live gecontroleerd; automatisch publiceren staat uit wegens ontbrekend bewijs van serverplanning.

## Voorbereide pagina's

- `/over-pitboard`: openbare uitleg, toegang voor het interne team, links naar privacy en voorwaarden.
- `/privacy`: de gegevensverwerking van de contentmodule en de daadwerkelijke beperkte Google-scopes.
- `/gebruiksvoorwaarden`: intern gebruik, menselijke publicatiecontrole en verwijzing naar de YouTube-voorwaarden.
- Privacy en voorwaarden zijn vanaf het inlogscherm en de ingelogde zijbalk bereikbaar.

Deze pagina's zijn voorbereid op `codex/google-production-pages` en goedgekeurd voor de bestaande PR-uitrolroute. Geen productieconfiguratie, database, appgeheim of platformtoegang wordt met deze code gewijzigd. Geen lokale data naar productie kopiëren.

## Beslissingen en uitvoering vóór productie

1. BBQuality heeft de openbare teksten goedgekeurd. De opdrachtgever neemt de verantwoordelijkheid voor verwijdering binnen zeven kalenderdagen en vernieuwen/verwijderen van opgehaalde YouTube-gegevens binnen dertig dagen op zich. Deze wijziging voegt geen automatische opschoning of verwijderknop toe. Er mag niet worden geclaimd dat die automatisering bestaat. De verantwoordelijke zorgt dat privacyverzoeken via de contactpagina bij de applicatiebeheerder terechtkomen en dat gebruikers met de verklaring instemmen vóór gebruik van de YouTube-functie.
2. Na akkoord en bevestigde uitvoerbare beheerprocedure: veilige code-uitrol, vervolgens alle drie de publieke pagina's live zonder login verifiëren. Geen juridische tekst als bestaand goedgekeurd beleid presenteren vóór die bevestiging.
3. In Google Branding de drie echte HTTPS-URL's invullen en opslaan. Het bestaande toegestane domein is `bbquality.nl`. Controleer eventuele aanvullende Google-eisen in de live UI; een inlogpagina alleen is onvoldoende als apphomepage.
4. Google Audience naar In production zetten en de status teruglezen. Dit is geen bewijs van afgeronde Google-verificatie. Er komt geen openbare Pitboard-registratie bij; de interne apprechten blijven gelden.
5. De bestaande YouTube-aanmelding opnieuw doorlopen voor dezelfde scopes en hetzelfde bedrijfskanaal, zodat we niet vertrouwen op het eerder in Testing uitgegeven kortlevende refresh token. Accountkeuze opnieuw bewaren, publicatie uit laten tot de servercontrole klaar is.
6. Beheerder van de bestaande SpinupWP-server laat Laravel iedere minuut `schedule:run` uitvoeren als de juiste sitegebruiker. De werkelijke geplande uitvoering en heartbeat controleren; niet alleen handmatig de opdracht starten. Controleer ook de bestaande Trunkrs-taak die dezelfde scheduler gebruikt.

## Bronnen

- [Google: Audience en Testing](https://support.google.com/cloud/answer/15549945): zeven dagen voor testtoestemming en refresh tokens; productie en verificatie zijn afzonderlijke statussen.
- [Google: Branding](https://support.google.com/cloud/answer/15549049): homepage, privacy, voorwaarden, domein en informatie op het toestemmingsscherm.
- [YouTube Developer Policies](https://developers.google.com/youtube/terms/developer-policies): voorwaarden/privacy, vernieuwen of verwijderen van API-data en verwijderverzoeken. Dit document claimt geen volledige juridische of platformaudit.
- [SpinupWP: extra crontaken](https://spinupwp.com/doc/add-cron-jobs/): eigen taken onder de sitegebruiker; de standaard WP-Cron-instelling is geen Laravel-scheduler.

## Validatie

475 PHP-tests / 6479 assertions en 138 JavaScript-tests geslaagd; Pint en diff-check geslaagd. Nieuwe test controleert publieke pagina's naast ongewijzigde authenticatie van de interne content-API. Lokale browsercontrole van paginaweergave en navigatie. Geen externe publicatie of live migratie uitgevoerd.

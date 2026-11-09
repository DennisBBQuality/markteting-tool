# Contentplatform voor The Pitboard

Bijgewerkt: 6 november 2026. Besluit Dennis: **eigen Meta- en YouTube-koppelingen; TikTok later**. De Meta Developer-app bestaat nog niet. Er wordt geen abonnement op een publicatiedienst afgesloten.

## Analyse van het voorbeeld

De drie screenshots en de 49 seconden durende schermopname laten een contentkalender, een editor met meerdere accounts, mediaselectie, een voorbeeld, goedkeuring en planning zien. De maker meldt dat het platform via koppelingen publiceert. De beelden bewijzen niet welke leverancier of techniek daarvoor wordt gebruikt.

De bruikbare opzet is één werkomgeving voor voorbereiding, beoordeling, planning en resultaat. De kalender moet het team laten zien wat wanneer verschijnt; het bericht moet eigenaar, campagne en productietaak verbinden. De voorbeeldweergave helpt de redactie, maar is geen garantie voor de uiteindelijke platformweergave. De zichtbare laadfouten in het voorbeeld onderstrepen dat ontbrekende data expliciet gemeld moet worden.

Voor Pitboard is aansluiting op de bestaande projecten, taken en vormgeving logischer dan het overnemen van het volledige andere CRM. Link-in-bio, advertenties, CRM-databases en chat vallen buiten deze eerste uitbreiding. Het bestaande Taken/Kalender/Notities/Projecten-deel blijft behouden.

Actuele kennisbank gelezen: Start hier, Teamwerkwijze, Merk & Tone of Voice, AI & automatisering. Marketing- en doelgroepkader zijn in de voorafgaande analyse meegenomen. De leidende documenten blijven in de Google Drive-kennisbank; dit plan kopieert de richtlijnen niet.

## Wat deze eerste versie doet

| Onderdeel | Gedrag |
| --- | --- |
| Contentoverzicht | Concepten met eigenaar, briefing en afzonderlijke kanaalstatussen; zoeken op de getoonde pagina en kanaalfilter |
| Contentkalender | Maand, week en lijst; kanaalversies op hun eigen tijd; items zonder datum apart |
| Editor | Titel, briefing, eigenaar, project, bestaande productietaak, bronlink, tekst/media/instellingen per kanaal |
| Bewaren | Automatisch bewaren, handmatig opslaan en lokale invoerherstelmogelijkheid per gebruiker |
| Samenwerken | Reacties, activiteitenlog, review aanvragen, aanpassingen vragen, exact deze versie goedkeuren |
| Versiebeheer | Revisienummer en bescherming tegen overschrijven vanuit een oud tabblad; wijzigingen maken goedkeuring ongeldig. Geen terugzetten van volledige historische versies |
| Media | Privébestanden; JPG, PNG, WEBP en MP4; maximaal 25 MB, mogelijk lager door de serverlimieten |
| Voorbeeld | Wisselen tussen kanaalvoorbeelden; indicatieve weergave van tekst en eerste bestand |
| Accountbeheer | Alleen beheerder; Meta/Google-aanmelding, accountselectie, versleutelde sleutels en expliciete activering |
| Publicatie | Alleen goedgekeurde versie; manager/beheerder bevestigt accounts en tijdstippen; lokale omgeving verstuurt nooit |
| Resultaat | Per kanaal apart: ingepland, aangeboden, verwerking, bevestigd geplaatst, mislukt of onbekende uitkomst |

De kalender mag een concept met een voorgenomen datum tonen. Dat betekent nog niet dat het automatisch wordt geplaatst. Alleen de afzonderlijke handeling **Automatisch inplannen** geeft die opdracht.

### Publicatieformaten in deze versie

- **Instagram:** één JPG (maximaal 8 MB, verhouding 4:5 tot 1,91:1) of één MP4 als Reel. Een professioneel account moet via de Facebook-pagina bereikbaar zijn. Meerdere afbeeldingen, Stories en automatische uitsnede vallen buiten deze versie.
- **Facebook:** tekst of één JPG/PNG-foto op de geselecteerde pagina. Facebook-video/Reels volgt in een volgende uitbreiding.
- **YouTube:** één MP4 met eigen titel, beschrijving, expliciete zichtbaarheid en keuze ‘gemaakt voor kinderen’. De gekozen zichtbaarheid wordt gecontroleerd. Een bevestigde plaatsing kan dus ook een privévideo zijn.
- **TikTok:** voorbereiden, beoordelen en in de kalender zetten; voorlopig handmatig plaatsen. TikTok wordt uitgesloten van de automatische inplanactie.
- Realistische synthetische beelden kunnen worden gemarkeerd. YouTube krijgt de disclosure door; automatische Meta-publicatie van zulke gemarkeerde content is geblokkeerd tot de juiste disclosure-route is uitgewerkt.

Video wordt nog niet getranscodeerd of volledig op codec/duur gevalideerd. Het platform kan een video tijdens verwerking afkeuren; dat verschijnt als mislukte verwerking. De huidige limiet van 25 MB is vooral geschikt voor de eerste kleine proefbestanden. Grotere productievideo’s vereisen een afzonderlijke uitbreiding van upload en opslag.

## Architectuur en betrouwbaarheid

Laravel 12, bestaande sessie-authenticatie en vanilla JavaScript blijven de basis. Nieuwe tabellen worden uitsluitend met een additieve migration aangemaakt: contentdossiers, kanaalversies, media, verbindingen en activiteiten. Er worden geen bestaande tabellen of gegevens vervangen.

Goedkeuring wordt aan het revisienummer gebonden. Een aangeboden dossier wordt onveranderlijk. Annuleren kan alleen de nog niet gestarte opdrachten terughalen; al begonnen platformverwerking wordt niet als geannuleerd voorgesteld. Dupliceren maakt een nieuw, ongepland concept zonder externe nummers.

Pitboard bewaart de planning zelf. De server voert `content:publish-due` via Laravel iedere minuut uit. Dit is toegevoegd aan de bestaande applicatieplanning, maar **een daadwerkelijke iedere-minuut-aanroep op de host is nog niet aangetoond**. De bestaande Trunkrs GitHub-planning vervangt die niet. Er is geen hosting- of productieconfiguratie veranderd. Activering en inplannen controleren een recente uitvoerder-heartbeat.

De uitvoerder claimt een opdracht vóór verzending. Transacties en sloten beschermen tegen gelijktijdig bewerken, annuleren en uitvoeren. Per kanaal blijven de externe objectnummers en resultaten bewaard. Meta haalt uitsluitend het gekozen bestand op via een tijdelijk ondertekend adres. YouTube ontvangt een bestand via een versleuteld opgeslagen resumable uploadadres waarvan de host wordt gecontroleerd.

Een geaccepteerde aanvraag wordt niet meteen ‘gepubliceerd’. Instagram wacht op verwerking en bevestigde plaatsing; Facebook controleert de post; YouTube controleert verwerking, kanaal en zichtbaarheid. De knop **Status controleren** start geen nieuwe publicatie.

Bij onduidelijke verzenduitkomst volgt geen automatische herhaling. Bestaande objecten en YouTube-uploadsessies kunnen worden gecontroleerd. Als bijvoorbeeld een Facebook-antwoord verloren ging vóór ontvangst van een objectnummer, is controle op het platform nodig; deze versie heeft geen automatische zoekreconciliatie of handmatige resetknop. Ook een niet-voltooide YouTube-upload wordt niet automatisch opnieuw gestart. Een opdracht die meer dan 15 minuten te laat wordt opgepakt wordt tegengehouden, zodat een serverstoring niet onverwacht oude campagnes publiceert.

Opnieuw aanmelden kan verlopen toegang herstellen. Ingeplande accounts blijven vastgelegd en kunnen niet naar een ander account worden omgeleid. Appgegevens wijzigen wordt geblokkeerd zolang opdrachten nog lopen of hun uitkomst onbekend is.

## Inrichting en eerste echte proef

1. **Meta-app maken.** Gebruik het BBQuality-bedrijfsbeheer en een beheerder met publicatierechten op de juiste Facebook-pagina en het gekoppelde professionele Instagram-account. Configureer Facebook Login en de rechten `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `instagram_basic`, `instagram_content_publish`. Neem de API-versie over uit de app. Controleer in Meta welke bedrijfsverificatie, review, privacy- en gegevensverwijderingsinformatie voor deze app vereist zijn.
2. **Google-project/app maken.** Schakel YouTube Data API v3 in en configureer een OAuth-client voor een webapp, toestemmingsscherm en de toegestane accounts. Gevraagde scopes zijn `youtube.upload` en `youtube.readonly`. Controleer publicatiestatus, eventuele verificatie, quota en blijvende toegang in het Google-project.
3. **Terugkeeradressen registreren.** In productie zijn dat `https://planning.bbquality.nl/api/content/connections/meta/callback` en `https://planning.bbquality.nl/api/content/connections/youtube/callback`. Pitboard toont het adres van de gebruikte omgeving. Registreer niet per ongeluk het lokale adres voor productie.
4. **Appgegevens veilig invoeren.** Via Content → Kanalen door een beheerder. Geen sleutels in chat, Git of het plan. Na aanmelden toont Pitboard de werkelijk gevonden accounts. Controleer expliciet de Facebook-pagina, Instagram-gebruikersnaam en het YouTube-kanaal.
5. **Servervoorwaarden aantonen.** De bestaande host moet Laravel iedere minuut aanroepen. Webproces en uitvoerder moeten dezelfde database, stabiele encryptiesleutel en duurzame privémediaopslag gebruiken. Controleer HTTPS-bereikbaarheid van de ondertekende media-route, uploadlimieten en dat bestanden een deploy overleven. Hiervoor is nog geen productieconfiguratie aangepast.
6. **Eerst beperkte proef.** Start met een privé-YouTube-video en daarna afzonderlijk expliciet vrijgegeven Facebook-/Instagram-content. Laat iemand de bedoelde tekst, media, account en zichtbaarheid goedkeuren. Controleer daarna op het platform zelf het resultaat en de link in Pitboard. Geen echte content is tijdens de bouw verzonden.
7. **Pas daarna breder gebruiken.** Controleer dagelijkse foutmeldingen, opnieuw aanmelden bij ingetrokken toegang, platformquota en opslagcapaciteit. Uitbreidingen: Facebook-video/Reels, grotere uploads, Stories/carrousels en pas later TikTok.

## Uitrol en controle

Werkbranch: `codex/content-platform`, gebaseerd op actuele `origin/main`, in een aparte worktree zodat lopend ander werk behouden blijft. Release volgens de bestaande route: GitHub-PR naar main → bestaande automatische uitrol. Geen nieuwe hosting, seed/import/fresh/refresh of overschrijven van de productiedatabase.

Voor een release moeten tests, stijlcontrole en build slagen. Na uitrol zijn afzonderlijke controles nodig op de nieuwe migration, ingelogde Content-API, versleuteld opslaan, private mediabereikbaarheid en de serveruitvoerder. Actuele frontendbestanden alleen bewijzen niet dat database en planning operationeel zijn. Zonder aangemaakte apps en bedrijfsaccounttoegang blijft echte publicatie uit.

## Bronnen

- [Meta: Instagram API en Facebook Login, officiële Postman-collectie](https://www.postman.com/meta/instagram/documentation/6yqw8pt/instagram-api) — accountrelatie, rechten en Reel-verwerking. Directe Meta-documentatiepagina’s waren tijdens controle niet steeds bereikbaar; echte account-/formaatproeven blijven noodzakelijk.
- [Google: OAuth voor webserver-apps](https://developers.google.com/identity/protocols/oauth2/web-server).
- [YouTube: uploadprotocol en sessiestatus](https://developers.google.com/youtube/data/guides/using_resumable_upload_protocol).
- [YouTube: videos.insert](https://developers.google.com/youtube/data/reference/rest/v3/videos/insert).
- [TikTok: content-sharing guidelines](https://developers.tiktok.com/doc/content-sharing-guidelines/) — een uitsluitend interne Direct Post-client past niet binnen de beschreven toelatingsvoorwaarden. Er wordt nu geen TikTok-publicatiekoppeling gebouwd.

## Uitgevoerde lokale verificatie

- Volledige PHP-testset: 465 tests, 6393 assertions, geslaagd (512 MB testlimiet).
- Volledige JavaScript-testset: 138 tests, geslaagd.
- Pint, JavaScript-syntaxcontrole, `git diff --check` en productiebuild geslaagd.
- Nieuwe tests controleren onder andere revisieconflicten, rollen, vervallen goedkeuring, accountbevestiging, annulering vóór claimen, onbekende verzenduitkomsten, eenmalige Instagram-publicatie, YouTube-verwerking/zichtbaarheid, OAuth-state, versleutelde geheimen en ondertekende media.
- Browserproef met een uitsluitend lokale testdatabase: opslaan, beoordeling, goedkeuring, kalender en kanaalinstellingen gecontroleerd. Zonder koppelingen wordt inplannen tegengehouden.
- Geen echte socialmedia-API aangeroepen of echte publicatie uitgevoerd. De live platformproeven en grotere videobestanden zijn nog niet gevalideerd.

## Herstel van de inrichting — 9 november 2026

De ingelogde livecontrole wees uit dat de contenttabellen nog ontbraken. Alleen frontendbestanden waren door de vorige release aantoonbaar geleverd. Daarom is de bestaande beperkte Composer-installatieroute uitgebreid met `pitboard:upgrade-content-storage`. Die opdracht mag uitsluitend de vaste contentmigration uitvoeren wanneer alle bijbehorende tabellen ontbreken en de migratiehistorie daarmee overeenkomt. Bij een gedeeltelijke structuur of ontbrekende kolommen stopt automatische inrichting; er worden geen bestaande tabellen vervangen.

Content → Kanalen controleert nu alle vijf tabellen en de vereiste velden. Een beheerder kan dezelfde begrensde initialisatie ook vanuit dat scherm bevestigen. Twee lege, uitgeschakelde verbindingsslots maken de laatst geregistreerde start van de serveruitvoerder zichtbaar vóór het invoeren van appgegevens. Initialiseren, de instellingen bekijken of accountgegevens opslaan vult die starttijd niet in. De uitvoerder stopt zonder verzending als opslag ontbreekt. Dit herstel stelt geen cron in en bewijst op zichzelf niet dat de serverplanning draait.

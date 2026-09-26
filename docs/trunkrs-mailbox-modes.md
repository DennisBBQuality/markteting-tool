# Trunkrs: bestaande mailbox of apart leesaccount

## Toegangsbesluit en grenzen

Op 22 september 2026 is expliciet gekozen voor gebruik van de bestaande mailboxeigenaar zonder extra betaald leesaccount, na uitleg van de mailboxbrede technische leesmachtiging. Dit document bevat geen accountgegevens of tokens. De code ondersteunt die keuze; deze documentatie bewijst geen actieve productieverbinding.

`TRUNKRS_MAILBOX_MODE=own` gebruikt gedelegeerde `Mail.Read`, `User.Read` en `offline_access`. Microsoft staat hiermee lezen van de gehele eigen mailbox toe. Alleen de applicatie beperkt haar verzoeken tot de ingestelde Trunkrs-rapportmap. Geen Microsoft-afgedwongen mapbeperking en geen schrijf-, verwijder- of verzendrechten. Toegang kan door Microsoft-beleid of intrekken van toestemming verlopen; opnieuw aanmelden kan nodig zijn.

`shared` blijft de standaard, met gedelegeerde `Mail.Read.Shared`, `User.Read` en `offline_access`. Hiervoor blijft een afzonderlijk leesaccount met door een beheerder gecontroleerde maprechten nodig. De applicatie controleert de identiteit en scopes, maar kan niet bewijzen dat Exchange-maprechten correct zijn begrensd. Er is geen automatische terugval tussen modi.

## Microsoft Entra: bestaande registratie hergebruiken

Voor de eigen mailbox:

1. Open de bestaande Trunkrs-appregistratie, **API-machtigingen**.
2. Voeg **Microsoft Graph → Gedelegeerde machtigingen → Mail.Read** toe (niet Application permissions, niet Mail.ReadWrite).
3. Verwijder `Mail.Read.Shared` uit de configuratie; behoud `User.Read` en `offline_access`.
4. Controleer ook de reeds verleende toestemmingen bij de bijbehorende **Bedrijfstoepassing → Machtigingen**. Alleen de configuratie verwijderen trekt oude toestemmingen niet noodzakelijk in. Trek een oude ruimere toestemming gericht voor deze app in indien aanwezig; keur vervolgens uitsluitend de drie gekozen rechten goed. Raak andere bedrijfsapps niet aan.
5. Onder **Authentication → Instellingen** moeten openbare clientstromen toegestaan zijn voor device-code-aanmelding. Geen client secret of redirect-URI nodig voor deze flow.
6. De beheerder verleent toestemming; de uiteindelijke device-code-aanmelding gebeurt met het bestaande mailboxaccount, niet met het beheeraccount.

Bronnen: [Microsoft Graph-machtigingen](https://learn.microsoft.com/en-us/graph/permissions-reference), [device-code-flow](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-device-code), [toestemmingen beheren](https://learn.microsoft.com/en-us/entra/identity/enterprise-apps/manage-application-permissions).

## Controle automatische start (24 september 2026)

- GitHub Actions is ingeschakeld, de workflow is actief, `main` is de default branch en de repository is niet gearchiveerd. Toch bevatte de uitvoeringshistorie geen enkel `schedule`-event, ook niet voor de gemiste ochtendcontrole van 24 september.
- De bestaande workflow is opnieuw geactiveerd. Een tijdelijke vijfminutenproef is onderzocht en vervolgens verwijderd; tijdens de controle verscheen geen geplande uitvoering. Het ontbreken van een proefrun binnen dit venster sluit latere GitHub-vertraging niet uit.
- De inhaalcontrole via `workflow_dispatch` ([run 35969569769](https://github.com/DennisBBQuality/markteting-tool/actions/runs/35969569769)) slaagde. De geslaagde mailboxcontrole en het nieuwere rapport zijn afzonderlijk op Live geverifieerd. Dit bewijst de importketen, niet de automatische start.
- De vaste UTC-ochtendplanning hieronder is gepubliceerd als herstelmaatregel. **Automatische uitvoering blijft onbevestigd.** Controleer een echte `schedule`-run én de bijbehorende Live-import voordat de ochtendimport als hersteld wordt gemeld. Een heartbeat kan ook van een handmatige GitHub- of CLI-proef komen; de beheerstatus vermeldt dat expliciet.

## Inrichting via GitHub en Pitboard (22 september 2026)

### Verifieerbare cloudcontrole (26 september 2026)

De controle om 10:07 Nederlandse tijd vond geen `schedule`-run van 26 september. De workflow stond wel `active`. De laatste echte automatische run was [36133275406](https://github.com/DennisBBQuality/markteting-tool/actions/runs/36133275406) op 25 september om 14:08:53 Nederlandse tijd. De logs daarvan bewijzen alleen ontvangst van de startopdracht. De precieze oorzaak van de ontbrekende GitHub-start is niet vastgesteld; tijdzoneondersteuning is volgens GitHub geldig en mag niet als bewezen oorzaak worden aangewezen.

De herstelrelease gebruikt gewone UTC-crons: `17,27,37,47,57 4-9 * * *` plus `17 10-20 * * *`. De runner slaat geplande uitvoeringen vóór 06:00 Europe/Amsterdam expliciet over. Daarmee zijn in beide seizoenen ochtendcontroles vanaf 06:17 aanwezig, plus latere inhaalmomenten. De bestaande optionele Laravel-planning herhaalt nu om :15, :25, :35, :45 en :55 in de uren 06–09. Deze code activeert geen hosting-cron. De extra momenten verkleinen het effect van gemiste starts, maar leveren geen harde tijdgarantie of onafhankelijke bewaking wanneer GitHub helemaal niet start.

De start-API geeft een unieke `check_id`. De achtergrondtaak bewaart per opdracht `queued`, `running`, `completed` of `failed` in de bestaande gedeelde databasecache (24 uur). De nieuwe alleen-lezen statusroute `/api/trunkrs/scheduled-sync/{checkId}` vereist dezelfde ondertekende repo/main/workflow-identiteit als de start; een openbare URL, Pitboard-login of ander repository-token is onvoldoende. Er worden geen klantregels, aantallen, adressen, mail-ID's of Microsoft-tokens teruggegeven. Geen nieuwe databasekolommen of productieconfiguratie nodig.

`scripts/trunkrs-cloud-check.py` draait uitsluitend op de GitHub-runner en wacht maximaal circa zeven minuten op die specifieke opdracht. Een nieuwe GitHub-identiteit per peiling voorkomt tokenverloop. Groen vereist een voltooide mailboxscan én het nieuwste opgeslagen rapport met ontvangst op de aangevraagde lokale dag en de verwachte bezorgdag (één dag eerder), met controle van de tijdstempels. Een leeg rapport zonder bezorgdatum blijft uitdrukkelijk onbevestigd. Oude successen, alleen HTTP 202, een verdwenen status of een gestopte worker falen gesloten. Een onzekere POST wordt niet onmiddellijk herhaald. Volgende geplande uitvoeringen gebruiken de bestaande importlock, ontdubbeling en provider-wachttijd.

Fouten krijgen een GitHub-foutmelding; de bestaande Pitboard-waarschuwing houdt een ontbrekend/oud rapport zichtbaar. GitHub-e-mailnotificaties hangen af van de bestaande accountvoorkeuren en zijn hiermee niet opnieuw ingesteld of bewezen. Een groen handmatig `workflow_dispatch` bewijst alleen de import- en verificatieketen; voor automatisch herstel blijft een echte `schedule`-run nodig. Zie [GitHubs eigen beperkingen van schedule-events](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#schedule).

Onderstaande oorspronkelijke inrichting blijft achtergrondinformatie; voor planning en eindcontrole gaat deze sectie vóór de eerdere tijden en alleen-heartbeatcontrole.

De eigen-mailboxkoppeling kan nu via **Instellingen → Trunkrs — Niet bezorgd** worden ingericht door een actieve Pitboard-beheerder. Hostingtoegang is hiervoor niet nodig. De gebruikelijke feature branch → pull request → main-route levert de code en een beperkte Composer-upgradehook. Een wijziging van `.env` is niet nodig. Bestaande serverinstellingen blijven als terugval gelden zolang er geen app-instellingen zijn opgeslagen.

Als de uitrol geen migraties uitvoert, verschijnt **Trunkrs-opslag voorbereiden**. Na expliciete bevestiging voert deze adminactie uitsluitend de vaste migraties `2026_09_11_160000_create_trunkrs_reports_tables` en `2026_09_22_150000_create_trunkrs_settings_table` uit voor ontbrekende tabellen. Dezelfde begrensde opdracht wordt door de installatiehook gebruikt. Alle tabel- en migratiestatussen worden vooraf gecontroleerd; bij een afwijkende of gedeeltelijke structuur stopt de opdracht zonder automatische reparatie. Er worden geen gebruikerscommando's uitgevoerd, bestaande tabellen vervangen of andere migraties gestart. Taken, projecten, kalender, notities en bestaande rapporten blijven behouden.

1. Vul tenant-ID, client-ID, het gebruikersobject-ID, het bestaande mailboxadres en de gecontroleerde rapportmap-ID in. Geen wachtwoord of client secret. Deze gegevens worden niet in Git gezet.
2. Sla op. Dit zet inlezen uit, trekt eventuele lokale oude tokens in en vereist een nieuwe verbinding. Bestaande rapporten blijven behouden.
3. Bevestig de expliciete mailboxbrede Microsoft-leestoegang en kies **Verbinden met Microsoft**. Open de vaste Microsoft-aanmeldpagina en gebruik de getoonde tijdelijke gebruikerscode. Meld aan als de ingestelde mailboxgebruiker, niet als het Entra-adminaccount.
4. Kies **Ik ben aangemeld — verbinding controleren**. De server controleert scopes, gebruikers-ID, mailboxadres én het pad van de rapportmap aan de hand van uitsluitend mapmetadata. Pas daarna bewaart hij het refresh-token en schakelt hij inlezen in.
5. Start **Rapporten nu controleren**. De bestaande deferred-uitvoering verwerkt de controle op de webserver na het HTTP-antwoord; geen nieuwe permanente worker nodig. Het antwoord 202 bewijst nog geen import. Vernieuw de status en controleer het echte rapport in het dashboard.
6. Controleer de afzonderlijke melding over automatische rapportcontrole. Een GitHub Actions-aanroep of een aanroep van `trunkrs:sync` via de serverplanning zet deze heartbeat; de handmatige webknop doet dit niet. Controleer daarna de tijd van de laatste geslaagde mapcontrole en het dashboardrapport om de daadwerkelijke verwerking te bevestigen.

De geplande GitHub-workflow in `.github/workflows/trunkrs-sync.yml` controleert dagelijks om :15, :25, :35, :45 en :55 in de uren 06 t/m 09 met tijdzone `Europe/Amsterdam`. De eerste controle is 06:15, de laatste 09:55, in zomer- en wintertijd. GitHub ondersteunt dit timezone-veld volgens de [actuele documentatie](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#schedule). De cloudrunner vereist geen laptop of geopende browser. De extra controles zijn op 25 september toegevoegd wegens opnieuw ontbrekende schedule-events. De handmatige import van die ochtend slaagde; automatisch herstel was bij voorbereiding van deze wijziging nog niet bewezen. Dit is een herstelmaatregel na ontbrekende schedule-events, geen bewezen verklaring voor het uitblijven daarvan. De workflow roept met een kortlevend GitHub-identiteitsbewijs uitsluitend `/api/trunkrs/scheduled-sync` aan. Herhaalcontroles slaan een al geïmporteerd rapport niet dubbel op. De server controleert de handtekening met GitHubs openbare sleutel, uitgifte- en verlooptijd, de exacte repository-identiteit, `main`, de workflownaam en het event voordat hij de bestaande rapportcontrole start. Er staat geen Microsoft-token of vaste sleutel in GitHub. GitHub kan geplande jobs vertragen of missen en schakelt geplande workflows in publieke repositories na 60 dagen zonder repositoryactiviteit uit; bekijk daarom de heartbeat en de laatste geslaagde controle in het Pitboard. Bestaande Laravel-serverplanning mag daarnaast blijven draaien: de import is vergrendeld en ontdubbeld.

Alle setup-API's vragen een actieve adminsessie; wijzigingen zijn POST/PUT met CSRF-beveiliging en afzonderlijke snelheidslimieten. Aanmeldpogingen zijn versleuteld, aan de concrete beheerderssessie en configuratie gebonden, maximaal vijftien minuten geldig en respecteren Microsoft's wachttijd en `slow_down`. Alleen de tijdelijke gebruikerscode gaat naar die browser; device-, access- en refresh-tokens niet. Configuratie, aanmelden, stoppen en synchronisatie gebruiken dezelfde lock. Een verouderd instellingsformulier wordt geweigerd. Logs bevatten uitsluitend actiecodes en de Pitboard-beheerder-ID, geen Microsoft-antwoorden of mailboxgegevens.

**Koppeling stoppen / aanmelding annuleren** schakelt inlezen uit en verwijdert lokale toegangstokens en de lopende aanmeldpoging. Dit verwijdert geen rapporten en trekt niet automatisch de toestemming bij Microsoft in. Die toestemming kan apart voor deze app in Entra worden ingetrokken. Opnieuw verbinden vereist opnieuw expliciete instemming. Gebruik lokaal alleen fictieve testgegevens: echte mailboxverzoeken blijven standaard geblokkeerd.

## Alternatief: inrichting op de hostingserver

Voor omgevingen die bewust serverconfiguratie gebruiken blijft de bestaande CLI-inrichting beschikbaar. GitHub bewaart nooit Microsoft-toestemming of tokens. Bij gebruik van de hierboven beschreven beheerfunctie is deze alternatieve route niet nodig.

- `TRUNKRS_MAILBOX_MODE=own` expliciet kiezen.
- Tenant-ID en app/client-ID van de juiste registratie gebruiken.
- `TRUNKRS_READER_USER_ID` is het Entra-object-ID van de **mailboxgebruiker**, niet van de app of het adminaccount.
- `TRUNKRS_MAILBOX` is het primaire mailadres of de aanmeldnaam van diezelfde gebruiker.
- `TRUNKRS_FOLDER_ID` is de gecontroleerde Graph-ID van de afgesproken rapportmap. Een mapnaam alleen volstaat niet. De huidige verwachte map staat als label in `config/trunkrs.php`.
- Verifieer de bestaande Trunkrs-tabellen met de migratiestatus; indien nodig uitsluitend de veilige additieve migratie toepassen. Geen fresh/refresh/seed/import of lokale databasekopie.
- Verbind via `php artisan trunkrs:connect`. Bevestig de getoonde toegangsgrens en meld aan via Microsoft. Het refresh-token wordt versleuteld in de serverdatabase bewaard, niet in Git, chat of `.env`.
- Activeer pas na gecontroleerde inrichting de synchronisatie en bestaande serverplanning. Als de hosting de Laravel-scheduler uitvoert, roept die `trunkrs:sync` op de hierboven beschreven ochtendmomenten aan. Controleer de concrete hostingplanning, niet alleen de aanwezigheid van code.
- Voer een eerste servercontrole uit en verifieer mailontvangst, rapportdatum, aantal regels en dashboardresultaat. Een geslaagde login of lege controle bewijst geen correct ingelezen rapport.

Lokaal blijven echte mailboxverzoeken geblokkeerd tenzij daar afzonderlijk expliciet toestemming voor is gegeven; tests gebruiken uitsluitend fictieve gegevens en gesimuleerde Microsoft-antwoorden.

## Bescherming en verificatie

- Identiteit moet overeenkomen met het ingestelde gebruikersobject-ID én de mailboxeigenaar (aanmeldnaam of primair mailadres).
- Eigen-mailboxverzoeken gebruiken `/me/mailFolders/{folder}/messages`; nooit een mailboxbrede berichtenlijst, mailbody, andere map of verzendactie.
- Alleen de vaste afzender en het vaste onderwerp uit `config/trunkrs.php` worden verwerkt; overige berichten krijgen geen bijlageverzoek.
- Verkeerde scopes, extra gedeelde-mailrechten, Mail.Send of Mail.ReadWrite worden geweigerd.
- Paginering blijft exact binnen dezelfde map/resource; ook andere Microsoft-maplinks worden geweigerd.
- Wisselen van modus maakt bestaande tokens onbruikbaar voor de nieuwe configuratie; opnieuw verbinden is verplicht. Bestaande shared-vingerafdrukken blijven compatibel.
- Bestaande rapporten blijven behouden; de browserinrichting voegt alleen een instellingentabel toe en kan ontbrekende oorspronkelijke Trunkrs-tabellen aanmaken. Geen wijzigingen aan Taken, Kalender, Notities of Projecten.

Regressiedekking: `TrunkrsReportTest`, `TrunkrsOwnMailboxTest` en `tests/js/trunkrs.test.cjs`. Geslaagde tests zijn geen bewijs van een werkende live Microsoft-koppeling.

Lokale controle op 22 september 2026: volledige PHP-suite 323 tests / 4132 assertions geslaagd; JavaScript-suite 73 tests geslaagd; Pint op alle gewijzigde PHP-bestanden geslaagd. Geen echte Microsoft-login, productieconfiguratie of rapportimport uitgevoerd.

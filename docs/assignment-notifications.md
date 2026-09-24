# Taak- en projectmeldingen — 24 september 2026

## Eerste versie

- Nieuwe toewijzing aan een taak of toevoeging aan een project maakt een blijvende melding voor ieder nieuw toegevoegd actief teamlid, behalve de uitvoerder zelf.
- Ongewijzigd opslaan en dubbele IDs leveren geen nieuwe melding op. Verwijderen en later opnieuw toevoegen is een nieuwe toewijzing. Er is geen terugwerkende verzending voor bestaande taken.
- Meldingen in de zijbalk: teller, alles/ongelezen, per melding lezen, alles lezen, doorklikken en eigen voorkeuren (ook voor gewone leden). Een zichtbare browser controleert iedere 30 seconden en bij terugkeer naar het tabblad. Geen toestemming voor browserpush nodig.
- Het opslaan van het item, de medewerkers en de meldingen gebeurt in één transactie; updates vergrendelen het item voor een consistente vergelijking. Ontbrekende medewerkersvelden behouden de bestaande koppelingen.
- Alleen de ingelogde ontvanger kan een melding opvragen of lezen. De bestaande gedeelde toegang tot taken/projecten verandert niet. Bij verwijderd doel blijft de melding zichtbaar en volgt een duidelijke fout bij openen.
- Gedeelde computer: meldingen worden bij uitloggen gewist uit het scherm en late antwoorden worden genegeerd.

## E-mail: voorbereid, bewust niet geactiveerd

De release zet **geen echte e-mailverzending aan** en wijzigt geen Microsoft-rechten of productieconfiguratie. De Trunkrs-leeskoppeling blijft ongewijzigd. Verzendrechten en echte ontvangst zijn nog niet operationeel gecontroleerd. Het scherm vermeldt dat e-mail nog uitstaat.

Door Dennis bevestigde afzendernaam: **BBQuality Pitboard**; voorgestelde inrichting met gedeeld adres **pitboard@bbquality.nl**. Dit adres wordt niet stilzwijgend aangemaakt of in productie ingesteld. Microsoft 365 ondersteunt een gedeeld postvak zonder afzonderlijke mailboxlicentie tot 50 GB bij normaal gebruik. De gebruiker die toegang krijgt heeft een bestaande Exchange Online-licentie nodig. Gebruik **Send As / Verzenden als**, niet **Send on behalf / Namens**. Alleen een andere weergavenaam op Dennis' adres voldoet niet.

Bron: https://learn.microsoft.com/en-us/microsoft-365/admin/email/about-shared-mailboxes

### Microsoft-verzendkoppeling via Pitboard

Een beheerder opent **Meldingen → Voorkeuren → Afzender en Microsoft-koppeling beheren**. Deze release voegt een aparte Graph-verzendadapter en beheerpagina toe, zonder de Trunkrs-app of tokens te gebruiken. Inrichting:

1. Controleer of maak het gedeelde postvak met weergavenaam BBQuality Pitboard; direct aanmelden op dat postvak blijft geblokkeerd. Gebruik geen basiswachtwoord en schakel geen tenantbeveiliging uit.
2. Geef een bestaand gelicentieerd account alleen de benodigde **Verzenden als**-machtiging voor de gedeelde afzender. Gebruik niet “Namens”. Microsoft Graph kan deze delegatie niet vooraf uitlezen; controleer dit in Exchange en met het proefbericht.
3. Aparte Entra-app **BBQuality Pitboard - Meldingen**, uitsluitend eigen tenant, openbare clientstromen voor device-code-aanmelding. Alleen gedelegeerd `Mail.Send.Shared`, `User.Read`, `offline_access`; geen toepassingsmachtigingen of mailbox-leesrechten. Een clientgeheim is niet nodig. Nieuwe toestemming en aanmelding blijven expliciete beheerhandelingen.
4. Sla tenant-id, client-id, object-id van het bestaande account en gedeeld afzenderadres in de Pitboard-beheerpagina op. Deze staan versleuteld in de database, niet in `.env`, Git of logs. Wijzigen wist deze verzendaanmelding en schakelt verzending uit.
5. Verbind met Microsoft via de beperkte, sessiegebonden apparaatcode. Alleen het gekozen account wordt geaccepteerd. Verlopen codes, te snel pollen, gewijzigde instellingen en bredere rechten worden geweigerd. Aanmelden alleen activeert nog geen verzending.
6. Stuur expliciet één synthetisch proefbericht naar het e-mailadres van je eigen ingelogde Pitboard-account. Geen vrij invoerbare ontvanger. De poging verbruikt de instellingenversie vóór netwerkverkeer; dezelfde aanvraag kan geen tweede bericht versturen.
7. Een `202 Accepted` is alleen acceptatie door Microsoft. Controleer ontvangst, controlecode, naam/adres en afwezigheid van “namens”. Alleen dezelfde beheerder kan binnen 24 uur na een aangenomen proefbericht expliciet activeren.

De Graph-adapter gebruikt `/me/sendMail` met `from` ingesteld op het gedeelde adres; `sender` wordt door Microsoft bepaald. Hierdoor is geen extra Full Access-machtiging nodig voor deze verzendroute. Een kopie wordt standaard in Verzonden items van het bestaande gebruikersaccount bewaard. De machtiging kan technisch verzenden vanuit andere postvakken waarvoor dat account verzendrechten heeft; de applicatie beperkt de afzender tot de ene ingestelde waarde.

Stoppen wist uitsluitend deze verzendtokens en schakelt deze adapter uit. Er is dan **geen terugval naar SMTP**. Meldingen, gebruikersgegevens en Trunkrs blijven behouden. Toestemming in Entra intrekken is een aparte beheerhandeling. Tokenrotatie gebeurt onder een gedeeld slot; geen onzekere automatische herhaalverzending.

Bronnen: [gedeeld verzenden](https://learn.microsoft.com/en-us/graph/outlook-send-mail-from-other-user), [sendMail en acceptatie](https://learn.microsoft.com/en-us/graph/api/user-sendmail?view=graph-rest-1.0), [apparaatcode-aanmelding](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-device-code).

### Bestaande alternatieve SMTP-adapter

De ingebouwde mailadapter ondersteunt een expliciet geconfigureerde Laravel SMTP-mailer. Pas na controle kan een bevoegde beheerder deze niet-geheime opties instellen (geen instellingen worden door deze release veranderd):

- `PITBOARD_NOTIFICATION_EMAIL_ENABLED=true`
- `PITBOARD_NOTIFICATION_FROM_ADDRESS`: geverifieerd eigen afzenderadres
- `PITBOARD_NOTIFICATION_MAILER`: bestaande SMTP-mailer met geschikte transportauthenticatie
- `APP_URL`: de bevestigde HTTPS-Pitboard-URL

De afzendernaam staat vast op BBQuality Pitboard; geen automatische terugval op het persoonlijke `MAIL_FROM_ADDRESS`. In de lokale omgeving blijft verzending geblokkeerd, zelfs als bovenstaande schakelaar aanstaat. Voorkeuren voor nieuwe taken en projecten staan standaard aan, maar de globale schakelaar staat standaard uit.

Na activatie wordt ieder nieuw bericht na commit via Laravel deferred afgehandeld; geen dagelijkse timer of Mac nodig. De melding bewaart de status `pending`, `processing`, `accepted`, `uncertain`, `cancelled`, `disabled` of `opted_out`. Eén atomische claim voorkomt dubbel versturen door een herhaalde job. `accepted` betekent alleen geaccepteerd door het mailtransport, niet bewezen in de inbox. Onzekere fouten worden niet automatisch herhaald. Uitgeschakelde oude berichten worden na activatie niet alsnog verstuurd. Beheerders zien bij Voorkeuren een waarschuwing voor onzekere of langer dan tien minuten vaststaande mailpogingen. Controleer bij twijfel eerst de verzendbestemming; herhaal niet blind.

## Release en controle

Additieve migratie `2026_09_24_120000_create_pitboard_notifications_tables` maakt uitsluitend `pitboard_notifications` en `pitboard_notification_preferences`. Bestaande gebruikers, taken, projecten, notities en kalender worden niet herschreven. Geen chat-tabellen herstellen of oude data importeren.

De bestaande Composer-installatieroute roept `pitboard:upgrade-notification-storage` aan. Deze draait alleen die ene migratie, onder een slot, en stopt bij verschil tussen migratiehistorie en tabelaanwezigheid. Als de bestaande host die stap niet uitvoert, toont Meldingen een beheeractie **Meldingenopslag voorbereiden**. Alleen een beheerder kan na expliciete bevestiging via een beveiligde POST exact dezelfde vaste migratie uitvoeren; er zijn geen instelbare paden of commando's. Lezen voert nooit een migratie uit. Test bewijst behoud van overige tabellen, autorisatie en herhaalbaarheid. Live moet de daadwerkelijke meldingen-API en voorkeurenpagina werken; alleen nieuwe assets bewijzen geen geslaagde migratie.

De verzendadapter heeft een tweede, volledig aparte additieve migratie `2026_09_24_140000_create_pitboard_mail_settings_table`. `pitboard:upgrade-notification-mail-storage` past uitsluitend die migratie toe en controleert historie en schema. Ook deze staat in de bestaande Composer-installatieroute. De beheerpagina biedt zo nodig **Verzendopslag voorbereiden**, alleen via bevestigde admin-POST. De enkele tabel bevat versleutelde configuratie, een versleuteld refresh-token en versleutelde aanmeldpoging. Geen omgevingsbestanden aanpassen of productiedata vervangen.

Aanvullende tests: admin- en CSRF-controle, versleutelde opslag en geheime velden buiten API-responses, sessiegebonden login, verlopen codes, slow-down, account/scopecontrole, proefontvanger, niet-herhaalbare proefverzending, ontvangen-testbevestiging, lokale verzendblokkade, stop zonder Trunkrs-wijziging, Graph-job met bestaande veilige link en HTML-escaping. Alle Microsoft-verzoeken zijn nagebootst.

Tests: featuretests voor toewijzingen, transacties, zelfmeldingen, duplicaten, inactieve ontvangers, autorisatie, voorkeuren, mailafzender, eenmalige verwerking, fouten, verwijderde doelen en migratiebehoud. E-mails zijn gesimuleerd; geen echte berichten verstuurd. Browserfixture `tests/browser/notifications-router.php` gebruikt uitsluitend fictieve gegevens zonder applicatiedatabase.

Geen meldingen voor historische koppelingen, kalender, notities, deadlinewijzigingen of browserpush in deze eerste versie. Er is geen bewijs van echte e-mailbezorging totdat de afzender en het transport apart zijn ingesteld en gecontroleerd.

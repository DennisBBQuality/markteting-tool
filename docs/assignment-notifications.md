# Taak- en projectmeldingen — 24 september 2026

## Eerste versie

- Nieuwe toewijzing aan een taak of toevoeging aan een project maakt een blijvende melding voor ieder nieuw toegevoegd actief teamlid, behalve de uitvoerder zelf.
- Ongewijzigd opslaan en dubbele IDs leveren geen nieuwe melding op. Verwijderen en later opnieuw toevoegen is een nieuwe toewijzing. Er is geen terugwerkende verzending voor bestaande taken.
- Meldingen in de zijbalk: teller, alles/ongelezen, per melding lezen, alles lezen, doorklikken en eigen voorkeuren (ook voor gewone leden). Een zichtbare browser controleert iedere 30 seconden en bij terugkeer naar het tabblad. Geen toestemming voor browserpush nodig.
- Het opslaan van het item, de medewerkers en de meldingen gebeurt in één transactie; updates vergrendelen het item voor een consistente vergelijking. Ontbrekende medewerkersvelden behouden de bestaande koppelingen.
- Alleen de ingelogde ontvanger kan een melding opvragen of lezen. De bestaande gedeelde toegang tot taken/projecten verandert niet. Bij verwijderd doel blijft de melding zichtbaar en volgt een duidelijke fout bij openen.
- Gedeelde computer: meldingen worden bij uitloggen gewist uit het scherm en late antwoorden worden genegeerd.

## E-mail: voorbereid, bewust niet geactiveerd

De release zet **geen echte e-mailverzending aan** en wijzigt geen Microsoft-rechten of productie-instellingen. De Trunkrs-leeskoppeling blijft ongewijzigd. SMTP- en verzendrechten zijn nog niet operationeel gecontroleerd. Het scherm vermeldt dat e-mail nog uitstaat.

Door Dennis bevestigde afzendernaam: **BBQuality Pitboard**, met een nog te bevestigen eigen adres. Microsoft 365 ondersteunt een gedeeld postvak zonder afzonderlijke mailboxlicentie tot 50 GB bij normaal gebruik. De gebruiker die toegang krijgt heeft een bestaande Exchange Online-licentie nodig. Gebruik **Send As / Verzenden als**, niet **Send on behalf / Namens**. Alleen een andere weergavenaam op Dennis' adres voldoet niet.

Bron: https://learn.microsoft.com/en-us/microsoft-365/admin/email/about-shared-mailboxes

Een gedeeld postvak maakt nog geen werkende applicatieverbinding. Vervolg: afzenderadres kiezen, postvak en Send As-recht in Microsoft inrichten, ondersteunde verzendverbinding kiezen en één expliciet afgesproken proefbericht controleren. De bestaande Graph-koppeling heeft alleen leesrechten; een Microsoft Graph-verzendadapter en afzonderlijke toestemming zijn **niet onderdeel van deze release**. Gebruik geen basiswachtwoord voor een gedeeld postvak en schakel geen tenantbeveiliging uit.

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

Tests: featuretests voor toewijzingen, transacties, zelfmeldingen, duplicaten, inactieve ontvangers, autorisatie, voorkeuren, mailafzender, eenmalige verwerking, fouten, verwijderde doelen en migratiebehoud. E-mails zijn gesimuleerd; geen echte berichten verstuurd. Browserfixture `tests/browser/notifications-router.php` gebruikt uitsluitend fictieve gegevens zonder applicatiedatabase.

Geen meldingen voor historische koppelingen, kalender, notities, deadlinewijzigingen of browserpush in deze eerste versie. Er is geen bewijs van echte e-mailbezorging totdat de afzender en het transport apart zijn ingesteld en gecontroleerd.

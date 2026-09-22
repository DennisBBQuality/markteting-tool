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

## Eenmalige inrichting en controle op de hostingserver

De gebruikelijke GitHub-uitrol levert code, maar verleent geen Microsoft-toestemming en stelt geen mailbox of map in. Productieconfiguratie wordt niet door deze wijziging aangepast. Inrichting vraagt afzonderlijk bevoegde servertoegang.

- `TRUNKRS_MAILBOX_MODE=own` expliciet kiezen.
- Tenant-ID en app/client-ID van de juiste registratie gebruiken.
- `TRUNKRS_READER_USER_ID` is het Entra-object-ID van de **mailboxgebruiker**, niet van de app of het adminaccount.
- `TRUNKRS_MAILBOX` is het primaire mailadres of de aanmeldnaam van diezelfde gebruiker.
- `TRUNKRS_FOLDER_ID` is de gecontroleerde Graph-ID van de afgesproken rapportmap. Een mapnaam alleen volstaat niet. De huidige verwachte map staat als label in `config/trunkrs.php`.
- Verifieer de bestaande Trunkrs-tabellen met de migratiestatus; indien nodig uitsluitend de veilige additieve migratie toepassen. Geen fresh/refresh/seed/import of lokale databasekopie.
- Verbind via `php artisan trunkrs:connect`. Bevestig de getoonde toegangsgrens en meld aan via Microsoft. Het refresh-token wordt versleuteld in de serverdatabase bewaard, niet in Git, chat of `.env`.
- Activeer pas na gecontroleerde inrichting de synchronisatie en bestaande serverplanning. De Laravel-planning roept `trunkrs:sync` elke tien minuten aan; de hosting moet de Laravel-scheduler uitvoeren. Controleer de concrete hostingplanning, niet alleen de aanwezigheid van code.
- Voer een eerste servercontrole uit en verifieer mailontvangst, rapportdatum, aantal regels en dashboardresultaat. Een geslaagde login of lege controle bewijst geen correct ingelezen rapport.

Lokaal blijven echte mailboxverzoeken geblokkeerd tenzij daar afzonderlijk expliciet toestemming voor is gegeven; tests gebruiken uitsluitend fictieve gegevens en gesimuleerde Microsoft-antwoorden.

## Bescherming en verificatie

- Identiteit moet overeenkomen met het ingestelde gebruikersobject-ID én de mailboxeigenaar (aanmeldnaam of primair mailadres).
- Eigen-mailboxverzoeken gebruiken `/me/mailFolders/{folder}/messages`; nooit een mailboxbrede berichtenlijst, mailbody, andere map of verzendactie.
- Alleen de vaste afzender en het vaste onderwerp uit `config/trunkrs.php` worden verwerkt; overige berichten krijgen geen bijlageverzoek.
- Verkeerde scopes, extra gedeelde-mailrechten, Mail.Send of Mail.ReadWrite worden geweigerd.
- Paginering blijft exact binnen dezelfde map/resource; ook andere Microsoft-maplinks worden geweigerd.
- Wisselen van modus maakt bestaande tokens onbruikbaar voor de nieuwe configuratie; opnieuw verbinden is verplicht. Bestaande shared-vingerafdrukken blijven compatibel.
- Bestaande rapporten blijven behouden; er zijn geen nieuwe migraties en geen wijzigingen aan Taken, Kalender, Notities of Projecten.

Regressiedekking: `TrunkrsReportTest`, `TrunkrsOwnMailboxTest` en `tests/js/trunkrs.test.cjs`. Geslaagde tests zijn geen bewijs van een werkende live Microsoft-koppeling.

Lokale controle op 22 september 2026: volledige PHP-suite 323 tests / 4132 assertions geslaagd; JavaScript-suite 73 tests geslaagd; Pint op alle gewijzigde PHP-bestanden geslaagd. Geen echte Microsoft-login, productieconfiguratie of rapportimport uitgevoerd.

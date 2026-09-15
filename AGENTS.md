# BBQuality The Pitboard - Agent Instructions

## Project
Dit is de interne BBQuality marketing- en samenwerkingstool, gebouwd met Laravel 12, PHP 8.2+, Vanilla JavaScript, Tailwind CSS en SQLite/MySQL.

We breiden deze applicatie uit met een klantenservicemodule voor e-mail en WhatsApp.

## Belangrijke regels
- Werk nooit rechtstreeks op `main`.
- Verander geen productieconfiguratie.
- Voeg nooit API-sleutels, wachtwoorden of persoonsgegevens toe aan Git.
- Gebruik alleen lokale testdata.
- Behoud bestaande functionaliteit.
- Pas geen ongerelateerde bestanden aan.
- Databasewijzigingen moeten altijd via Laravel migrations.
- Nieuwe functionaliteit moet passen binnen de bestaande architectuur en vormgeving.
- Voeg waar zinvol tests toe.
- Voer bestaande tests uit voordat een taak wordt afgerond.
- Voer geen destructieve databasecommando's uit zonder expliciete toestemming.
- Verstuur nooit automatisch echte e-mails of WhatsApp-berichten vanuit de lokale omgeving.

## Werkwijze
1. Lees eerst `README.md`.
2. Lees daarna `PROJECT_CONTEXT.md` indien aanwezig.
3. Analyseer de relevante bestaande code.
4. Beschrijf kort het plan voordat je wijzigingen maakt.
5. Werk alleen binnen de gevraagde scope.
6. Voer tests en linting uit.
7. Rapporteer gewijzigde bestanden, uitgevoerde tests, resterende risico's en eventuele vervolgstappen.

## Git
- Gebruik een duidelijke feature branch.
- Maak kleine, logische wijzigingen.
- Commit geen `.env`, databases, logs, uploads of andere lokale bestanden.

## Vaste live-uitrolroute
- Op 15 september 2026 opnieuw expliciet bevestigd door Dennis: wijzigingen via GitHub naar `main` brengen start automatisch de bestaande live-uitrol naar `https://planning.bbquality.nl/`.
- Repository: `DennisBBQuality/markteting-tool`. Werk op een feature branch, push deze en voeg de geteste release via een pull request samen naar `main`; een feature-branch-push alleen is nog geen bevestigde live-release.
- Vraag deze bekende hosting-/uitrolroute niet opnieuw uit. Gebruik de bestaande automatisering; zet geen nieuwe hosting op en verander geen productieconfiguratie.
- Neem noodzakelijke veilige Laravel-migraties mee, maar vervang nooit de productiedatabase door lokale data. Bestaande Taken, Kalender, Notities en Projecten moeten behouden blijven; geen fresh, refresh, seed of import bij een release.
- Controleer na de uitrol wat daadwerkelijk live verifieerbaar is. Een geslaagde push of actuele frontend-assets bewijzen op zichzelf niet dat een nieuwe databasekolom is toegepast; benoem een niet-verifieerbare backendcontrole precies in plaats van de bekende route opnieuw ter discussie te stellen.

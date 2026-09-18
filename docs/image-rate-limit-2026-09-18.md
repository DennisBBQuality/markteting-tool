# Fotogenerator — geïsoleerde verzoeklimieten

## Oorzaak en scope

De bestaande numerieke Laravel-throttles gebruikten dezelfde sleutel voor meerdere routes. De eigen sessie-authenticatie vult de standaard Laravel-guard niet, waardoor die sleutel gebaseerd was op het IP-adres. Modellenlijst/SEO/andere begrensde acties konden daarom het budget van drie fotostarts vullen; collega's op hetzelfde netwerk deelden dat budget ook.

Dit is gereproduceerd met gesimuleerde gegevens: vier modelreads blokkeren de eerste fotostart, en een tweede gebruiker op hetzelfde IP wordt door de eerste geblokkeerd. Beide regressietests faalden vóór de reparatie.

## Correctie

- Zes afzonderlijke named limiters voor de bestaande foto-endpoints, op gevalideerde gebruikers-ID. Ongewijzigde aantallen per minuut: modellenlijst 30, verversen 3, genereren 3, SEO 12, nabewerken 6, stijlbibliotheek 12.
- De eigen authenticatiemiddleware wordt vóór throttling uitgevoerd, zodat het actieve account al gecontroleerd is. Anonieme/inactieve accounts blijven geweigerd.
- Wisselen van sessie, foto of fotoset geeft dezelfde gebruiker geen nieuw budget voor dezelfde functie.
- Nederlandse 429-melding met Retry-After. De generator verwerkt ook niet-JSON 429-antwoorden, bewaart invoer/referenties/resultaten en herhaalt geen betaalde POST automatisch.
- Geen database-, hostingconfiguratie- of productdatawijzigingen. Geen nieuwe livefoto's gestart voor deze reparatie.

## Controle

- PHP: 315 tests, 4086 assertions geslaagd.
- JavaScript: 73 tests geslaagd.
- Pint voor gewijzigde PHP-bestanden en git diff --check geslaagd.
- Huidige Live-tab bij onderzoek toonde inmiddels de nieuwe fotoset met SEO 2/2 klaar; geen herstart door de agent.
- Uitrol via feature branch, pull request en main, volgens de bestaande GitHub-route. Livecontrole volgt na de merge; lokale tests alleen bewijzen geen uitrol.

# SEO-voltooiing en foutdiagnose

## Reparatie

- De fotoset krijgt pas de API-/UI-status `completed` als alle vijf SEO-velden van iedere actuele fotoversie geldig opgeslagen zijn.
- Fotogeneratie en SEO-voortgang worden apart bijgehouden. Bij mislukte SEO blijven afbeeldingen en herstelverwijzing beschikbaar; alleen SEO hoeft opnieuw.
- WEBP-export vereist volledige SEO. Polling behoudt etiketcontrole en niet-opgeslagen foto-aanpassingen; nieuwe fotoversies erven geen etiketgoedkeuring.
- Ook een foto-aanpassing meldt pas gereed na SEO. Onvolledige of geweigerde AI-antwoorden gelden niet als afgerond.
- Ontbrekende bestandsnaamopslag, verbindingsproblemen en databasefouten krijgen onderscheidbare, veilige meldingen. De opslagcontrole gebeurt vóór een betaalde analyse.
- Prompt volgt Google Search Central: beschrijvende namen, nuttige alt-tekst en relevante tekst zonder zoekwoordenstapeling of verzonnen details. Alle vijf velden verplicht is de BBQuality-opleverregel, geen vijfdelige Google-verplichting: https://developers.google.com/search/docs/appearance/google-images.

## Veiligheid en controle

Geen nieuwe migratie, productieconfiguratie, sleutels, uploads of operationele data toegevoegd. De bestaande migratie `2026_09_18_100000_create_product_image_download_names_table` moet op Live aanwezig zijn. Deze reparatie voert geen schemawijziging vanuit een webrequest uit.

Lokaal: 303 PHP-tests (3966 assertions) en 71 JavaScript-tests geslaagd; Pint en diffcontrole geslaagd.

Bij oplevering nog live verifiëren: oorzaak van mislukte SEO en volledige opslag van alle vijf velden bij de getroffen foto's. Een geslaagde push of assetcontrole alleen bewijst dat niet.

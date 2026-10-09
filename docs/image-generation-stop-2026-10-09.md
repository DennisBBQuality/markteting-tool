# Stoppen van fotosetgeneratie en SEO

## Scope en werking

- Eigenaar kan via `POST /api/images/requests/{id}/cancel` stoppen; bestaande sessie- en CSRF-beveiliging behouden.
- `cancelled` wordt direct persistent vastgelegd. Bij actieve beeldgeneratie blijft `progress_step=cancelling` zichtbaar tot de reeds verzonden groep terugkomt; queued werk stopt onmiddellijk.
- De generator verstuurt groepen van maximaal twee aanvragen. Vóór elke groep wordt de opgeslagen stop gecontroleerd. Geldige foto's die nog terugkomen blijven bewaard; geen automatische SEO erna.
- SEO-stop trekt queued/processing jobtokens in. Late antwoorden en late foutcallbacks kunnen daardoor geen nieuwe velden opslaan. Voltooide en handmatige velden blijven behouden.
- Eén foto stoppen kan via `/assets/{asset}/seo/cancel`, met versie/revisiecontrole. Expliciet opnieuw SEO maken blijft mogelijk.
- Afzonderlijke nabewerking en WEBP-conversie vallen buiten deze knoppen. Al verzonden providerwerk kan niet worden teruggeroepen; kosten kunnen blijven gelden. Een stop is geen bevestigde providerannulering.
- Geen migratie, credentials, productieconfiguratie of wijzigingen aan andere modules.

## Controle

Geautomatiseerde tests gebruiken uitsluitend fictieve foto's en gesimuleerde AI-antwoorden. Gecontroleerd: queued stop, herhaald stoppen, eigenaargrens, stoppen tijdens een HTTP-groep, bewaren/downloaden van gedeeltelijke fotoset, stoppen tijdens de eerste SEO-groep, geen volgende groepen, late antwoorden/fouten, expliciet opnieuw starten, onopgeslagen invoer en verouderde browserpolling.

Geslaagd: 434 PHP-tests (5980 assertions), 131 JavaScript-tests, 6 Python-tests, Pint en diffcontrole. De geïsoleerde Chrome-test bevestigde de overgang van Stoppen via Stop aangevraagd naar Opdracht handmatig gestopt. Er zijn geen echte AI-opdrachten gestart of gestopt voor deze controle.

Bronnen op 9 oktober 2026 live gelezen: Start hier, Teamwerkwijze, AI & automatisering, Lokale testomgeving. Projectinstructies en bestaande release-route gevolgd. Providerannulering en terugbetaling worden niet geclaimd.

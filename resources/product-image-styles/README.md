# BBQuality productbeeld-stijlbibliotheek

Deze map bevat de op 3 september 2026 door BBQuality goedgekeurde AI-voorbeelden. Ze worden uitsluitend als visuele stijlreferentie naar het gekozen GPT Image-model gestuurd. Goedkeuring van de sfeer is geen bewijs van echte fotografie of van een geschikte vleesstructuur voor ieder product/model.

De echte productfoto's blijven altijd leidend voor productidentiteit, vorm, verhoudingen, hoeveelheid, kleur, vetverdeling, marmering, verpakking en etikettekst. Het model mag uit deze voorbeelden alleen sfeer, licht, camerastandpunt, compositie en het type omgeving overnemen.

De bibliotheek bevat afzonderlijke families voor buiten-BBQ, serveermomenten, BBQuality-flessen en totaalpakketten. De twee bereide varianten van een vleesproduct gebruiken altijd verschillende scènefamilies. Eén rauwe variant gebruikt een lege achtergrondplaat die is afgeleid van een echte BBQuality-foto, met zwart achtervlak en warm hout. Door het voorbeeldproduct uit die plaat te verwijderen kan het model daar geen verkeerde vleesvorm meer van overnemen. Deze referentie mag nooit voor een bereid beeld worden gebruikt. De tweede rauwe variant krijgt geen stijlreferentie, zodat er daarnaast een vrijer alternatief blijft.

## Bereide varianten: beperkte referentierol vanaf 11 september 2026

Voor bereid vlees gaan deze beelden uitsluitend over type omgeving, camerahoek en ruimtelijke opbouw. Korst, vleesvezels, vocht, glaze, kruiding en scherpte mogen niet worden overgenomen. De eigen instructie `FOTOGRAFIE BEREID VLEES` bepaalt zacht licht en natuurlijke materiaalweergave, ook wanneer het sfeerbeeld hard licht of glanzend, korrelig vlees bevat. De bestanden en de rauwe achtergrond zijn niet bewerkt of vervangen.

Een afzonderlijk door het team opgeslagen, goedgekeurd voorbeeld voor exact hetzelfde product en dezelfde variantstijl vervangt nog steeds het gebundelde bereide sfeerbeeld. Dat blijft een kwaliteitsanker voor bereiding en presentatie, met behoud van de nieuwe echte productreferenties als bron voor identiteit en hoeveelheid. Kopieer ook hier geen overmatige korrels, glans of verscherping. Voeg afgekeurde testresultaten niet als nieuwe kwaliteitsreferentie toe. Een echte bereide productfoto kan later een betere referentie bieden; deze wijziging voegt die niet automatisch toe.

## Vis: afzonderlijke rauwe achtergrond vanaf 16 september 2026

`vis-rauw-zwart.png` is een ongewijzigde kopie van de echte zalmfoto die Dennis op 16 september aanleverde als gewenst achtergrondvoorbeeld. Uitsluitend gebruikt voor `vis_rauw_zwart`: zwart achtervlak, zwarte ondergrond, subtiele textuur en reflectie. Dit is nadrukkelijk géén lege plaat: het voorbeeldproduct mag niet worden gekopieerd. De andere twee aangeleverde echte voorbeelden (hele vis en kreeftenstaart) onderbouwen dezelfde setting, maar worden niet extra meegestuurd. De afgekeurde rechtopstaande zalmhaas op de vleesachtergrond is niet opgenomen.

`vis_rauw_hout` heeft geen gebundelde stijlreferentie en mag wel een lichte houten plank gebruiken. Bereide vis deelt de bestaande kamado- en serveerplank-sfeerbeelden; de visprompt beperkt hun rol tot omgeving en compositie, niet het vlees of de gaarheid. Opgeslagen kwaliteitsankers blijven gescheiden op producttype, productnaam, status en stijl-ID. De bestaande vleesreferenties zijn ongewijzigd.

## Stoofgerechten

Voor sucade/sukade en expliciet genoemde stoof-/sudderproducten bestaan de stijlen `bbq_buiten_stoof` en `serveerbeeld_stoof`. Deze gebruiken bewust geen gebundelde kamado-, steak- of brisketfoto: de bord-/panpresentatie en stoofstructuur worden in de prompt beschreven. Een goedgekeurd voorbeeld wordt alleen hergebruikt bij hetzelfde product en dezelfde nieuwe stoofstijl. Oudere foto's onder `bbq_buiten_algemeen` of `serveerbeeld_algemeen` blijven bewaard, maar worden niet automatisch als stoofvoorbeeld geselecteerd. Dennis' aangeleverde `Stoofgerecht final.webp` is inhoudelijk de presentatierichting voor deze wijziging; het bestand is niet automatisch geïmporteerd of als nieuwe bibliotheekreferentie opgeslagen.

## Kiesbare keukenvarianten (16 september 2026)

`keuken-pan.png`, `keuken-oven.png` en `keuken-airfryer.png` zijn ongewijzigde kopieën van de drie door Dennis in deze opdracht aangeleverde stijlvoorbeelden. Elk wordt uitsluitend bij de bijbehorende gekozen variant voor Vlees of Vis gebruikt. Bron: bijlagen met respectievelijk pan/fornuis, huishoudelijke oven en airfryer. Geen bewijs van echte fotografie, receptuur of geschiktheid van ieder product voor een apparaat.

Alleen setting, apparaat, compositie en licht/kleur mogen worden overgenomen. Het voorbeeldgerecht, de kipstukken in de airfryer, aantallen, garing, korst en voedselstructuur mogen nooit een ander product bepalen of toevoegen. De daadwerkelijke productreferenties blijven leidend. Nieuwe stijl-ID's `keuken_{pan|oven|airfryer}_{productfamilie}` scheiden goedgekeurde kwaliteitsankers van elkaar en van de eerdere generieke keukenstijl. Deze uitbreiding wijzigt de bestaande rauwe en BBQ-voorbeelden niet.
# Uitbreiding keukenachtergronden — 17 september 2026

Tien aanvullende screenshots van Dennis, ongewijzigd opgenomen. Samen met de drie eerdere beelden zijn er vier Pan-, vijf Oven- en vier Airfryer-achtergronden. Opeenvolgende aanvragen van dezelfde gebruiker doorlopen per groep de reeks; tussentijdse opdrachten zonder die groep veranderen de reeks niet. Gelijktijdig ingestuurde aanvragen kunnen dezelfde stand lezen. De gekozen referentie-ID wordt vóór generatie in de opdrachtcontext vastgelegd. Oude opdrachten zonder zo'n ID behouden hun oorspronkelijke referentie.

Bronmapping (screenshot-ID → lokaal bestand):
- Pan: `6a4c77c8-b0b5-4da1-8f57-aa49bd3c03fc` → `keuken-pan-02.png`; `914b8427-1535-4ec1-8446-a4f1413b6d85` → `keuken-pan-03.png`; `d5c281f8-e3b3-4529-9e03-1b79e8ca0b1d` → `keuken-pan-04.png`.
- Oven: `133261da-228a-4d0c-afaf-9b7a28cc3747` → `keuken-oven-02.png`; `c7057067-9eb7-452e-82eb-5fe77c5291ae` → `keuken-oven-03.png`; `c1b13750-ef43-4753-b2a1-a0e15f210c48` → `keuken-oven-04.png`; `971a4a87-5abc-435f-9c5f-bd94d06bc80d` → `keuken-oven-05.png`.
- Airfryer: `61653fc8-b81f-4fbf-a21f-062d6917e646` → `keuken-airfryer-02.png`; `b6a27050-40e9-4cae-9e1e-9c132673259c` → `keuken-airfryer-03.png`; `5ef50e97-8c01-4864-b3ca-31ed6c8366a9` → `keuken-airfryer-04.png`.

Alleen setting, licht, kleur en compositie: geen kopie van het voorbeeldgerecht, hoeveelheid, garing, logo's/displayteksten of screenshotranden. Het hoofdproduct kan in de pan, op de bakplaat of in de mand worden gepresenteerd, mits passend; nooit dezelfde portie nogmaals ernaast. Bij stoofvlees blijven jus en zachte structuur leidend. Een passend goedgekeurd productvoorbeeld mag de afwisselende keukenachtergrond niet vervangen. Deze wijziging bewerkt geen bronpixels en is nog geen visuele goedkeuring van echte gegenereerde resultaten.

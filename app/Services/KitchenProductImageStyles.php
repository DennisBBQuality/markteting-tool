<?php

namespace App\Services;

/** Approved domestic settings; the example food never defines the generated product. */
class KitchenProductImageStyles
{
    public static function key(string $reference): string
    {
        return preg_match('/^keuken_(?:pan|oven|airfryer)_(warm|landelijk|donker|mediterraan)$/', $reference, $match)
            ? $match[1] : 'licht';
    }

    public static function label(string $reference): string
    {
        return match (self::key($reference)) {
            'warm' => 'Warm modern',
            'landelijk' => 'Landelijk',
            'donker' => 'Donker eigentijds',
            'mediterraan' => 'Mediterraan huiselijk',
            default => 'Licht modern',
        };
    }

    public static function environment(string $reference): string
    {
        return match (self::key($reference)) {
            'warm' => 'Een warme moderne woonkeuken met natuurlijk eiken, greige tinten en een warmgrijs stenen werkblad. Een iets hoger diagonaal camerastandpunt zoals het stijlvoorbeeld. Rustige huiselijke sfeer en zachte neutrale daglichtkleuren; geen felwitte showroom.',
            'landelijk' => 'Een landelijke woonkeuken met gedempt saliegroene kaderkastjes, een eiken werkblad en crème keramische tegels. Een huiselijk, gebruikt maar verzorgd aanrecht. Een schuin camerastandpunt met de keuken herkenbaar achter het gerecht, zoals het stijlvoorbeeld.',
            'donker' => 'Een donkere eigentijdse woonkeuken met diepblauwe matte kastjes, antraciet natuursteen en walnoothouten details. Zacht zijlicht maakt het hoofdproduct helder en smakelijk terwijl de keuken donkerder blijft. Geen dichtgelopen schaduwen, zwarte studiowand of restaurantkeuken. Neem het diagonale camerastandpunt uit het stijlvoorbeeld over.',
            'mediterraan' => 'Een mediterrane huiselijke woonkeuken met een zacht terracotta pleisterwand, ivoorkleurige tegels en natuurlijke eiken kastjes. Een ruimere serveercompositie op het aanrecht met herkenbare keuken en apparaat achter het bord, zoals het stijlvoorbeeld. Warmte komt uit de materialen, niet uit een oranje kleurfilter.',
            default => 'Een lichte moderne woonkeuken in een echt woonhuis, met zacht diffuus raamlicht, rustige gebroken witte en zandkleurige tinten, licht steen en natuurlijk hout.',
        };
    }

    public static function presentation(string $group, string $reference): string
    {
        $style = self::key($reference);
        if ($style === 'mediterraan' || ($group === 'airfryer' && $style === 'landelijk')) {
            return 'Het hoofdproduct staat op een licht keramisch bord op de voorgrond, zoals het stijlvoorbeeld. Het gekozen apparaat is herkenbaar achter het bord. Een pan of oven op de achtergrond is leeg; het eten staat uitsluitend op het bord. De airfryerlade is gesloten. Geen tweede portie.';
        }
        if ($group === 'pan' && ($style !== 'licht' || in_array($reference, ['keuken_pan_02', 'keuken_pan_03', 'keuken_pan_04'], true))) {
            return 'Het hoofdproduct ligt IN een passende pan op de kookplaat, met de gekozen keukenachtergrond zoals het stijlvoorbeeld. Gebruik bij de landelijke stijl een huishoudelijk gasfornuis, bij warm modern en donker eigentijds inductie. Geen tweede portie ernaast.';
        }
        if ($group === 'oven' && ($style !== 'licht' || in_array($reference, ['keuken_oven_02', 'keuken_oven_03', 'keuken_oven_05'], true))) {
            return 'Het hoofdproduct ligt op een bakplaat op een hittebestendige onderzetter voor de open huishoudoven, eventueel op bakpapier zoals het stijlvoorbeeld. Geen extra voedsel in de oven.';
        }
        if ($group === 'airfryer' && (in_array($style, ['warm', 'donker'], true) || $reference === 'keuken_airfryer_04')) {
            return 'Het hoofdproduct ligt in de geopende airfryermand zoals het stijlvoorbeeld, uitsluitend als formaat en productbereiding dat geloofwaardig toelaten. Geen tweede portie ernaast. Stoofvlees met jus wordt in een passende schaal ernaast gepresenteerd, niet los in een geperforeerde mand.';
        }

        return 'Het gerecht staat op de voorgrond op een bord of houten serveerplank, eventueel met bakpapier, zoals het stijlvoorbeeld.';
    }
}

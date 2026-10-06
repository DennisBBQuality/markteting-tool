<?php

use App\Services\ProductImageSeoAnalyzer;
use Illuminate\Contracts\Console\Kernel;

// Opt-in, three paid text/vision calls using synthetic local fixtures only.
// No image generation, database writes, uploads to Pitboard or publication.
require __DIR__.'/../vendor/autoload.php';

if (($argv[1] ?? '') !== '--run') {
    fwrite(STDERR, "Use --run to perform exactly three real SEO analyses with the configured API.\n");
    exit(1);
}

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('services.product_content.endpoint') !== 'https://api.openai.com/v1/responses') {
    fwrite(STDERR, "Expected official Responses endpoint; no requests made.\n");
    exit(1);
}

function fixture(bool $jar): string
{
    $image = imagecreatetruecolor(512, 512);
    $background = imagecolorallocate($image, 235, 235, 235);
    $black = imagecolorallocate($image, 30, 30, 30);
    $brown = imagecolorallocate($image, 150, 75, 25);
    imagefill($image, 0, 0, $background);
    if ($jar) {
        imagefilledrectangle($image, 160, 130, 340, 390, $brown);
        imagefilledrectangle($image, 150, 100, 350, 140, $black);
    } else {
        imagefilledrectangle($image, 140, 110, 290, 400, $black);
        imagerectangle($image, 290, 200, 360, 350, $black);
        imagefilledellipse($image, 180, 360, 26, 26, $background);
        imagefilledellipse($image, 250, 360, 26, 26, $background);
    }
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    imagedestroy($image);

    return $png;
}

$cases = [
    ['name' => 'TEST brikettenstarter', 'type' => 'accessory', 'jar' => false,
        'notes' => 'Fictieve testdata: brikettenstarter van Proefmerk. Steekt houtskool en briketten gelijkmatig aan. Ook houtskoolstarter genoemd. Geen aanmaakvloeistof nodig. Werkt met aanmaakblokjes of houtwol. De koker en ventilatieopeningen zorgen voor luchttoevoer.'],
    ['name' => 'TEST rub', 'type' => 'sauce', 'jar' => true,
        'notes' => 'Fictieve testdata: TEST rub van Proefmerk met gerookte paprika en knoflook. Voor het kruiden van geroosterde groenten. Gebruik de rub vóór het roosteren. De smaak is rokerig en hartig. Niet geschikt als saus.'],
    ['name' => 'TEST kruidenpot', 'type' => 'sauce', 'jar' => true, 'notes' => 'TEST kruidenpot'],
];
foreach ($cases as $case) {
    try {
        $fields = app(ProductImageSeoAnalyzer::class)->analyze(fixture($case['jar']), [
            'product_name' => $case['name'], 'product_type' => $case['type'], 'notes' => $case['notes'],
        ], ['status' => 'product']);
        echo json_encode(['case' => $case['name'], 'source' => $case['notes'], 'fields' => $fields], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
    } catch (Throwable $exception) {
        // No provider payloads or credentials in output; do not retry a paid call.
        fwrite(STDERR, $case['name'].': '.get_class($exception)."; no retry.\n");
        exit(1);
    }
}

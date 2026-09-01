<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiProductImageGenerator implements ProductImageGenerator
{
    private const INPUT_CANVAS_SIZE = 1024;

    private const INPUT_CONTENT_SIZE = 960;

    private const MAX_INPUT_BYTES = 4 * 1024 * 1024;

    private const VARIANT_PROMPTS = [
        'bereid' => 'MANDATORY VARIANT: Show the meat fully prepared and cooked in an appetising way appropriate for this exact cut. It may be grilled, roasted or smoked, with a realistic safe doneness. Preserve recognition of the original cut. Create subtle visual variety between the two outputs through camera angle, composition or surface, without adding side dishes, text or packaging.',
        'rauw' => 'MANDATORY VARIANT: Show the meat completely raw and uncooked, outside all packaging. Preserve the natural raw colour, marbling, fat and texture of this exact cut. Create subtle visual variety between the two outputs through camera angle, composition or surface, without adding seasoning, garnish, text or packaging.',
    ];

    public function __construct(private readonly AiCredentialStore $credentials) {}

    public function generate(UploadedFile $source, string $basePrompt): array
    {
        $apiKey = $this->credentials->openAiApiKey() ?? '';

        if ($apiKey === '') {
            throw new ProductImageGenerationException('De OpenAI API-sleutel is niet ingesteld.');
        }

        $normalizedSource = $this->normalizeSource($source);
        $results = [];

        foreach (self::VARIANT_PROMPTS as $status => $variantPrompt) {
            foreach ($this->requestVariants($normalizedSource, trim($basePrompt)."\n\n".$variantPrompt, $apiKey) as $contents) {
                $results[] = [
                    'status' => $status,
                    'contents' => $contents,
                    'extension' => 'png',
                ];
            }
        }

        if (count($results) !== 4) {
            throw new ProductImageGenerationException('De beeldservice leverde niet exact vier afbeeldingen op.');
        }

        return $results;
    }

    /** @return list<string> */
    private function requestVariants(string $sourcePng, string $prompt, string $apiKey): array
    {
        $model = (string) config('services.product_images.openai.model');
        $parameters = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => 2,
            'size' => (string) config('services.product_images.openai.size'),
        ];

        $parameters['output_format'] = 'png';
        $parameters['quality'] = (string) config('services.product_images.openai.quality', 'high');
        $parameters['input_fidelity'] = (string) config('services.product_images.openai.input_fidelity', 'high');
        $parameters['background'] = 'opaque';

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout((int) config('services.product_images.openai.timeout', 240))
                ->connectTimeout(15)
                ->attach('image', $sourcePng, 'reference.png', [
                    'Content-Type' => 'image/png',
                ])
                ->post((string) config('services.product_images.openai.endpoint'), $parameters);
        } catch (ConnectionException) {
            throw new ProductImageGenerationException('De beeldservice is momenteel niet bereikbaar.');
        }

        if (! $response->successful()) {
            Log::warning('Product image generation request failed.', [
                'status' => $response->status(),
                'request_id' => $response->header('x-request-id'),
                'error_code' => $response->json('error.code'),
            ]);

            throw new ProductImageGenerationException('De beeldservice kon de productfoto niet maken.');
        }

        $encodedImages = $response->json('data');

        if (! is_array($encodedImages) || count($encodedImages) !== 2) {
            throw new ProductImageGenerationException('De beeldservice leverde een onvolledig resultaat op.');
        }

        $maxBytes = (int) config('services.product_images.max_output_bytes', 20 * 1024 * 1024);
        $images = [];

        foreach ($encodedImages as $image) {
            $contents = is_array($image) ? base64_decode((string) ($image['b64_json'] ?? ''), true) : false;

            if ($contents === false || $contents === '' || strlen($contents) > $maxBytes) {
                throw new ProductImageGenerationException('De beeldservice leverde een ongeldige afbeelding op.');
            }

            $images[] = $contents;
        }

        return $images;
    }

    private function normalizeSource(UploadedFile $source): string
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new ProductImageGenerationException('De server kan de geüploade afbeelding niet voorbereiden.');
        }

        $sourceContents = @file_get_contents($source->getRealPath());
        $sourceImage = is_string($sourceContents) ? @imagecreatefromstring($sourceContents) : false;

        if ($sourceImage === false) {
            throw new ProductImageGenerationException('De geüploade afbeelding kon niet worden gelezen.');
        }

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        $canvas = imagecreatetruecolor(self::INPUT_CANVAS_SIZE, self::INPUT_CANVAS_SIZE);

        if ($sourceWidth < 1 || $sourceHeight < 1 || $canvas === false) {
            imagedestroy($sourceImage);

            throw new ProductImageGenerationException('De geüploade afbeelding kon niet worden voorbereid.');
        }

        $background = imagecolorallocate($canvas, 248, 248, 246);
        imagefill($canvas, 0, 0, $background);

        $scale = min(self::INPUT_CONTENT_SIZE / $sourceWidth, self::INPUT_CONTENT_SIZE / $sourceHeight);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        $targetX = (int) floor((self::INPUT_CANVAS_SIZE - $targetWidth) / 2);
        $targetY = (int) floor((self::INPUT_CANVAS_SIZE - $targetHeight) / 2);

        $copied = imagecopyresampled(
            $canvas,
            $sourceImage,
            $targetX,
            $targetY,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );
        imagedestroy($sourceImage);

        if (! $copied) {
            imagedestroy($canvas);

            throw new ProductImageGenerationException('De geüploade afbeelding kon niet worden voorbereid.');
        }

        ob_start();
        $encoded = imagepng($canvas, null, 9);
        $png = ob_get_clean();
        imagedestroy($canvas);

        if (! $encoded || ! is_string($png) || $png === '' || strlen($png) > self::MAX_INPUT_BYTES) {
            throw new ProductImageGenerationException('De geüploade afbeelding kon niet binnen de API-limiet worden voorbereid.');
        }

        return $png;
    }
}

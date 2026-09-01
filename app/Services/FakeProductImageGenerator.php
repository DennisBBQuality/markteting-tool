<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class FakeProductImageGenerator implements ProductImageGenerator
{
    private const PLACEHOLDER_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAFAgI/69VZ5QAAAABJRU5ErkJggg==';

    public function generate(UploadedFile $source, string $basePrompt): array
    {
        $contents = base64_decode(self::PLACEHOLDER_PNG, true);

        if ($contents === false) {
            throw new ProductImageGenerationException('De lokale voorbeeldafbeelding kon niet worden gemaakt.');
        }

        return [
            ['status' => 'bereid', 'contents' => $contents, 'extension' => 'png'],
            ['status' => 'bereid', 'contents' => $contents, 'extension' => 'png'],
            ['status' => 'rauw', 'contents' => $contents, 'extension' => 'png'],
            ['status' => 'rauw', 'contents' => $contents, 'extension' => 'png'],
        ];
    }
}

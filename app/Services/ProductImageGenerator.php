<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

interface ProductImageGenerator
{
    /**
     * @return list<array{status: 'bereid'|'rauw', contents: string, extension: 'png'|'webp'|'jpeg'}>
     */
    public function generate(UploadedFile $source, string $basePrompt): array;
}

<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

interface ProductImageWorkflowGenerator
{
    /** @param list<UploadedFile> $sources */
    public function generateForProduct(
        array $sources,
        string $basePrompt,
        array $context,
        ?callable $reportProgress = null,
    ): array;
}

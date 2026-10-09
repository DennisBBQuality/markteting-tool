<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

interface ProductImageWorkflowGenerator
{
    /**
     * @param  list<UploadedFile>  $sources
     * @param  null|callable(string, int): (bool|void)  $reportProgress  False stops before the next provider wave and returns available images.
     */
    public function generateForProduct(
        array $sources,
        string $basePrompt,
        array $context,
        ?callable $reportProgress = null,
    ): array;
}

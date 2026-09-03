<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

interface ProductImageRefiner
{
    public function refine(UploadedFile $source, string $instruction, array $context = []): string;
}

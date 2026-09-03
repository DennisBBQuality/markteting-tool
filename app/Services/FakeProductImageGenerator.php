<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class FakeProductImageGenerator implements ProductImageGenerator, ProductImageWorkflowGenerator, ProductImageRefiner
{
    private const PLACEHOLDER_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAFAgI/69VZ5QAAAABJRU5ErkJggg==';

    public function generate(UploadedFile $source, string $basePrompt, ?callable $reportProgress = null): array
    {
        if ($reportProgress) {
            $reportProgress('preparing', 15);
        }
        $contents = base64_decode(self::PLACEHOLDER_PNG, true);

        if ($contents === false) {
            throw new ProductImageGenerationException('De lokale voorbeeldafbeelding kon niet worden gemaakt.');
        }

        if ($reportProgress) {
            $reportProgress('generating_prepared', 30);
            $reportProgress('generating_raw', 60);
        }

        return [
            ['status' => 'bereid', 'contents' => $contents, 'extension' => 'png'],
            ['status' => 'bereid', 'contents' => $contents, 'extension' => 'png'],
            ['status' => 'rauw', 'contents' => $contents, 'extension' => 'png'],
            ['status' => 'rauw', 'contents' => $contents, 'extension' => 'png'],
        ];
    }

    public function generateForProduct(array $sources, string $basePrompt, array $context, ?callable $reportProgress = null): array
    {
        if ($reportProgress) {
            $reportProgress('preparing', 15);
        }
        $contents = base64_decode(self::PLACEHOLDER_PNG, true);
        if (! is_string($contents)) {
            throw new ProductImageGenerationException('De lokale voorbeeldafbeelding kon niet worden gemaakt.');
        }

        $plans = app(ProductImagePromptBuilder::class)->plans($context);
        $results = [];
        foreach ($plans as $index => $plan) {
            if ($reportProgress) {
                $reportProgress('generating_product', 25 + ($index * 20));
            }
            $results[] = [
                'status' => $plan['status'],
                'label' => $plan['label'],
                'style_id' => $plan['style_id'],
                'contents' => $contents,
                'extension' => 'png',
            ];
        }

        return $results;
    }

    public function refine(UploadedFile $source, string $instruction, array $context = []): string
    {
        $contents = file_get_contents($source->getRealPath());
        if (! is_string($contents) || $contents === '') {
            throw new ProductImageGenerationException('De lokale voorbeeldafbeelding kon niet worden aangepast.');
        }

        return $contents;
    }
}

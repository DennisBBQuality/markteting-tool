<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductImageUploadScriptTest extends TestCase
{
    public function test_selected_files_are_copied_before_the_live_file_input_is_reset(): void
    {
        $javascript = file_get_contents(__DIR__.'/../../public/js/converter.js');

        $this->assertIsString($javascript);

        $handlerStart = strpos($javascript, 'function handleProductImageFiles(fileList)');
        $copyPosition = strpos($javascript, 'const selectedFiles = Array.from(fileList || []);', $handlerStart);
        $resetPosition = strpos($javascript, "if (input) input.value = '';", $handlerStart);

        $this->assertNotFalse($handlerStart);
        $this->assertNotFalse($copyPosition);
        $this->assertNotFalse($resetPosition);
        $this->assertLessThan($resetPosition, $copyPosition);
        $this->assertStringContainsString('for (const file of selectedFiles)', $javascript);
    }
}

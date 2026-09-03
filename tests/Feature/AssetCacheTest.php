<?php

namespace Tests\Feature;

use Tests\TestCase;

class AssetCacheTest extends TestCase
{
    public function test_app_shell_is_not_cached_and_local_assets_have_a_version(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);

        $appShell = file_get_contents(public_path('index.html'));
        $this->assertIsString($appShell);
        $this->assertStringContainsString('/css/style.css?v=20260903-1', $appShell);
        $this->assertStringContainsString('/js/app.js?v=20260903-1', $appShell);
        $this->assertStringContainsString('/js/converter.js?v=20260903-2', $appShell);
        $this->assertStringContainsString('/js/settings.js?v=20260903-1', $appShell);
    }
}

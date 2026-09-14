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
        $this->assertStringContainsString('/css/style.css?v=20260911-1', $appShell);
        $this->assertStringContainsString('/js/app.js?v=20260914-1', $appShell);
        $this->assertStringContainsString('/js/image-models.js?v=20260911-1', $appShell);
        $this->assertStringContainsString('/js/converter.js?v=20260911-1', $appShell);
        $this->assertStringContainsString('/js/product-dossiers.js?v=20260909-1', $appShell);
        $this->assertStringContainsString('/js/settings.js?v=20260911-1', $appShell);
        $this->assertStringContainsString('/js/trunkrs.js?v=20260914-1', $appShell);
        $this->assertStringContainsString('/css/trunkrs.css?v=20260911-1', $appShell);
        $this->assertStringContainsString('/js/dashboard.js?v=20260914-3', $appShell);
        $this->assertStringContainsString('/js/dashboard-layout.js?v=20260914-2', $appShell);
    }
}

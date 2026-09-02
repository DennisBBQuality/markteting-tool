<?php

namespace Tests\Feature;

use Tests\TestCase;

class CsrfCookieTest extends TestCase
{
    public function test_csrf_endpoint_supplies_the_browser_security_cookie(): void
    {
        $response = $this->get('/api/auth/csrf');

        $response->assertNoContent();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);

        $cookies = collect($response->headers->getCookies());

        $this->assertTrue(
            $cookies->contains(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN'),
            'Het CSRF-eindpunt moet een XSRF-TOKEN-cookie plaatsen.'
        );
    }

    public function test_login_page_loads_the_new_csrf_aware_javascript_version(): void
    {
        $appShell = file_get_contents(public_path('index.html'));
        $appJavascript = file_get_contents(public_path('js/app.js'));

        $this->assertIsString($appShell);
        $this->assertIsString($appJavascript);
        $this->assertStringContainsString('/js/app.js?v=20260902-2', $appShell);
        $this->assertStringContainsString("fetch('/api/auth/csrf'", $appJavascript);
        $this->assertStringContainsString('res.status === 419', $appJavascript);
    }
}

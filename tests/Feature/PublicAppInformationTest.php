<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicAppInformationTest extends TestCase
{
    public function test_information_pages_are_public_without_exposing_internal_api_access(): void
    {
        Http::preventStrayRequests();
        foreach (['/over-pitboard', '/privacy', '/gebruiksvoorwaarden'] as $path) {
            $this->get($path)->assertOk()->assertSee('BBQuality The Pitboard')
                ->assertSee('href="/privacy"', false)->assertSee('href="/gebruiksvoorwaarden"', false)
                ->assertDontSee('id="app"', false);
        }
        $this->getJson('/api/content/connections')->assertUnauthorized();
        $this->getJson('/api/content/posts')->assertUnauthorized();
        Http::assertNothingSent();
    }
}

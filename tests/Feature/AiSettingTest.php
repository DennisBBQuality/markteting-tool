<?php

namespace Tests\Feature;

use App\Models\AiProviderSetting;
use App\Services\OpenAiProductImageGenerator;
use App\Services\ProductImageGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSettingTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_KEY = 'sk-project_test_12345678901234567890';

    public function test_ai_settings_are_only_available_to_administrators(): void
    {
        $this->getJson('/api/settings/ai/openai')->assertUnauthorized();

        $this->actingAsUser(['rol' => 'lid']);
        $this->getJson('/api/settings/ai/openai')->assertForbidden();
        $this->putJson('/api/settings/ai/openai', ['api_key' => self::VALID_KEY])->assertForbidden();
        $this->postJson('/api/settings/ai/openai/test')->assertForbidden();
        $this->deleteJson('/api/settings/ai/openai')->assertForbidden();
    }

    public function test_admin_can_store_a_valid_key_encrypted_and_never_read_it_back(): void
    {
        $admin = $this->actingAsUser(['rol' => 'admin']);
        Http::fake([
            'api.openai.com/v1/models/*' => Http::response(['id' => 'gpt-image-2']),
        ]);

        $response = $this->putJson('/api/settings/ai/openai', [
            'api_key' => self::VALID_KEY,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('ingesteld', true)
            ->assertJsonPath('actief', true)
            ->assertJsonPath('bron', 'app')
            ->assertJsonPath('verbonden', true)
            ->assertJsonPath('weergave', '••••••••7890');

        $rawKey = DB::table('ai_provider_settings')->value('api_key');
        $this->assertIsString($rawKey);
        $this->assertNotSame(self::VALID_KEY, $rawKey);
        $this->assertStringNotContainsString(self::VALID_KEY, $rawKey);
        $this->assertSame(self::VALID_KEY, AiProviderSetting::firstOrFail()->api_key);
        $this->assertSame($admin->id, AiProviderSetting::firstOrFail()->updated_by);
        $this->assertStringNotContainsString(self::VALID_KEY, json_encode($response->json(), JSON_THROW_ON_ERROR));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/models/gpt-image-2'
            && $request->hasHeader('Authorization', 'Bearer '.self::VALID_KEY)
        );
    }

    public function test_invalid_key_is_rejected_without_being_stored(): void
    {
        $this->actingAsUser(['rol' => 'admin']);
        Http::fake([
            'api.openai.com/v1/models/*' => Http::response(['error' => ['message' => 'invalid']], 401),
        ]);

        $this->putJson('/api/settings/ai/openai', ['api_key' => self::VALID_KEY])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Deze API-sleutel is ongeldig of heeft geen toegang tot GPT Image 2.');

        $this->assertDatabaseCount('ai_provider_settings', 0);
    }

    public function test_stored_key_can_be_tested_and_deleted(): void
    {
        $admin = $this->actingAsUser(['rol' => 'admin']);
        AiProviderSetting::create([
            'provider' => 'openai',
            'api_key' => self::VALID_KEY,
            'updated_by' => $admin->id,
        ]);
        Http::fake([
            'api.openai.com/v1/models/*' => Http::response(['id' => 'gpt-image-2']),
        ]);

        $this->postJson('/api/settings/ai/openai/test')
            ->assertOk()
            ->assertJsonPath('verbonden', true);

        $this->deleteJson('/api/settings/ai/openai')
            ->assertOk()
            ->assertJsonPath('ingesteld', false)
            ->assertJsonPath('actief', false);

        $this->assertDatabaseCount('ai_provider_settings', 0);
    }

    public function test_stored_key_automatically_enables_the_real_image_generator(): void
    {
        config()->set('services.product_images.driver', 'fake');
        $admin = $this->actingAsUser(['rol' => 'admin']);
        AiProviderSetting::create([
            'provider' => 'openai',
            'api_key' => self::VALID_KEY,
            'updated_by' => $admin->id,
        ]);

        $this->assertInstanceOf(OpenAiProductImageGenerator::class, app(ProductImageGenerator::class));
        $this->getJson('/api/images/prompt')
            ->assertOk()
            ->assertJsonPath('voorbeeldmodus', false);
    }
}

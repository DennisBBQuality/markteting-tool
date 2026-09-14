<?php

namespace Tests\Feature;

use App\Models\ProductImageRequest;
use App\Services\ProductImageModelCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageModelTest extends TestCase
{
    use RefreshDatabase;

    private function connect(): void
    {
        config(['services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-model-key']);
        Http::preventStrayRequests();
    }

    private function response(array $ids): array
    {
        return ['data' => array_map(fn ($id) => ['id' => $id, 'object' => 'model', 'owned_by' => 'openai'], $ids)];
    }

    public function test_list_requires_login_and_only_admin_can_change_the_default(): void
    {
        Http::fake();
        $this->getJson('/api/images/models')->assertUnauthorized();
        $this->postJson('/api/images/models/refresh')->assertUnauthorized();
        $this->putJson('/api/settings/ai/openai/image-model', ['image_model' => 'gpt-image-2'])->assertUnauthorized();
        $this->actingAsUser(['rol' => 'lid']);
        $this->getJson('/api/images/models')->assertOk()->assertJsonPath('default_model', ProductImageModelCatalog::PREFERRED);
        $this->putJson('/api/settings/ai/openai/image-model', ['image_model' => 'gpt-image-2'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_preview_defaults_to_sunburst_without_claiming_a_live_catalog(): void
    {
        Http::fake();
        $this->actingAsUser();
        $this->getJson('/api/images/models')->assertOk()
            ->assertJsonPath('preview', true)->assertJsonPath('checked_at', null)
            ->assertJsonPath('models.0.id', 'gpt-image-2.5-sunburst');
        Http::assertNothingSent();
    }

    public function test_discovers_only_provider_image_candidates_and_keeps_unknown_models_experimental(): void
    {
        $this->connect();
        $this->actingAsUser();
        $rows = $this->response(['gpt-image-2', 'gpt-image-2.5-sunburst', 'gpt-image-2.5-flare-2026-09-08',
            'gpt-image-3', 'gpt-5.6-sol', 'gpt-image-1.5', 'chatgpt-image-latest', '<script>bad</script>']);
        $rows['data'][] = ['id' => 'gpt-image-99', 'owned_by' => 'untrusted'];
        Http::fake(['api.openai.com/v1/models' => Http::response($rows)]);
        $response = $this->getJson('/api/images/models')->assertOk()->assertJsonPath('preview', false);
        $models = collect($response->json('models'))->keyBy('id');
        $this->assertTrue($models['gpt-image-3']['experimental']);
        $this->assertTrue($models['gpt-image-3']['available']);
        $this->assertFalse($models['gpt-image-2.5-flare-2026-09-08']['experimental']);
        $this->assertFalse($models['gpt-image-2.5-flare']['available']);
        foreach (['gpt-5.6-sol', 'gpt-image-99', 'gpt-image-1.5', '<script>bad</script>'] as $excluded) {
            $this->assertFalse($models->has($excluded));
        }
        $this->assertStringNotContainsString('test-model-key', $response->getContent());
        $this->assertDatabaseCount('product_image_model_settings', 0);
    }

    public function test_automatic_daily_refresh_and_manual_refresh_do_not_change_default(): void
    {
        $this->connect();
        $catalog = app(ProductImageModelCatalog::class);
        $catalog->saveDefault('gpt-image-2');
        Http::fakeSequence()->push($this->response(['gpt-image-2']))
            ->push($this->response(['gpt-image-2', ProductImageModelCatalog::PREFERRED]))
            ->push($this->response(['gpt-image-3']));
        $catalog->catalog();
        $catalog->catalog();
        Http::assertSentCount(1);
        $this->travel(25)->hours();
        $this->assertSame('gpt-image-2', $catalog->catalog()['default_model']);
        Http::assertSentCount(2);
        $this->travel(2)->minutes();
        $this->assertSame('gpt-image-2', $catalog->catalog(true)['default_model']);
        Http::assertSentCount(3);
    }

    public function test_timeout_uses_stale_list_and_backoff_without_swapping_models(): void
    {
        $this->connect();
        Http::fakeSequence()->push($this->response([ProductImageModelCatalog::PREFERRED]))->pushResponse(Http::failedConnection());
        $catalog = app(ProductImageModelCatalog::class);
        $first = $catalog->catalog();
        $this->travel(25)->hours();
        $second = $catalog->catalog();
        $this->assertSame($first['checked_at'], $second['checked_at']);
        $this->assertNotEmpty($second['warning']);
        $this->assertSame($first['default_model'], $second['default_model']);
        $catalog->catalog(true);
        Http::assertSentCount(2);
    }

    public function test_html_or_rate_limit_response_is_safe_and_does_not_enable_unverified_access(): void
    {
        $this->connect();
        $this->actingAsUser(['rol' => 'admin']);
        Http::fake(['*' => Http::response('<html>temporary failure</html>', 429)]);
        $this->getJson('/api/images/models')->assertOk()->assertJsonPath('models.0.available', null);
        $this->putJson('/api/settings/ai/openai/image-model', ['image_model' => ProductImageModelCatalog::PREFERRED])->assertUnprocessable();
        $this->assertDatabaseCount('product_image_model_settings', 0);
        Http::assertSentCount(1);
    }

    public function test_cache_is_scoped_to_the_current_key(): void
    {
        $this->connect();
        Http::fakeSequence()->push($this->response([ProductImageModelCatalog::PREFERRED]))->push($this->response(['gpt-image-2']));
        $catalog = app(ProductImageModelCatalog::class);
        $this->assertTrue($catalog->catalog()['models'][0]['available']);
        config(['services.product_images.openai.api_key' => 'different-test-key']);
        $this->assertFalse($catalog->catalog()['models'][0]['available']);
        Http::assertSentCount(2);
    }

    public function test_default_is_persistent_and_experimental_choice_requires_confirmation(): void
    {
        $this->connect();
        $this->actingAsUser(['rol' => 'admin']);
        Http::fake(['*' => Http::response($this->response(['gpt-image-3', ProductImageModelCatalog::PREFERRED]))]);
        $this->putJson('/api/settings/ai/openai/image-model', ['image_model' => 'gpt-image-3'])->assertUnprocessable();
        $this->putJson('/api/settings/ai/openai/image-model', ['image_model' => 'gpt-image-3', 'accept_experimental' => true])->assertOk();
        $this->getJson('/api/settings/ai/openai')->assertOk()->assertJsonPath('model', 'gpt-image-3');
        $this->putJson('/api/settings/ai/openai/image-model', ['image_model' => ProductImageModelCatalog::PREFERRED])->assertOk();
        $this->assertDatabaseHas('product_image_model_settings', ['id' => 1, 'model' => ProductImageModelCatalog::PREFERRED]);
        $this->assertDatabaseCount('ai_provider_settings', 0);
    }

    public function test_selected_model_is_pinned_before_queueing_and_not_overwritten_by_default(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAsUser();
        $response = $this->postJson('/api/images/generate', [
            'foto' => UploadedFile::fake()->image('test.png', 40, 40),
            'image_model' => 'gpt-image-2.5-flare',
        ])->assertStatus(202)->assertJsonPath('context.image_model', 'gpt-image-2.5-flare');
        app(ProductImageModelCatalog::class)->saveDefault('gpt-image-2');
        $this->assertSame('gpt-image-2.5-flare', ProductImageRequest::findOrFail($response->json('request_id'))->generation_context['image_model']);
    }

    public function test_invalid_model_is_rejected_before_storing_files_or_queueing(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAsUser();
        $this->postJson('/api/images/generate', [
            'foto' => UploadedFile::fake()->image('test.png', 40, 40), 'image_model' => 'gpt-5.6-sol',
        ])->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('product_image_requests', 0);
        Queue::assertNothingPushed();
    }

    public function test_additive_migration_and_rollback_leave_existing_data_untouched(): void
    {
        $user = $this->actingAsUser();
        $before = DB::table('users')->get()->toJson();
        $migration = require database_path('migrations/2026_09_11_100000_create_product_image_model_settings_table.php');
        $migration->down();
        $migration->up();
        $this->assertSame($before, DB::table('users')->get()->toJson());
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}

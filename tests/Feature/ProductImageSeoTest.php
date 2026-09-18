<?php

namespace Tests\Feature;

use App\Console\Commands\UpgradeImageSeoStorage;
use App\Jobs\GenerateProductImages;
use App\Jobs\GenerateProductImageSeo;
use App\Jobs\RefineProductImage;
use App\Models\ProductImageAsset;
use App\Models\ProductImageMetadata;
use App\Models\ProductImageRequest;
use App\Services\FakeProductImageGenerator;
use App\Services\ProductImagePromptBuilder;
use App\Services\ProductImageSeo;
use App\Services\ProductImageSeoAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageSeoTest extends TestCase
{
    use RefreshDatabase;

    private function photos(): array
    {
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        $this->actingAsUser(['rol' => 'lid']);
        $id = $this->post('/api/images/generate', ['foto' => UploadedFile::fake()->image('test.png', 30, 30),
            'product_type' => 'meat', 'product_name' => 'Varkens wangen ontvliesd'], ['Accept' => 'application/json'])
            ->assertAccepted()->json('request_id');
        (new GenerateProductImages($id))->handle(new FakeProductImageGenerator);
        $request = ProductImageRequest::findOrFail($id);
        $raw = collect($request->results)->firstWhere('status', 'rauw');
        $asset = ProductImageAsset::where('product_image_request_id', $id)->where('filename', $raw['filename'])->firstOrFail();

        return [$request, $asset, '/api/images/requests/'.$id.'/assets/'.$asset->id.'/seo'];
    }

    private function fields(): array
    {
        return ['filename' => 'Varkens wangen_ontvliesd--gestoofd-aardappel puree.WEBP',
            'alt' => 'Gestoofde varkenswangen met jus en aardappelpuree op een licht bord',
            'title' => 'Varkenswangen ontvliesd – serveersuggestie met aardappelpuree',
            'caption' => 'Serveersuggestie: gestoofde varkenswangen met jus en aardappelpuree.',
            'description' => 'Varkenswangen met aardappelpuree op een licht bord in een woonkeuken. Serveersuggestie voor varkenswangen ontvliesd van BBQuality.'];
    }

    private function runSeo(ProductImageAsset $asset, ?ProductImageSeoAnalyzer $analyzer = null): void
    {
        $row = app(ProductImageSeo::class)->record($asset);
        (new GenerateProductImageSeo($asset->id, $asset->version, $row->job_token))->handle($analyzer ?? app(ProductImageSeoAnalyzer::class));
    }

    public function test_photoset_is_only_ready_after_all_five_fields_of_every_current_photo_are_saved(): void
    {
        [$request, $asset] = $this->photos();
        $url = '/api/images/requests/'.$request->id;
        $this->getJson($url)->assertJsonPath('status', 'processing_seo')->assertJsonPath('seo_summary.ready', 0);
        $assets = ProductImageAsset::where('product_image_request_id', $request->id)->get();
        $names = ['licht-bord', 'donker-bord', 'houten-tafel', 'zwarte-achtergrond', 'lichte-keuken'];
        foreach ($assets as $index => $photo) {
            app(ProductImageSeo::class)->save($photo, [...$this->fields(), 'filename' => 'varkenswangen-'.$names[$index].'.webp'], 0);
        }
        $this->getJson($url)->assertJsonPath('status', 'completed')->assertJsonPath('progress', 100)
            ->assertJsonPath('seo_summary.ready', 5)->assertJsonPath('results.0.seo.ready', true);
        $row = app(ProductImageSeo::class)->record($asset);
        $row->update(['fields' => [...$row->fields, 'description' => ' ']]);
        $response = $this->getJson($url)->assertJsonPath('status', 'seo_failed')->assertJsonPath('seo_summary.ready', 4);
        $photo = collect($response->json('results'))->firstWhere('asset_id', $asset->id);
        $this->getJson($photo['download_url'])->assertUnprocessable();
        $this->assertDatabaseCount('product_image_assets', 5);
        app(ProductImageSeo::class)->save($asset, [...$this->fields(), 'filename' => 'varkenswangen-eigen-presentatie.webp'], 1);
        $this->getJson($url)->assertJsonPath('status', 'completed');
    }

    public function test_missing_filename_storage_fails_before_paid_analysis_and_is_visible_on_existing_failures(): void
    {
        [$request, $asset, $url] = $this->photos();
        // Test database only. Production schema is changed exclusively by migrations.
        Schema::drop('product_image_download_names');
        $analyzer = $this->mock(ProductImageSeoAnalyzer::class);
        $analyzer->shouldNotReceive('analyze');
        $this->getJson($url)->assertJsonPath('seo.storage_ready', false)->assertJsonPath('seo.error', ProductImageSeo::STORAGE_ERROR);
        $this->runSeo($asset, $analyzer);
        $this->getJson($url)->assertJsonPath('seo.status', 'failed')->assertJsonPath('seo.ready', false)
            ->assertJsonPath('seo.error', ProductImageSeo::STORAGE_ERROR);
        $this->assertSame($request->results, $request->fresh()->results);
        $this->assertNotEmpty($asset->fresh()->contents_base64);
        Http::assertNothingSent();
    }

    public function test_connection_failure_has_safe_actionable_error_and_preserves_photo(): void
    {
        [, $asset, $url] = $this->photos();
        $analyzer = $this->mock(ProductImageSeoAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()->andThrow(new ConnectionException('private-provider-details'));
        $this->runSeo($asset, $analyzer);
        $response = $this->getJson($url)->assertJsonPath('seo.status', 'failed')->assertJsonPath('seo.ready', false);
        $this->assertStringContainsString('verbinding', $response->json('seo.error'));
        $this->assertStringNotContainsString('private-provider-details', $response->getContent());
        $this->assertNotEmpty($asset->fresh()->contents_base64);
    }

    public function test_existing_failed_photo_can_recover_storage_and_finish_seo_without_regenerating_pixels(): void
    {
        [$request, $asset, $url] = $this->photos();
        $pixels = $asset->contents_base64;
        Schema::drop('product_image_download_names');
        DB::table('migrations')->where('migration', UpgradeImageSeoStorage::MIGRATION)->delete();
        $this->getJson($url)->assertJsonPath('seo.storage_ready', false);
        $this->assertFalse(Schema::hasTable('product_image_download_names'), 'Reading SEO must never migrate.');
        $analyzer = $this->mock(ProductImageSeoAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()->andReturn($this->fields());
        $this->runSeo($asset, $analyzer);
        $this->getJson($url)->assertJsonPath('seo.storage_ready', true)->assertJsonPath('seo.ready', true);
        $this->assertSame($pixels, $asset->fresh()->contents_base64);
        $this->assertSame($request->results, $request->fresh()->results);
        Http::assertNothingSent();
    }

    public function test_names_are_unique_across_photosets_and_users_and_ai_uses_a_visible_alternative(): void
    {
        [, $first] = $this->photos();
        app(ProductImageSeo::class)->save($first, $this->fields(), 0);
        [, $second, $url] = $this->photos();
        $analyzer = $this->mock(ProductImageSeoAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()->andReturn([...$this->fields(),
            'filename_alternatives' => ['varkenswangen-ontvliesd-op-licht-bord.webp', 'varkenswangen-ontvliesd-in-woonkeuken.webp']]);
        $this->runSeo($second, $analyzer);
        $this->getJson($url)->assertJsonPath('metadata.filename', 'varkenswangen-ontvliesd-op-licht-bord.webp')
            ->assertJsonPath('seo.status', 'completed');
        $this->assertCount(5, app(ProductImageSeo::class)->record($second)->fields);
        $this->assertDatabaseCount('product_image_download_names', 2);
    }

    public function test_manual_numeric_name_is_rejected_and_old_name_stays_reserved_after_renaming(): void
    {
        [, $first, $url] = $this->photos();
        $this->putJson($url, ['image_version' => 1, 'revision' => 0,
            'fields' => [...$this->fields(), 'filename' => 'varkenswangen-op-bord-2.webp']])->assertUnprocessable()->assertJsonValidationErrors('filename');
        app(ProductImageSeo::class)->save($first, $this->fields(), 0);
        app(ProductImageSeo::class)->save($first, [...$this->fields(), 'filename' => 'varkenswangen-op-licht-bord.webp'], 1);
        [, , $secondUrl] = $this->photos();
        $this->putJson($secondUrl, ['image_version' => 1, 'revision' => 0, 'fields' => $this->fields()])
            ->assertUnprocessable()->assertJsonValidationErrors('filename');
        $this->assertDatabaseCount('product_image_download_names', 2);
    }

    public function test_historical_names_without_reservation_are_respected_and_exhausted_ai_names_fail_safely(): void
    {
        [, $first] = $this->photos();
        app(ProductImageSeo::class)->record($first)->update(['status' => 'completed', 'fields' => ProductImageSeo::normalize($this->fields())]);
        [, $second, $url] = $this->photos();
        $analyzer = $this->mock(ProductImageSeoAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()->andReturn([...$this->fields(),
            'filename_alternatives' => [$this->fields()['filename'], 'varkenswangen-op-bord-2.webp']]);
        $this->runSeo($second, $analyzer);
        $this->getJson($url)->assertJsonPath('seo.status', 'failed')->assertJsonPath('metadata.filename', '');
        $this->assertStringContainsString('geen volgnummer', app(ProductImageSeo::class)->record($second)->error);
        $this->assertDatabaseCount('product_image_download_names', 0);
    }

    public function test_same_photo_can_save_its_own_reserved_name_again(): void
    {
        [, $asset] = $this->photos();
        app(ProductImageSeo::class)->save($asset, $this->fields(), 0);
        app(ProductImageSeo::class)->save($asset, [...$this->fields(), 'alt' => 'Verbeterde omschrijving van dezelfde foto'], 1);
        $this->assertSame(2, app(ProductImageSeo::class)->record($asset)->revision);
        $this->assertDatabaseCount('product_image_download_names', 1);
    }

    public function test_every_photo_gets_its_own_analysis_of_actual_pixels_and_download_name(): void
    {
        [$request, $asset, $url] = $this->photos();
        $this->assertSame(5, ProductImageMetadata::count());
        Queue::assertPushed(GenerateProductImageSeo::class, 5);
        config(['services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['output' => [['content' => [['type' => 'output_text', 'text' => json_encode($this->fields())]]]]])]);
        $this->runSeo($asset);
        Http::assertSent(fn ($r) => $r['store'] === false
            && $r['input'][1]['content'][1]['image_url'] === 'data:image/png;base64,'.$asset->contents_base64
            && $r['text']['format']['strict'] === true);
        $this->getJson($url)->assertOk()->assertJsonPath('seo.source', 'ai')->assertJsonPath('seo.status', 'completed')
            ->assertJsonPath('metadata.filename', 'varkenswangen-ontvliesd-gestoofd-aardappelpuree.webp');
        $result = collect($this->getJson('/api/images/requests/'.$request->id)->json('results'))->firstWhere('asset_id', $asset->id);
        $this->get($result['download_url'])->assertDownload('varkenswangen-ontvliesd-gestoofd-aardappelpuree.webp');
        $this->assertSame(4, ProductImageMetadata::whereNull('fields')->count());
    }

    public function test_manual_edits_survive_late_ai_response_and_stale_tabs_cannot_overwrite(): void
    {
        [, $asset, $url] = $this->photos();
        $fake = $this->mock(ProductImageSeoAnalyzer::class);
        $fake->shouldReceive('analyze')->once()->andReturnUsing(function () use ($url) {
            $this->putJson($url, ['image_version' => 1, 'revision' => 0, 'fields' => $this->fields()])->assertOk();

            return [...$this->fields(), 'alt' => 'AI mag de handmatige correctie niet vervangen'];
        });
        $this->runSeo($asset, $fake);
        $this->getJson($url)->assertJsonPath('seo.source', 'manual')->assertJsonPath('seo.revision', 1)
            ->assertJsonPath('metadata.alt', $this->fields()['alt']);
        $this->putJson($url, ['image_version' => 1, 'revision' => 0, 'fields' => $this->fields()])->assertConflict();
        $this->postJson($url.'/generate', ['image_version' => 1, 'revision' => 1])->assertConflict();
        $this->postJson($url.'/generate', ['image_version' => 1, 'revision' => 1, 'replace_manual' => true])->assertAccepted();
    }

    public function test_automatic_seo_runs_inside_existing_deferred_background_work(): void
    {
        [, $asset] = $this->photos();
        app(ProductImageSeo::class)->record($asset)->delete();
        config(['services.product_images.queue_connection' => 'deferred']);
        Queue::getFacadeRoot()->except([GenerateProductImageSeo::class]);
        $this->mock(ProductImageSeoAnalyzer::class)->shouldReceive('analyze')->once()->andReturn($this->fields());
        app(ProductImageSeo::class)->queue($asset, alreadyBackground: true);
        $this->assertSame('completed', app(ProductImageSeo::class)->record($asset)->status);
    }

    public function test_automatic_seo_does_not_fail_a_photo_when_refinement_has_already_started(): void
    {
        [$request, $asset] = $this->photos();
        $asset->update(['refinement_status' => 'queued']);
        app(ProductImageSeo::class)->queueAutomatically($asset);
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertSame('queued', $asset->fresh()->refinement_status);
    }

    public function test_failures_keep_previous_fields_and_photo_and_duplicate_clicks_do_not_dispatch_again(): void
    {
        [, $asset, $url] = $this->photos();
        $this->postJson($url.'/generate', ['image_version' => 1, 'revision' => 0])->assertAccepted();
        Queue::assertPushed(GenerateProductImageSeo::class, 5);
        $this->putJson($url, ['image_version' => 1, 'revision' => 0, 'fields' => $this->fields()])->assertOk();
        $this->postJson($url.'/generate', ['image_version' => 1, 'revision' => 1, 'replace_manual' => true])->assertAccepted();
        config(['services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-secret']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'secret provider details']], 429)]);
        $this->runSeo($asset);
        $payload = $this->getJson($url)->assertJsonPath('seo.status', 'failed')->assertJsonPath('metadata.alt', $this->fields()['alt']);
        $this->assertStringNotContainsString('test-secret', $payload->getContent());
        $this->assertStringNotContainsString('secret provider', $payload->getContent());
        $this->assertStringContainsString('tijdelijk begrensd', $payload->json('seo.error'));
        $this->assertSame($asset->contents_base64, $asset->fresh()->contents_base64);
        Http::assertSentCount(1);
    }

    public function test_invalid_ai_response_does_not_publish_partial_fields(): void
    {
        [, $asset, $url] = $this->photos();
        config(['services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['output_text' => '{"filename":"test.webp"}'])]);
        $this->runSeo($asset);
        $this->getJson($url)->assertJsonPath('seo.status', 'failed')->assertJsonPath('seo.source', 'none');
        $this->assertNull(app(ProductImageSeo::class)->record($asset)->fields);
    }

    public function test_new_versions_get_new_seo_and_restoring_a_version_preserves_its_manual_text(): void
    {
        [$request, $asset, $url] = $this->photos();
        $this->putJson($url, ['image_version' => 1, 'revision' => 0, 'fields' => $this->fields()])->assertOk();
        $this->postJson(str_replace('/seo', '/refine', $url), ['instruction' => 'Maak de achtergrond lichter.'])->assertAccepted();
        (new RefineProductImage($request->id, $asset->id, 'Maak de achtergrond lichter.'))->handle(new FakeProductImageGenerator);
        $asset->refresh();
        $this->assertSame(2, $asset->version);
        $this->getJson($url)->assertJsonPath('seo.source', 'none')->assertJsonPath('seo.status', 'queued');
        $this->putJson($url, ['image_version' => 1, 'revision' => 1, 'fields' => $this->fields()])->assertConflict();
        $revision = $asset->revisions()->firstOrFail();
        $this->postJson(str_replace('/seo', '/revisions/'.$revision->id.'/restore', $url))->assertOk();
        $this->getJson($url)->assertJsonPath('version', 3)->assertJsonPath('seo.source', 'manual')
            ->assertJsonPath('metadata.alt', $this->fields()['alt']);
        $this->assertSame($this->fields()['alt'], ProductImageMetadata::where('product_image_asset_id', $asset->id)->where('image_version', 1)->first()->fields['alt']);
    }

    public function test_old_version_job_cannot_write_to_new_image_and_expired_jobs_can_be_retried(): void
    {
        [, $asset, $url] = $this->photos();
        $row = app(ProductImageSeo::class)->record($asset);
        $oldJob = new GenerateProductImageSeo($asset->id, 1, $row->job_token);
        $asset->update(['version' => 2]);
        $oldJob->handle(app(ProductImageSeoAnalyzer::class));
        $this->assertNull(app(ProductImageSeo::class)->record($asset));
        $this->assertSame('failed', $row->fresh()->status);
        $this->postJson($url.'/generate', ['image_version' => 2, 'revision' => 0])->assertAccepted();
        $new = app(ProductImageSeo::class)->record($asset);
        $new->update(['updated_at' => now()->subHours(1)]);
        $this->getJson($url)->assertJsonPath('seo.status', 'failed');
        $this->postJson($url.'/generate', ['image_version' => 2, 'revision' => 0])->assertAccepted();
        $this->assertNotSame($new->job_token, $new->fresh()->job_token);
    }

    public function test_filename_collisions_and_authorization_and_validation(): void
    {
        [$request, $asset, $url] = $this->photos();
        $fields = $this->fields();
        $this->putJson($url, ['image_version' => 1, 'revision' => 0, 'fields' => [...$fields, 'alt' => '']])->assertUnprocessable();
        $this->putJson($url, ['image_version' => 1, 'revision' => 0, 'fields' => $fields])->assertOk();
        $otherRaw = collect($request->results)->where('status', 'rauw')->first(fn ($result) => $result['filename'] !== $asset->filename);
        $second = ProductImageAsset::where('product_image_request_id', $request->id)->where('filename', $otherRaw['filename'])->firstOrFail();
        $secondUrl = str_replace('/assets/'.$asset->id, '/assets/'.$second->id, $url);
        $this->putJson($secondUrl, ['image_version' => 1, 'revision' => 0, 'fields' => $fields])->assertUnprocessable()->assertJsonValidationErrors('filename');
        app(ProductImageSeo::class)->save($second, [...$fields, 'filename' => 'varkenswangen-ontvliesd-op-licht-bord.webp'], 0);
        $this->assertSame('varkenswangen-ontvliesd-op-licht-bord.webp', app(ProductImageSeo::class)->record($second)->fields['filename']);
        $this->actingAsUser();
        $this->getJson($url)->assertNotFound();
        $this->putJson($url, ['image_version' => 1, 'revision' => 1, 'fields' => $fields])->assertNotFound();
        $this->postJson($url.'/generate', ['image_version' => 1, 'revision' => 1])->assertNotFound();
    }

    public function test_fake_mode_does_not_claim_to_have_analyzed_a_photo(): void
    {
        [, $asset, $url] = $this->photos();
        $this->runSeo($asset);
        $this->getJson($url)->assertJsonPath('seo.status', 'failed')->assertJsonPath('seo.source', 'none');
        $this->assertStringStartsWith('Voorbeeldmodus:', app(ProductImageSeo::class)->record($asset)->error);
        Http::assertNothingSent();
    }

    public function test_selected_preparation_is_sent_to_ai_and_enforced_on_save_and_download(): void
    {
        [$request] = $this->photos();
        // Use the real plan builder and persist its stable style ID as generation does.
        $context = ['product_type' => 'fish', 'product_name' => 'Zalmhaas', 'variant_groups' => ['airfryer']];
        $plan = app(ProductImagePromptBuilder::class)->plans($context)[0];
        $asset = ProductImageAsset::where('product_image_request_id', $request->id)->firstOrFail();
        $request->update(['generation_context' => $context, 'results' => [[...$plan, 'filename' => $asset->filename, 'variant' => 1]]]);
        $url = '/api/images/requests/'.$request->id.'/assets/'.$asset->id.'/seo';
        config(['services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['output_text' => json_encode($this->fields())])]);
        $this->runSeo($asset);
        Http::assertSent(fn ($r) => json_decode($r['input'][1]['content'][0]['text'], true)['bereidingswijze'] === 'airfryer');
        $metadata = $this->getJson($url)->assertOk()->assertJsonPath('seo.status', 'completed')->json('metadata');
        foreach (['alt', 'title', 'caption', 'description'] as $field) {
            $this->assertStringContainsString('bereid in de airfryer', $metadata[$field]);
        }
        $this->assertSame('varkenswangen-ontvliesd-gestoofd-aardappelpuree-airfryer.webp', $metadata['filename']);
        $this->putJson($url, ['image_version' => 1, 'revision' => 1, 'fields' => $this->fields()])->assertOk()
            ->assertJsonPath('metadata.filename', $metadata['filename']);
        $result = $this->getJson('/api/images/requests/'.$request->id)->json('results.0');
        $this->get($result['download_url'])->assertDownload($metadata['filename']);
        $this->assertSame($metadata['alt'], app(ProductImageSeo::class)->record($asset)->fields['alt']);
    }

    public function test_reading_existing_prepared_seo_does_not_rewrite_saved_text(): void
    {
        [$request] = $this->photos();
        $prepared = collect($request->results)->firstWhere('status', 'bereid');
        $asset = ProductImageAsset::where('product_image_request_id', $request->id)->where('filename', $prepared['filename'])->firstOrFail();
        $row = app(ProductImageSeo::class)->record($asset);
        $fields = ProductImageSeo::normalize($this->fields());
        $row->update(['fields' => $fields, 'source' => 'manual', 'status' => 'completed', 'job_token' => null]);
        $url = '/api/images/requests/'.$request->id.'/assets/'.$asset->id.'/seo';
        $this->getJson($url)->assertOk()->assertJsonPath('metadata.alt', $fields['alt']);
        $this->assertSame($fields, $row->fresh()->fields);
        // Explicitly saving this older SEO adopts the new method rule.
        $this->putJson($url, ['image_version' => 1, 'revision' => 0, 'fields' => $fields])->assertOk()
            ->assertJsonPath('metadata.alt', $fields['alt'].' – bereid op de BBQ');
    }
}

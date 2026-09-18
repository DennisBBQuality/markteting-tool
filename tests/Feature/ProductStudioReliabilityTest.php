<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductPage;
use App\Models\AiProviderSetting;
use App\Models\ProductDossier;
use App\Models\ProductDossierAsset;
use App\Models\ProductImageRequest;
use App\Models\User;
use App\Services\ProductDossierAiService;
use App\Services\ProductDossierContent;
use App\Services\ProductDossierExport;
use App\Services\ProductDossierMedia;
use App\Services\ProductImageDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductStudioReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private function dossier(array $data = []): ProductDossier
    {
        $user = $this->actingAsUser();
        AiProviderSetting::create(['provider' => 'openai', 'api_key' => 'sk-local-test-not-a-real-key-1234567890', 'updated_by' => $user->id]);

        return ProductDossier::create(['user_id' => $user->id, 'product_name' => 'Test brisket', 'product_type' => 'meat', 'data' => ['facts' => ['origin' => 'Uruguay'], ...$data]]);
    }

    private function page(): array
    {
        return [
            'korte_introductie' => 'Test brisket is een mooi stuk rundvlees voor low & slow. Geef deze borstsnit de tijd en geniet van de volle rundvleessmaak.',
            'secties' => [['kop' => 'Een stuk voor low & slow', 'tekst' => 'Deze borstsnit heeft een stevige structuur. Dat maakt langzaam garen zo passend.'], ['kop' => 'Volle rundvleessmaak', 'tekst' => 'Brisket combineert vlees en vet. Die combinatie geeft smaak.']],
            'faqs' => array_map(fn ($i) => ['vraag' => 'Koopvraag '.$i.'?', 'antwoord' => 'Een zelfstandig antwoord.'], range(1, 6)),
            'ingrediënten' => ['waarde' => '100% rundvlees', 'source_status' => 'etiket'],
            'allergenen' => ['waarde' => 'Geen verwacht', 'source_status' => 'etiket'],
            'voedingswaarden' => ['basis' => 'Per 100 g', 'energie_kcal' => 'ca. 210 kcal', 'source_status' => 'etiket'],
            'seo' => ['titel' => 'Test brisket | BBQuality', 'slug' => 'test-brisket'],
            'controlepunten' => [],
        ];
    }

    public function test_double_clicks_enqueue_only_one_job_and_do_not_call_ai_in_web_request(): void
    {
        $dossier = $this->dossier();
        Queue::fake();
        Http::fake();
        $url = '/api/product-dossiers/'.$dossier->id.'/generate-page';
        $first = $this->postJson($url)->assertAccepted();
        $this->postJson($url)->assertAccepted()->assertJsonPath('dossier.generation.token', $first->json('dossier.generation.token'));
        Queue::assertPushed(GenerateProductPage::class, 1);
        Http::assertNothingSent();
    }

    public function test_deferred_hosting_processes_the_job_without_a_database_worker(): void
    {
        config(['services.product_content.queue_connection' => 'deferred']);
        $dossier = $this->dossier();
        Http::fake(['*' => Http::response(['output_text' => json_encode($this->page())])]);
        $token = 'test-deferred-token';
        $dossier->update(['generation' => ['token' => $token, 'status' => 'queued']]);
        $job = new GenerateProductPage($dossier->id, $token, $dossier->only(['product_name', 'product_type', 'data']), ProductDossierContent::inputHash($dossier));
        $this->assertSame('deferred', $job->connection);
        dispatch($job);
        Http::assertNothingSent();
        app(DeferredCallbackCollection::class)->invoke();
        $this->assertSame('completed', $dossier->fresh()->generation['status']);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_label_media_survives_a_new_server_filesystem_and_stays_private(): void
    {
        Storage::fake('local');
        $dossier = $this->dossier();
        $response = $this->post('/api/product-dossiers/'.$dossier->id.'/labels', [
            'labels' => [UploadedFile::fake()->image('persistent-label.jpg')],
        ], ['Accept' => 'application/json'])->assertOk();
        $metadata = $dossier->fresh()->label_images[0];
        $expected = ProductDossierMedia::contents($dossier->id, $metadata);
        Storage::fake('local'); // A replacement web/worker instance has no local originals.
        $this->get($response->json('label_images.0.url'))->assertOk()->assertContent($expected);
        $this->assertStringNotContainsString('contents_base64', $response->getContent());
        $this->assertStringNotContainsString('contents_base64', ProductDossierAsset::findOrFail($metadata['asset_id'])->toJson());
        $this->assertNull(ProductDossierMedia::contents('another-dossier', $metadata));

        Queue::fake();
        Http::fakeSequence()->push(['output_text' => json_encode(['ingrediënten' => 'Rundvlees', 'ingrediënten_bron' => 'etiket'])])
            ->push(['output_text' => json_encode(['voedingswaarden' => ['energie_kcal' => 'ca. 200 kcal']])]);
        $this->postJson('/api/product-dossiers/'.$dossier->id.'/generate-page', ['operation' => 'label'])->assertAccepted();
        Queue::pushed(GenerateProductPage::class)->first()->handle(app(ProductDossierAiService::class));
        $this->assertSame('completed', $dossier->fresh()->generation['status']);
        $this->assertSame('Rundvlees', $dossier->fresh()->data['ingredients']);
        $this->withSession(['userId' => User::factory()->create()->id]);
        $this->get($response->json('label_images.0.url'))->assertNotFound();
    }

    public function test_job_is_idempotent_and_preserves_previous_text_history(): void
    {
        $dossier = $this->dossier(['content' => ['short_description' => 'Bestaande tekst']]);
        Queue::fake();
        Http::fake(['*' => Http::response(['output_text' => json_encode($this->page())])]);
        $this->postJson('/api/product-dossiers/'.$dossier->id.'/generate-page')->assertAccepted();
        $job = Queue::pushed(GenerateProductPage::class)->first();
        $job->handle(app(ProductDossierAiService::class));
        $job->handle(app(ProductDossierAiService::class));
        Http::assertSentCount(1);
        $dossier->refresh();
        $this->assertSame('completed', $dossier->generation['status']);
        $this->assertSame('Bestaande tekst', $dossier->data['content_history'][0]['short_description']);
        $this->assertSame('ai_schatting', $dossier->data['ingredients_source_status']);
        $this->assertSame('ai_schatting', $dossier->data['allergens_source_status']);
        $this->assertSame('ai_schatting', $dossier->data['nutrition']['source_status']);
    }

    public function test_provider_failure_is_persistent_and_preserves_the_last_good_copy(): void
    {
        $dossier = $this->dossier(['content' => ['short_description' => 'Bewaar deze tekst']]);
        Queue::fake();
        Http::fake(['*' => Http::response(['error' => ['code' => 'insufficient_quota']], 429)]);
        $this->postJson('/api/product-dossiers/'.$dossier->id.'/generate-page')->assertAccepted();
        Queue::pushed(GenerateProductPage::class)->first()->handle(app(ProductDossierAiService::class));
        $result = $this->getJson('/api/product-dossiers/'.$dossier->id)->assertOk();
        $result->assertJsonPath('generation.status', 'failed')->assertJsonPath('data.content.short_description', 'Bewaar deze tekst');
        $this->assertStringContainsString('projectbudget', $result->json('generation.error'));
    }

    public function test_changed_source_during_job_is_not_overwritten(): void
    {
        $dossier = $this->dossier();
        Queue::fake();
        Http::fake(['*' => Http::response(['output_text' => json_encode($this->page())])]);
        $this->postJson('/api/product-dossiers/'.$dossier->id.'/generate-page')->assertAccepted();
        $dossier->update(['data' => ['facts' => ['origin' => 'Nederland'], 'ingredients' => 'Handmatige correctie']]);
        Queue::pushed(GenerateProductPage::class)->first()->handle(app(ProductDossierAiService::class));
        $dossier->refresh();
        $this->assertSame('Nederland', $dossier->data['facts']['origin']);
        $this->assertSame('Handmatige correctie', $dossier->data['ingredients']);
        $this->assertTrue($dossier->data['content']['input_changed_during_generation']);
    }

    public function test_nutrition_and_composition_never_replace_existing_values(): void
    {
        $nutrition = ProductDossierContent::mergeNutrition(['energie_kcal' => '189 kcal', 'source_status' => 'etiket'], ['energie_kcal' => 'ca. 220 kcal', 'zout' => 'ca. 0.2 g'], 'ai_schatting');
        $this->assertSame('189 kcal', $nutrition['energie_kcal']);
        $this->assertSame('gemengd', $nutrition['source_status']);
        $this->assertSame('etiket', $nutrition['field_sources']['energie_kcal']);
        $this->assertSame('ai_schatting', $nutrition['field_sources']['zout']);
        $data = ProductDossierContent::mergeComposition(['ingredients' => 'Rundvlees, MOSTERD', 'ingredients_source_status' => 'handmatig'], ['ingrediënten' => 'Rundvlees', 'ingrediënten_bron' => 'etiket']);
        $this->assertSame('Rundvlees, MOSTERD', $data['ingredients']);
        $this->assertSame('handmatig', $data['ingredients_source_status']);
    }

    public function test_stale_browser_save_returns_conflict_instead_of_overwriting(): void
    {
        $dossier = $this->dossier();
        $before = $dossier->updated_at->toISOString();
        $this->travel(2)->seconds();
        $dossier->update(['data' => ['facts' => ['origin' => 'Nederland']]]);
        $this->putJson('/api/product-dossiers/'.$dossier->id, ['product_name' => 'Test brisket', 'expected_updated_at' => $before, 'data' => ['facts' => ['origin' => 'Uruguay']]])->assertConflict();
        $this->assertSame('Nederland', $dossier->fresh()->data['facts']['origin']);
    }

    public function test_export_separates_estimates_and_escapes_html(): void
    {
        $dossier = $this->dossier(['ingredients' => 'Verzonnen sausrecept', 'ingredients_source_status' => 'ai_schatting', 'allergens' => 'Geen verwacht', 'allergens_source_status' => 'ai_schatting', 'nutrition' => ['energie_kcal' => 'ca. 200 kcal', 'source_status' => 'ai_schatting'], 'expert' => ['name' => 'Testvakman', 'tip' => 'Testadvies', 'approved' => false], 'content' => ['short_description' => '<script>alert(1)</script>', 'sections' => [['kop' => '<img src=x>', 'tekst' => 'Goede tekst']]]]);
        $export = app(ProductDossierExport::class)->build($dossier);
        $this->assertSame('draft', $export['product']['status']);
        $this->assertNull($export['pdp_fields']['ingredients']);
        $this->assertNull($export['pdp_fields']['allergens']);
        $this->assertNull($export['pdp_fields']['nutrition']);
        $this->assertNull($export['pdp_fields']['expert']);
        $this->assertStringNotContainsString('<script>', $export['product']['short_description']);
        $this->assertArrayNotHasKey('offers', $export['structured_data_draft']);
        $this->get('/api/product-dossiers/'.$dossier->id.'/export?format=json')->assertOk()->assertDownload('test-brisket-concept.json');
    }

    public function test_exports_and_label_photos_are_private(): void
    {
        Storage::fake('local');
        $dossier = $this->dossier();
        Storage::disk('local')->put('private-label.png', 'private');
        $dossier->update(['label_images' => [['path' => 'private-label.png', 'mime_type' => 'image/png']]]);
        $this->get('/api/product-dossiers/'.$dossier->id.'/labels/0')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $other = User::factory()->create();
        $this->withSession(['userId' => $other->id]);
        $this->get('/api/product-dossiers/'.$dossier->id.'/labels/0')->assertNotFound();
        $this->get('/api/product-dossiers/'.$dossier->id.'/export')->assertNotFound();
    }

    public function test_stalled_generation_gives_a_recoverable_error(): void
    {
        $dossier = $this->dossier();
        $dossier->update(['generation' => ['token' => 'test-token', 'status' => 'queued', 'requested_at' => now()->subMinutes(13)->toISOString()]]);
        $this->getJson('/api/product-dossiers/'.$dossier->id)->assertJsonPath('generation.status', 'failed');
    }

    public function test_lossless_webp_preserves_pixels_and_has_descriptive_metadata(): void
    {
        $image = imagecreatetruecolor(3, 2);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 100, 40, 20, 50));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        $delivery = app(ProductImageDelivery::class);
        $webp = $delivery->webp($png);
        $decoded = imagecreatefromstring($webp);
        $this->assertSame(3, imagesx($decoded));
        $this->assertSame(2, imagesy($decoded));
        $this->assertSame(imagecolorat($image, 0, 0), imagecolorat($decoded, 0, 0));
        $metadata = $delivery->metadata(['product_name' => 'Black Angus brisket'], ['status' => 'rauw', 'variant' => 1], 2);
        $this->assertSame('', $metadata['filename']); // No invented or numbered fallback before photo-specific SEO.
        $this->assertSame('Black Angus brisket, rauw', $metadata['alt']);
        imagedestroy($image);
        imagedestroy($decoded);
    }

    public function test_supplement_works_without_a_label_and_never_claims_label_provenance(): void
    {
        $dossier = $this->dossier();
        Queue::fake();
        Http::fakeSequence()->push(['output_text' => json_encode(['ingrediënten' => 'Rundvlees', 'ingrediënten_bron' => 'etiket', 'allergenen' => 'Geen verwacht', 'allergenen_bron' => 'etiket', 'voedingswaarden' => [], 'waarschuwingen' => []])])
            ->push(['output_text' => json_encode(['voedingswaarden' => ['energie_kcal' => 'ca. 200 kcal'], 'aannames' => ['Onbewerkt rundvlees'], 'waarschuwing' => 'Schatting controleren'])]);
        $this->postJson('/api/product-dossiers/'.$dossier->id.'/generate-page', ['operation' => 'supplement'])->assertAccepted();
        Queue::pushed(GenerateProductPage::class)->first()->handle(app(ProductDossierAiService::class));
        $dossier->refresh();
        $this->assertSame('completed', $dossier->generation['status']);
        $this->assertSame('ai_schatting', $dossier->data['ingredients_source_status']);
        $this->assertSame('ai_schatting', $dossier->data['allergens_source_status']);
        $this->assertSame('ca. 200 kcal', $dossier->data['nutrition']['energie_kcal']);
        Http::assertSentCount(2);
    }

    public function test_same_second_concurrent_edit_is_rejected_using_revision(): void
    {
        $dossier = $this->dossier();
        $revision = $this->getJson('/api/product-dossiers/'.$dossier->id)->json('revision');
        $dossier->update(['product_name' => 'Gewijzigd op de server']);
        $this->putJson('/api/product-dossiers/'.$dossier->id, ['product_name' => 'Oude naam', 'expected_revision' => $revision])->assertConflict();
    }

    public function test_expert_image_is_private_and_requires_fresh_approval(): void
    {
        Storage::fake('local');
        $dossier = $this->dossier(['expert' => ['name' => 'Testvakman', 'tip' => 'Testtip', 'approved' => true]]);
        $response = $this->post('/api/product-dossiers/'.$dossier->id.'/expert-assets/photo', ['file' => UploadedFile::fake()->image('testvakman.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->assertFalse($response->json('data.expert.approved'));
        Storage::fake('local'); // A deployment must not break the private expert photo.
        $this->get($response->json('expert_assets.photo.url'))->assertOk();
        $this->withSession(['userId' => User::factory()->create()->id]);
        $this->get($response->json('expert_assets.photo.url'))->assertNotFound();
    }

    public function test_supplier_chilled_storage_is_flagged_for_frozen_delivery(): void
    {
        $analysis = ProductDossierContent::reviewLabelStorage(['bewaaradvies' => 'Bewaren bij +2 °C.', 'waarschuwingen' => []]);
        $this->assertTrue($analysis['storage_needs_review']);
        $this->assertSame('Bewaren bij +2 °C.', $analysis['bewaaradvies']);
        $this->assertCount(1, $analysis['waarschuwingen']);
        $frozen = ProductDossierContent::reviewLabelStorage(['bewaaradvies' => 'Bewaren bij -18 °C.']);
        $this->assertArrayNotHasKey('storage_needs_review', $frozen);
    }

    public function test_images_can_only_be_linked_to_an_owned_dossier(): void
    {
        $dossier = $this->dossier();
        $images = ProductImageRequest::create(['user_id' => $dossier->user_id, 'status' => 'completed', 'source_path' => 'test', 'prompt' => 'Test', 'results' => [], 'generation_context' => ['product_name' => 'Test brisket']]);
        $other = ProductDossier::create(['user_id' => User::factory()->create()->id, 'product_name' => 'Andermans product']);
        $url = '/api/images/requests/'.$images->id.'/link-dossier';
        $this->postJson($url, ['product_dossier_id' => $other->id])->assertNotFound();
        $this->postJson($url, ['product_dossier_id' => $dossier->id])->assertOk();
        $this->getJson('/api/product-dossiers/'.$dossier->id)->assertJsonPath('image_requests.0.id', $images->id);
    }

    public function test_label_analysis_automatically_fills_missing_nutrition_without_overwriting_label_values(): void
    {
        Storage::fake('local');
        $dossier = $this->dossier();
        Storage::disk('local')->put('test-label.png', 'label');
        $dossier->update(['label_images' => [['path' => 'test-label.png', 'mime_type' => 'image/png']]]);
        Queue::fake();
        Http::fakeSequence()->push(['output_text' => json_encode(['ingrediënten' => 'Rundvlees', 'ingrediënten_bron' => 'etiket', 'voedingswaarden' => ['energie_kcal' => '189 kcal'], 'waarschuwingen' => []])])
            ->push(['output_text' => json_encode(['voedingswaarden' => ['energie_kcal' => 'ca. 200 kcal', 'eiwitten' => 'ca. 22 g'], 'aannames' => ['Onbewerkt vlees']])]);
        $this->postJson('/api/product-dossiers/'.$dossier->id.'/generate-page', ['operation' => 'label'])->assertAccepted();
        Queue::pushed(GenerateProductPage::class)->first()->handle(app(ProductDossierAiService::class));
        $dossier->refresh();
        $this->assertSame('completed', $dossier->generation['status']);
        $this->assertSame('189 kcal', $dossier->data['nutrition']['energie_kcal']);
        $this->assertSame('ca. 22 g', $dossier->data['nutrition']['eiwitten']);
        $this->assertSame('gemengd', $dossier->data['nutrition']['source_status']);
        $this->assertSame('etiket', $dossier->data['ingredients_source_status']);
    }

    public function test_failure_of_optional_nutrition_call_preserves_the_extracted_label(): void
    {
        Storage::fake('local');
        $dossier = $this->dossier();
        Storage::disk('local')->put('test-label.png', 'label');
        $dossier->update(['label_images' => [['path' => 'test-label.png', 'mime_type' => 'image/png']]]);
        Queue::fake();
        Http::fakeSequence()->push(['output_text' => json_encode(['ingrediënten' => 'Rundvlees', 'ingrediënten_bron' => 'etiket', 'voedingswaarden' => [], 'waarschuwingen' => []])])->push(['error' => ['code' => 'insufficient_quota']], 429);
        $this->postJson('/api/product-dossiers/'.$dossier->id.'/generate-page', ['operation' => 'label'])->assertAccepted();
        Queue::pushed(GenerateProductPage::class)->first()->handle(app(ProductDossierAiService::class));
        $dossier->refresh();
        $this->assertSame('completed', $dossier->generation['status']);
        $this->assertSame('Rundvlees', $dossier->data['ingredients']);
        $this->assertStringContainsString('projectbudget', $dossier->data['composition_warnings'][0]);
    }

    public function test_an_older_browser_cannot_erase_composition_sources_on_save(): void
    {
        $dossier = $this->dossier(['ingredients' => 'Rundvlees', 'ingredients_source_status' => 'etiket', 'allergens' => 'Geen verwacht', 'allergens_source_status' => 'ai_schatting']);
        $this->putJson('/api/product-dossiers/'.$dossier->id, ['product_name' => 'Test brisket', 'data' => ['ingredients' => 'Rundvlees', 'allergens' => 'Geen verwacht']])
            ->assertOk()->assertJsonPath('data.ingredients_source_status', 'etiket')->assertJsonPath('data.allergens_source_status', 'ai_schatting');
    }

    public function test_conflicting_legacy_storage_is_excluded_from_publishable_export(): void
    {
        $dossier = $this->dossier(['facts' => ['origin' => 'Uruguay', 'storage' => 'Bewaren bij +2 °C']]);
        $export = app(ProductDossierExport::class)->build($dossier);
        $this->assertArrayNotHasKey('storage', $export['pdp_fields']['facts']);
        $this->assertStringContainsString('diepgevroren levering', implode(' ', $export['internal_review_do_not_publish']['checks']));
    }
}

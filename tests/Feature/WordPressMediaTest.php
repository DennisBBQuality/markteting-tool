<?php

namespace Tests\Feature;

use App\Models\ProductImageAsset;
use App\Models\ProductImageMetadata;
use App\Models\ProductImageRequest;
use App\Models\WordPressConnection;
use App\Models\WordPressMediaTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WordPressMediaTest extends TestCase
{
    use RefreshDatabase;

    private function photo(): ProductImageAsset
    {
        $user = $this->actingAsUser(['rol' => 'lid']);
        $request = ProductImageRequest::create(['user_id' => $user->id, 'status' => 'completed', 'source_path' => 'test.png', 'prompt' => 'TEST',
            'generation_context' => ['product_name' => 'TEST saus', 'product_type' => 'sauce'],
            'results' => [['filename' => 'test.png', 'status' => 'rauw']]]);
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $asset = ProductImageAsset::create(['product_image_request_id' => $request->id, 'filename' => 'test.png',
            'mime_type' => 'image/png', 'version' => 1, 'refinement_status' => 'idle', 'contents_base64' => base64_encode($png)]);
        ProductImageMetadata::create(['product_image_asset_id' => $asset->id, 'image_version' => 1, 'revision' => 1,
            'status' => 'completed', 'fields' => ['filename' => 'test-saus-pot.webp', 'alt' => 'TEST saus in een pot',
                'title' => 'TEST saus', 'caption' => '', 'description' => 'TEST saus in een pot.']]);
        WordPressConnection::create(['destination' => 'local', 'username' => 'pitboard', 'password' => 'TEST_SECRET',
            'enabled' => true, 'tested_at' => now()]);
        Http::preventStrayRequests();

        return $asset;
    }

    private function url(ProductImageAsset $asset): string
    {
        return '/api/images/assets/'.$asset->id.'/wordpress';
    }

    private function approve(ProductImageAsset $asset): string
    {
        $fingerprint = $this->getJson($this->url($asset))->assertOk()->assertJsonPath('ready', true)->json('fingerprint');
        $this->putJson($this->url($asset).'/approval', ['fingerprint' => $fingerprint, 'approved' => true])->assertOk()->assertJsonPath('approved', true);

        return $fingerprint;
    }

    public function test_credentials_are_admin_only_encrypted_and_never_returned_and_changes_disable_uploads(): void
    {
        $this->actingAsUser(['rol' => 'lid']);
        $this->getJson('/api/settings/wordpress')->assertForbidden();
        $this->putJson('/api/settings/wordpress', [])->assertForbidden();
        $this->postJson('/api/settings/wordpress/test')->assertForbidden();
        $this->actingAsUser(['rol' => 'admin']);
        $this->putJson('/api/settings/wordpress', ['username' => 'pitboard', 'password' => 'normal-login-password',
            'enabled' => true, 'confirm_public' => true])->assertUnprocessable();
        $this->putJson('/api/settings/wordpress', ['username' => 'pitboard', 'password' => 'TEST TEST TEST TEST TEST TEST',
            'enabled' => true, 'confirm_public' => true])->assertOk()->assertJsonPath('enabled', false)->assertDontSee('TEST');
        $this->assertSame(str_repeat('TEST', 6), WordPressConnection::first()->password);
        $this->assertStringNotContainsString(str_repeat('TEST', 6), DB::table('wordpress_connections')->value('password'));
        $this->assertArrayNotHasKey('password', WordPressConnection::first()->toArray());
        Http::fake(['*' => Http::response(['protocol' => 2, 'username' => 'pitboard', 'site_url' => 'https://bbquality.test', 'can_upload' => true])]);
        $this->postJson('/api/settings/wordpress/test')->assertOk();
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/connection'));
        $this->putJson('/api/settings/wordpress', ['username' => 'pitboard', 'enabled' => true, 'confirm_public' => true])->assertOk()->assertJsonPath('enabled', true);
        $this->putJson('/api/settings/wordpress', ['username' => 'other', 'enabled' => true, 'confirm_public' => true])->assertOk()->assertJsonPath('enabled', false);
    }

    public function test_connection_rejects_wrong_identity_and_hides_remote_errors(): void
    {
        $this->photo();
        $this->actingAsUser(['rol' => 'admin']);
        Http::fake(['*' => Http::response(['protocol' => 2, 'username' => 'admin', 'can_upload' => true, 'site_url' => 'https://bbquality.test'])]);
        $this->postJson('/api/settings/wordpress/test')->assertUnprocessable();
        Http::fake(['*' => Http::response('secret TEST_SECRET html', 401)]);
        $this->postJson('/api/settings/wordpress/test')->assertUnprocessable()->assertDontSee('TEST_SECRET')->assertDontSee('html');
    }

    public function test_owner_and_approval_required_before_any_network_request(): void
    {
        $asset = $this->photo();
        $fp = $this->getJson($this->url($asset))->json('fingerprint');
        $this->postJson($this->url($asset), ['fingerprint' => $fp])->assertUnprocessable();
        $this->actingAsUser(['rol' => 'admin']);
        $this->getJson($this->url($asset))->assertNotFound();
        $this->postJson($this->url($asset), ['fingerprint' => $fp])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_seo_or_image_changes_invalidate_approval_and_old_browser_requests(): void
    {
        $asset = $this->photo();
        $fp = $this->approve($asset);
        ProductImageMetadata::query()->increment('revision');
        $this->getJson($this->url($asset))->assertJsonPath('approved', false);
        $this->postJson($this->url($asset), ['fingerprint' => $fp])->assertConflict();
        $this->putJson($this->url($asset).'/approval', ['fingerprint' => $fp, 'approved' => true])->assertConflict();
        $asset->update(['version' => 2]);
        $this->getJson($this->url($asset))->assertJsonPath('approved', false)->assertJsonPath('ready', false);
        Http::assertNothingSent();
    }

    public function test_upload_and_retry_use_same_reference_and_only_media_fields_with_webp(): void
    {
        $asset = $this->photo();
        $fp = $this->approve($asset);
        Http::fakeSequence()->push(['found' => false, 'revision' => 0])->push(['attachment_id' => 42, 'url' => 'https://bbquality.test/wp-content/uploads/test.webp'])
            ->push(['found' => true, 'revision' => 1])->push(['attachment_id' => 42, 'url' => 'https://bbquality.test/wp-content/uploads/test.webp']);
        $this->postJson($this->url($asset), ['fingerprint' => $fp])->assertOk()->assertJsonPath('uploaded', true);
        $this->postJson($this->url($asset), ['fingerprint' => $fp])->assertOk()->assertJsonPath('attachment_id', 42);
        $posts = Http::recorded(fn ($request) => $request->method() === 'POST')->values();
        $this->assertCount(2, $posts);
        $this->assertSame(collect($posts[0][0]->data())->keyBy('name')['source_ref']['contents'], collect($posts[1][0]->data())->keyBy('name')['source_ref']['contents']);
        foreach ($posts as [$request]) {
            $data = collect($request->data())->keyBy('name')->all();
            $this->assertSame('pitboard', $data['source']['contents']);
            $this->assertArrayNotHasKey('product_id', $data);
            $this->assertArrayNotHasKey('placement', $data);
            $this->assertSame('test-saus-pot.webp', $data['filename']['contents']);
            $this->assertTrue($request->hasFile('file', null, 'test-saus-pot.webp'));
        }
        $this->assertDatabaseCount('wordpress_media_transfers', 1);
    }

    public function test_concurrent_claim_blocks_second_request_and_timeout_is_not_false_success(): void
    {
        $asset = $this->photo();
        $fp = $this->approve($asset);
        WordPressMediaTransfer::query()->update(['status' => 'uploading', 'claimed_at' => now()]);
        $this->postJson($this->url($asset), ['fingerprint' => $fp])->assertConflict();
        Http::assertNothingSent();
        WordPressMediaTransfer::query()->update(['claimed_at' => now()->subMinutes(4)]);
        Http::fake(fn () => throw new ConnectionException('Authorization: TEST_SECRET'));
        $this->postJson($this->url($asset), ['fingerprint' => $fp])->assertUnprocessable()->assertDontSee('TEST_SECRET');
        $this->getJson($this->url($asset))->assertJsonPath('uploaded', false)->assertJsonPath('status', 'unconfirmed');
    }

    public function test_test_upload_status_never_counts_for_live_destination(): void
    {
        $asset = $this->photo();
        $this->approve($asset);
        $this->app->instance('env', 'production');
        $this->getJson($this->url($asset))->assertJsonPath('enabled', false)->assertJsonPath('approved', false)->assertJsonPath('destination', 'live');
    }

    public function test_root_relative_media_url_is_resolved_without_creating_another_attachment(): void
    {
        $asset = $this->photo();
        $fp = $this->approve($asset);
        Http::fakeSequence()->push(['found' => true, 'revision' => 1])->push([
            'attachment_id' => 42, 'url' => '/wp-content/uploads/test.webp', 'status' => 'exists',
        ]);
        $this->postJson($this->url($asset), ['fingerprint' => $fp])->assertOk()
            ->assertJsonPath('uploaded', true)->assertJsonPath('attachment_id', 42)
            ->assertJsonPath('url', 'https://bbquality.test/wp-content/uploads/test.webp');
    }

    public function test_accepted_but_unfinished_upload_is_never_marked_complete(): void
    {
        $asset = $this->photo();
        $fp = $this->approve($asset);
        Http::fakeSequence()->push(['found' => false, 'revision' => 0])->push([
            'attachment_id' => 42, 'url' => 'https://bbquality.test/wp-content/uploads/test.webp',
        ], 202);
        $this->postJson($this->url($asset), ['fingerprint' => $fp])->assertUnprocessable();
        $this->getJson($this->url($asset))->assertJsonPath('uploaded', false)->assertJsonPath('status', 'unconfirmed');
    }

    public function test_storage_upgrade_is_idempotent_and_preserves_connections(): void
    {
        $this->photo();
        $id = WordPressConnection::first()->id;
        $this->artisan('pitboard:upgrade-wordpress-media-storage')->assertSuccessful();
        $this->artisan('pitboard:upgrade-wordpress-media-storage')->assertSuccessful();
        $this->assertDatabaseCount('wordpress_connections', 1);
        $this->assertSame($id, WordPressConnection::first()->id);
        $this->assertTrue(WordPressConnection::first()->enabled);
    }

    public function test_remote_upload_errors_are_safe_and_do_not_claim_success(): void
    {
        $asset = $this->photo();
        $fp = $this->approve($asset);
        foreach ([401, 403, 409, 413, 415, 500] as $status) {
            Http::fakeSequence()->push(['found' => false, 'revision' => 0])
                ->push(['message' => 'Authorization TEST_SECRET'], $status);
            $this->postJson($this->url($asset), ['fingerprint' => $fp])->assertUnprocessable()->assertDontSee('TEST_SECRET');
            $this->getJson($this->url($asset))->assertJsonPath('uploaded', false);
        }
    }

    public function test_external_and_protocol_relative_media_urls_are_not_accepted(): void
    {
        $asset = $this->photo();
        $fp = $this->approve($asset);
        foreach (['//other.example/test.webp', '/\\other.example/test.webp', 'https://other.example/test.webp'] as $url) {
            Http::fakeSequence()->push(['found' => true, 'revision' => 1])->push(['attachment_id' => 42, 'url' => $url]);
            $this->postJson($this->url($asset), ['fingerprint' => $fp])->assertUnprocessable();
            $this->getJson($this->url($asset))->assertJsonPath('uploaded', false);
        }
    }
}

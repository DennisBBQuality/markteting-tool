<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebpConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversion_downloads_decodable_webp_with_custom_name_for_each_role(): void
    {
        Storage::fake('public');
        foreach (['admin', 'manager', 'lid'] as $role) {
            $this->actingAsUser(['rol' => $role]);
            $result = $this->postJson('/api/convert/webp', [
                'bestanden' => [UploadedFile::fake()->image('TEST foto.jpg', 128, 64)],
                'quality' => 80,
            ])->assertOk()->json('results.0');
            $this->assertArrayNotHasKey('error', $result);
            $this->assertSame('/api/convert/download', parse_url($result['download_url'], PHP_URL_PATH));
            $this->assertSame('TEST foto.webp', $result['geconverteerd']);
            $download = $this->get($result['download_url'].'&naam=TEST-keuze.webp')->assertOk()
                ->assertDownload('TEST-keuze.webp')->assertHeader('Content-Type', 'image/webp');
            $bytes = $download->streamedContent();
            $this->assertSame($result['geconverteerd_grootte'], strlen($bytes));
            $image = imagecreatefromstring($bytes);
            $this->assertNotFalse($image);
            $this->assertSame(128, imagesx($image));
            $this->assertSame(64, imagesy($image));
            imagedestroy($image);
        }
    }

    public function test_same_named_uploads_do_not_overwrite_each_other(): void
    {
        Storage::fake('public');
        $this->actingAsUser();
        $results = $this->postJson('/api/convert/webp', ['bestanden' => [
            UploadedFile::fake()->image('TEST.jpg', 80, 40),
            UploadedFile::fake()->image('TEST.jpg', 40, 80),
        ]])->assertOk()->json('results');
        $this->assertNotSame($results[0]['download_url'], $results[1]['download_url']);
        $this->assertCount(2, Storage::disk('public')->files('converted'));
    }

    public function test_existing_converted_files_can_use_new_route(): void
    {
        Storage::fake('public');
        $this->actingAsUser();
        Storage::disk('public')->put('converted/TEST foto-123.webp', 'TEST existing bytes');
        $this->assertSame('TEST existing bytes', $this->get('/api/convert/download?bestand=TEST%20foto-123.webp')->assertOk()->streamedContent());
        $this->get('/api/convert/download/TEST%20foto-123.webp')->assertOk();
    }

    public function test_download_requires_login_and_rejects_missing_and_unsafe_paths(): void
    {
        $this->getJson('/api/convert/download?bestand=TEST.webp')->assertUnauthorized();
        $this->actingAsUser();
        Storage::fake('public');
        foreach (['', 'missing.webp', '../TEST.webp', '..\\TEST.webp', 'TEST.txt'] as $name) {
            $this->getJson('/api/convert/download?bestand='.rawurlencode($name))->assertNotFound();
        }
        $this->getJson('/api/convert/download?bestand[]=TEST.webp')->assertNotFound();
    }

    public function test_storage_failure_is_not_reported_as_success(): void
    {
        $this->actingAsUser();
        Storage::shouldReceive('disk')->with('public')->andReturnSelf();
        Storage::shouldReceive('put')->once()->andReturn(false);
        $result = $this->postJson('/api/convert/webp', ['bestanden' => [UploadedFile::fake()->image('TEST.jpg')]])->assertOk()->json('results.0');
        $this->assertStringContainsString('niet worden opgeslagen', $result['error']);
        $this->assertArrayNotHasKey('download_url', $result);
    }

    public function test_invalid_image_does_not_report_conversion_success(): void
    {
        $this->actingAsUser();
        $result = $this->postJson('/api/convert/webp', ['bestanden' => [UploadedFile::fake()->createWithContent('TEST.jpg', 'not an image')]])->assertOk()->json('results.0');
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('download_url', $result);
    }
}

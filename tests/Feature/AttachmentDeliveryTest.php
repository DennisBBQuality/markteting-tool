<?php

namespace Tests\Feature;

use App\Models\Attachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_uploaded_photo_can_be_previewed_and_downloaded_by_all_team_roles(): void
    {
        foreach (['admin', 'manager', 'lid'] as $role) {
            $this->actingAsUser(['rol' => $role]);
            $upload = $this->postJson('/api/attachments', [
                'bestand' => UploadedFile::fake()->image('TEST foto.jpg'),
            ])->assertOk();
            $id = $upload->json('id');
            $this->get('/api/attachments/'.$id.'/preview')->assertOk()
                ->assertHeader('Content-Type', 'image/jpeg')
                ->assertHeader('Content-Disposition', 'inline')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->get('/api/attachments/'.$id.'/download')->assertOk()
                ->assertDownload('TEST foto.jpg')
                ->assertHeader('Content-Type', 'application/octet-stream');
        }
    }

    public function test_existing_files_work_without_reupload_or_database_changes(): void
    {
        $this->actingAsUser();
        $file = UploadedFile::fake()->image('TEST.png');
        Storage::disk('local')->put('uploads/legacy.png', file_get_contents($file->getRealPath()));
        $attachment = Attachment::create(['bestandsnaam' => 'legacy.png', 'originele_naam' => 'TEST bestaand.png']);
        $this->get('/api/attachments/'.$attachment->id.'/preview')->assertOk();
        $download = $this->get('/api/attachments/'.$attachment->id.'/download')->assertDownload('TEST bestaand.png');
        $this->assertSame(file_get_contents($file->getRealPath()), file_get_contents($download->baseResponse->getFile()->getPathname()));
    }

    public function test_delivery_requires_login(): void
    {
        $attachment = Attachment::create(['bestandsnaam' => 'TEST.png', 'originele_naam' => 'TEST.png']);
        foreach (['preview', 'download'] as $action) {
            $this->getJson('/api/attachments/'.$attachment->id.'/'.$action)->assertUnauthorized();
        }
    }

    public function test_missing_files_and_unsafe_paths_are_not_served(): void
    {
        $this->actingAsUser();
        Storage::disk('local')->put('secret.txt', 'TEST secret');
        foreach (['missing.jpg', '../secret.txt', '..\\secret.txt'] as $filename) {
            $attachment = Attachment::create(['bestandsnaam' => $filename, 'originele_naam' => 'TEST.jpg']);
            foreach (['preview', 'download'] as $action) {
                $this->getJson('/api/attachments/'.$attachment->id.'/'.$action)->assertNotFound();
            }
        }
    }

    public function test_non_images_cannot_be_previewed_even_with_an_image_mime_label(): void
    {
        $this->actingAsUser();
        Storage::disk('local')->put('uploads/fake.jpg', '<html>TEST</html>');
        $attachment = Attachment::create(['bestandsnaam' => 'fake.jpg', 'originele_naam' => 'TEST.jpg', 'mimetype' => 'image/jpeg']);
        $this->getJson('/api/attachments/'.$attachment->id.'/preview')->assertStatus(415);
        $this->get('/api/attachments/'.$attachment->id.'/download')->assertDownload('TEST.jpg')
            ->assertHeader('Content-Type', 'application/octet-stream');
    }

    public function test_failed_storage_does_not_create_a_successful_attachment_record(): void
    {
        $this->actingAsUser();
        Storage::shouldReceive('disk')->with('local')->andReturnSelf();
        Storage::shouldReceive('putFileAs')->once()->andReturn(false);
        $this->postJson('/api/attachments', ['bestand' => UploadedFile::fake()->image('TEST.jpg')])->assertStatus(500);
        $this->assertDatabaseCount('attachments', 0);
    }
}

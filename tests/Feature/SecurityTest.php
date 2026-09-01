<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_regenerates_the_session_id(): void
    {
        User::factory()->create([
            'email' => 'login@example.test',
            'wachtwoord_hash' => Hash::make('veilig-wachtwoord'),
        ]);

        $session = $this->app['session']->driver();
        $session->setId(str_repeat('a', 40));
        $this->withSession(['voor_login' => true]);
        $oldSessionId = $session->getId();

        $this->postJson('/api/auth/login', [
            'email' => 'login@example.test',
            'wachtwoord' => 'veilig-wachtwoord',
        ])->assertOk();

        $this->assertNotSame($oldSessionId, $session->getId());
    }

    public function test_login_is_rate_limited_after_five_attempts_per_minute(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'onbekend@example.test',
                'wachtwoord' => 'onjuist',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', [
            'email' => 'onbekend@example.test',
            'wachtwoord' => 'onjuist',
        ])->assertTooManyRequests();
    }

    public function test_an_inactive_user_loses_access_on_the_next_request(): void
    {
        $user = $this->actingAsUser();
        $user->update(['actief' => false]);

        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJson(['error' => 'Niet ingelogd'])
            ->assertSessionMissing('userId')
            ->assertSessionMissing('rol');
    }

    public function test_admin_authorization_uses_the_current_database_role(): void
    {
        $user = $this->actingAsUser(['rol' => 'admin']);
        $user->update(['rol' => 'lid']);

        $this->postJson('/api/users', [])
            ->assertForbidden()
            ->assertJson(['error' => 'Geen toegang'])
            ->assertSessionHas('rol', 'lid');
    }

    public function test_scriptable_upload_formats_are_rejected(): void
    {
        Storage::fake('local');
        $this->actingAsUser();

        foreach (['html', 'svg'] as $extension) {
            $response = $this->post('/api/attachments', [
                'bestand' => UploadedFile::fake()->createWithContent(
                    "kwaadaardig.$extension",
                    '<script>alert(document.domain)</script>'
                ),
            ], ['Accept' => 'application/json']);

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors('bestand');
        }

        Storage::disk('local')->assertDirectoryEmpty('uploads');
    }

    public function test_a_safe_image_upload_is_stored_with_a_server_detected_mime_type(): void
    {
        Storage::fake('local');
        $this->actingAsUser();

        $response = $this->post('/api/attachments', [
            'bestand' => UploadedFile::fake()->image('campagne.jpg', 20, 20),
        ], ['Accept' => 'application/json']);

        $response
            ->assertOk()
            ->assertJson([
                'originele_naam' => 'campagne.jpg',
                'mimetype' => 'image/jpeg',
            ]);

        Storage::disk('local')->assertExists('uploads/'.$response->json('bestandsnaam'));
    }

    public function test_uploaded_files_are_protected_against_content_sniffing_and_documents_download(): void
    {
        Storage::fake('local');
        $this->actingAsUser();
        Storage::disk('local')->put('uploads/rapport.pdf', "%PDF-1.4\n%%EOF");

        $this->get('/uploads/rapport.pdf')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'attachment; filename="rapport.pdf"');
    }

    public function test_uploaded_files_require_authentication_to_download(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('uploads/geheim.pdf', 'secret');

        $this->getJson('/uploads/geheim.pdf')
            ->assertUnauthorized()
            ->assertJson(['error' => 'Niet ingelogd']);
    }

    public function test_existing_public_uploads_move_to_private_storage(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('public')->put('uploads/bestaand.pdf', 'inhoud');

        $migration = require database_path('migrations/2026_09_01_120000_move_existing_uploads_to_local_disk.php');
        $migration->up();

        Storage::disk('public')->assertMissing('uploads/bestaand.pdf');
        Storage::disk('local')->assertExists('uploads/bestaand.pdf');
        $this->assertSame('inhoud', Storage::disk('local')->get('uploads/bestaand.pdf'));
    }

    public function test_upload_migration_never_overwrites_an_existing_private_file(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('public')->put('uploads/dubbel.pdf', 'openbaar');
        Storage::disk('local')->put('uploads/dubbel.pdf', 'privé');

        $migration = require database_path('migrations/2026_09_01_120000_move_existing_uploads_to_local_disk.php');
        $migration->up();

        Storage::disk('public')->assertExists('uploads/dubbel.pdf');
        $this->assertSame('privé', Storage::disk('local')->get('uploads/dubbel.pdf'));
    }

    public function test_upload_migration_rollback_keeps_private_files_private(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('local')->put('uploads/geheim.pdf', 'privé');

        $migration = require database_path('migrations/2026_09_01_120000_move_existing_uploads_to_local_disk.php');
        $migration->down();

        Storage::disk('local')->assertExists('uploads/geheim.pdf');
        Storage::disk('public')->assertMissing('uploads/geheim.pdf');
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthHelperTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_factory_creates_a_user_against_the_real_schema(): void
    {
        $user = User::factory()->create();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'naam' => $user->naam,
            'email' => $user->email,
            'wachtwoord_hash' => $user->wachtwoord_hash,
            'rol' => $user->rol,
            'kleur' => $user->kleur,
            'actief' => true,
        ]);
    }

    public function test_auth_me_returns_the_expected_user_after_acting_as_user(): void
    {
        $user = $this->actingAsUser([
            'naam' => 'Test Medewerker',
            'email' => 'test.medewerker@example.test',
            'rol' => 'manager',
            'kleur' => '#123456',
        ]);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson([
                'id' => $user->id,
                'naam' => $user->naam,
                'email' => $user->email,
                'rol' => $user->rol,
                'kleur' => $user->kleur,
                'avatar' => null,
            ])
            ->assertJsonMissingPath('wachtwoord_hash');
    }

    public function test_auth_me_returns_unauthorized_without_an_authenticated_session(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJson(['error' => 'Niet ingelogd']);
    }
}

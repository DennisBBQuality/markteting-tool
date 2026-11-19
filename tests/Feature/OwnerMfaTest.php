<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\OwnerAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class OwnerMfaTest extends TestCase
{
    use RefreshDatabase;

    private array $credentials = ['email' => 'pilot@example.test', 'wachtwoord' => 'test-password-only'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        config(['owner.invitation' => hash_hmac('sha256', $this->credentials['email'], 'example-invite')]);
        User::factory()->create(['email' => $this->credentials['email'], 'rol' => 'admin',
            'wachtwoord_hash' => Hash::make($this->credentials['wachtwoord'])]);
    }

    private function rememberSession(): void
    {
        $this->withCookie(config('session.cookie'), session()->getId());
    }

    private function enrol(): array
    {
        $setup = $this->postJson('/api/auth/login', $this->credentials + ['invitation' => 'example-invite'])
            ->assertOk()->assertSessionMissing('userId')->assertJsonPath('step', 'enrol');
        $this->rememberSession();
        $this->getJson('/api/settings/trunkrs')->assertUnauthorized();
        $code = (new Google2FA)->getCurrentOtp($setup->json('secret'));
        $result = $this->postJson('/api/auth/mfa', ['code' => $code, 'trust' => true])->assertOk()->assertJsonCount(8, 'recovery');
        $this->rememberSession();
        $this->assertNotSame($setup->json('secret'), OwnerAccess::record()->secret);

        return [$code, $result];
    }

    public function test_only_invited_identity_can_claim_admin_and_old_admin_sessions_are_denied(): void
    {
        $other = User::factory()->create(['rol' => 'admin', 'wachtwoord_hash' => Hash::make('other-password')]);
        $this->postJson('/api/auth/login', ['email' => $other->email, 'wachtwoord' => 'other-password', 'invitation' => 'example-invite'])->assertForbidden();
        $this->postJson('/api/auth/login', $this->credentials + ['invitation' => 'wrong'])->assertForbidden();
        $this->enrol();
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('rol', 'admin');
        $this->postJson('/api/users', [])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => $other->email, 'wachtwoord' => 'other-password'])->assertJsonPath('rol', 'manager');
        $this->rememberSession();
        $this->getJson('/api/auth/me')->assertJsonPath('rol', 'manager');
        $this->postJson('/api/users', [])->assertForbidden();
    }

    public function test_cookie_deadline_is_absolute_and_fresh_browser_requires_mfa(): void
    {
        [, $result] = $this->enrol();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', $this->credentials)->assertJsonPath('step', 'verify')->assertSessionMissing('userId');
        $cookie = $result->getCookie(OwnerAccess::COOKIE);
        $this->assertTrue($cookie->isSecure() && $cookie->isHttpOnly());
        $this->withCookie(OwnerAccess::COOKIE, $cookie->getValue());
        $this->travel(29)->days();
        $this->postJson('/api/auth/login', $this->credentials)->assertJsonPath('rol', 'admin');
        $this->rememberSession();
        $this->travel(1)->days();
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/login', $this->credentials)->assertJsonPath('step', 'verify');
    }

    public function test_recovery_replaces_factor_and_revokes_old_browser_and_codes(): void
    {
        [$code, $result] = $this->enrol();
        $this->postJson('/api/auth/login', $this->credentials)->assertJsonPath('step', 'verify');
        $this->rememberSession();
        $this->postJson('/api/auth/mfa', ['code' => $code])->assertUnprocessable();
        $recovery = $result->json('recovery.0');
        $replace = $this->postJson('/api/auth/mfa', ['code' => $recovery])->assertJsonPath('step', 'enrol');
        $this->postJson('/api/auth/mfa', ['code' => (new Google2FA)->getCurrentOtp($replace->json('secret'))])->assertOk()->assertJsonCount(8, 'recovery');
        $this->withCookie(OwnerAccess::COOKIE, $result->getCookie(OwnerAccess::COOKIE)->getValue());
        $this->postJson('/api/auth/login', $this->credentials)->assertJsonPath('step', 'verify');
        $this->rememberSession();
        $this->postJson('/api/auth/mfa', ['code' => $recovery])->assertUnprocessable();
    }
}

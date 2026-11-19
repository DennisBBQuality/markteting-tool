<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\OwnerAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MemberMfaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pilot_is_personal_and_preserves_owner_privileges_and_fixed_expiry(): void
    {
        $this->withCredentials();
        $owner = $this->actingAsUser(['rol' => 'admin']);
        $password = 'member-test-password';
        $pilot = User::factory()->create(['rol' => 'admin', 'wachtwoord_hash' => Hash::make($password)]);
        $other = User::factory()->create(['rol' => 'lid', 'wachtwoord_hash' => Hash::make($password)]);
        $endpoint = "/api/users/{$pilot->id}/mfa";
        $this->putJson($endpoint)->assertOk()->assertJsonPath('mfa_status', 'pending');
        $this->putJson($endpoint)->assertOk();
        $this->assertDatabaseCount('pitboard_member_security', 1);
        $this->withSession(['userId' => $pilot->id])->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $other->email, 'wachtwoord' => $password])->assertJsonPath('rol', 'lid');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->putJson($endpoint)->assertForbidden();
        $this->getJson('/api/users')->assertJsonMissingPath('0.mfa_status');

        $login = ['email' => $pilot->email, 'wachtwoord' => $password];
        // An open tab from before MFA cannot display a challenge.
        $this->postJson('/api/auth/login', $login)->assertStatus(409)
            ->assertJsonPath('message', 'Je inlogscherm is verouderd. Vernieuw dit tabblad en log opnieuw in om je authenticator te gebruiken.')
            ->assertSessionMissing('userId')->assertSessionMissing('mfa_pending')->assertJsonMissingPath('secret');
        $this->withCookie(config('session.cookie'), session()->getId());
        // The first MFA release sent an empty invitation for ordinary members.
        $this->postJson('/api/auth/login', $login + ['invitation' => ''])->assertJsonPath('step', 'enrol');
        $this->withHeader('X-Pitboard-Auth', 'mfa-v1');
        $setup = $this->postJson('/api/auth/login', $login)->assertOk()->assertJsonPath('step', 'enrol')->assertSessionMissing('userId');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/mfa', ['code' => 'invalid'])->assertUnprocessable();
        $otp = (new Google2FA)->getCurrentOtp($setup->json('secret'));
        $result = $this->postJson('/api/auth/mfa', ['code' => $otp, 'trust' => true])
            ->assertOk()->assertJsonPath('user.rol', 'manager')->assertJsonCount(8, 'recovery');
        $this->assertSame($owner->id, OwnerAccess::record()->user_id);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->putJson($endpoint)->assertForbidden();
        $this->postJson('/api/auth/login', $login)->assertJsonPath('step', 'verify');
        $this->withCookie(OwnerAccess::COOKIE, $result->getCookie(OwnerAccess::COOKIE)->getValue());
        $this->travel(29)->days();
        $this->postJson('/api/auth/login', $login)->assertJsonPath('rol', 'manager');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->travel(1)->days();
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/login', $login)->assertJsonPath('step', 'verify');
        $this->withCookie(config('session.cookie'), session()->getId());
        $replace = $this->postJson('/api/auth/mfa', ['code' => $result->json('recovery.0')])->assertJsonPath('step', 'enrol');
        $this->postJson('/api/auth/mfa', ['code' => (new Google2FA)->getCurrentOtp($replace->json('secret'))])
            ->assertOk()->assertJsonCount(8, 'recovery');
        $this->assertSame($owner->id, OwnerAccess::record()->user_id);
    }
}

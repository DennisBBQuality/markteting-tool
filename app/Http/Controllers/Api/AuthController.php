<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\OwnerAccess;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate(['email' => 'required|email', 'wachtwoord' => 'required|string', 'invitation' => 'nullable|string|max:128']);
        $user = User::where('email', $request->email)->where('actief', true)->first();
        if (! $user || ! password_verify($request->wachtwoord, $user->wachtwoord_hash)) {
            return response()->json(['error' => 'Ongeldige inloggegevens'], 401);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        OwnerAccess::ensureStorage();
        $owner = OwnerAccess::record();
        if ($request->filled('invitation')) {
            $digest = hash_hmac('sha256', strtolower($user->email), trim($request->invitation));
            abort_unless(! $owner->user_id && $user->rol === 'admin' && hash_equals(config('owner.invitation'), $digest), 403, 'Activatiecode niet geldig.');
            $this->challenge($request, $user, $owner);

            abort_unless(class_exists(Google2FA::class), 503, 'De server moet de MFA-bibliotheek nog installeren.');

            return $this->setup($request, $user, app(Google2FA::class));
        }

        $isOwner = $owner->user_id === $user->id;
        if (! $isOwner && $user->rol === 'admin') {
            $user->rol = 'manager';
        }
        $factor = $isOwner ? $owner : OwnerAccess::member($user->id)->first();
        if ($factor) {
            $proof = json_decode($request->cookie(OwnerAccess::COOKIE) ?? '', true);
            if (! $factor->secret || ! OwnerAccess::valid($proof, $user, $factor)) {
                $this->challenge($request, $user, $factor, ! $isOwner);
                if (! $factor->secret) {
                    return $this->setup($request, $user, app(Google2FA::class));
                }

                return response()->json(['step' => 'verify'])->header('Cache-Control', 'no-store');
            }
            $request->session()->put('owner_proof', $proof);
        }

        OwnerAccess::admit($request, $user);

        return response()->json(OwnerAccess::profile($user));
    }

    private function challenge(Request $request, User $user, object $owner, bool $member = false): void
    {
        // The first MFA-aware browser release always sent the invitation field, even when empty.
        // Earlier tabs interpret every successful login response as a complete user profile.
        abort_unless($request->header('X-Pitboard-Auth') === 'mfa-v1' || $request->has('invitation'),
            409, 'Je inlogscherm is verouderd. Vernieuw dit tabblad en log opnieuw in om je authenticator te gebruiken.');

        $request->session()->put('mfa_pending', [
            'user' => $user->id, 'member' => $member, 'expires' => now()->addMinutes(10)->timestamp,
            'stamp' => OwnerAccess::stamp($user, $owner),
        ]);
    }

    private function setup(Request $request, User $user, Google2FA $totp)
    {
        $secret = $totp->generateSecretKey(32);
        $request->session()->put('mfa_pending.secret', Crypt::encryptString($secret));
        $uri = $totp->getQRCodeUrl('BBQuality The Pitboard', $user->email, $secret);
        $qr = class_exists(\XMLWriter::class)
            ? base64_encode((new Writer(new ImageRenderer(new RendererStyle(225), new SvgImageBackEnd)))->writeString($uri))
            : null;

        return response()->json(['step' => 'enrol', 'qr' => $qr, 'secret' => $secret])->header('Cache-Control', 'no-store');
    }

    public function verify(Request $request, Google2FA $totp)
    {
        $request->validate(['code' => 'required|string|max:64', 'trust' => 'sometimes|boolean']);
        $pending = $request->session()->get('mfa_pending');
        abort_unless($pending && $pending['expires'] > now()->timestamp, 401, 'Log opnieuw in.');

        return DB::transaction(function () use ($request, $totp, $pending) {
            $user = User::where('actief', true)->findOrFail($pending['user']);
            $member = $pending['member'] ?? false;
            $record = $member ? OwnerAccess::member($user->id) : DB::table('pitboard_owner_security')->where('id', 1);
            $factor = $record->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals(OwnerAccess::stamp($user, $factor), $pending['stamp']), 401, 'Log opnieuw in.');
            $enrol = isset($pending['secret']);
            if ($member && $user->rol === 'admin') {
                $user->rol = 'manager';
            }
            abort_unless($factor->user_id === $user->id || (! $member && $enrol && ! $factor->user_id && $user->rol === 'admin'), 403);

            $code = strtoupper(trim($request->code));
            if (! $enrol && preg_match('/^[A-F0-9]{20}$/D', $code)) {
                $hashes = json_decode($factor->recovery_hashes, true);
                $index = array_search(hash('sha256', $code), $hashes, true);
                abort_if($index === false, 422, 'Herstelcode niet geldig.');
                unset($hashes[$index]);
                $record->update(['recovery_hashes' => json_encode(array_values($hashes))]);

                return $this->setup($request, $user, $totp);
            }

            $secret = Crypt::decryptString($enrol ? $pending['secret'] : $factor->secret);
            $step = preg_match('/^\d{6}$/D', $code)
                ? $totp->verifyKeyNewer($secret, $code, $enrol ? 0 : $factor->last_step, 1) : false;
            abort_if($step === false, 422, 'Code ongeldig of al gebruikt. Probeer de volgende code.');
            $update = ['last_step' => $step];
            $codes = [];
            if ($enrol) {
                for ($i = 0; $i < 8; $i++) {
                    $codes[] = strtoupper(bin2hex(random_bytes(10)));
                }
                $factor->generation++;
                $update += ['secret' => $pending['secret'], 'user_id' => $user->id, 'generation' => $factor->generation,
                    'recovery_hashes' => json_encode(array_map(fn ($value) => hash('sha256', $value), $codes))];
            }
            $record->update($update);
            $proof = ['stamp' => OwnerAccess::stamp($user, $factor), 'until' => now()->addDays(30)->timestamp];
            $request->session()->forget('mfa_pending');
            $request->session()->put('owner_proof', $proof);
            OwnerAccess::admit($request, $user);
            $response = response()->json(['user' => OwnerAccess::profile($user), 'recovery' => $codes])->header('Cache-Control', 'no-store');

            return $request->boolean('trust')
                ? $response->cookie(OwnerAccess::COOKIE, json_encode($proof), 43200, '/', null, true, true, false, 'strict')
                : $response->withoutCookie(OwnerAccess::COOKIE);
        });
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        return response()->json(OwnerAccess::profile($request->attributes->get('authenticatedUser')));
    }
}

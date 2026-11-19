<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OwnerAccess
{
    public const COOKIE = 'pitboard_mfa_browser';

    public static function record(bool $lock = false): object
    {
        $query = DB::table('pitboard_owner_security')->where('id', 1);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public static function stamp(User $user, object $owner): string
    {
        return hash_hmac('sha256', implode('|', [$user->id, $user->wachtwoord_hash, $owner->generation]), config('app.key'));
    }

    public static function valid(mixed $proof, User $user, object $owner): bool
    {
        return is_array($proof) && ($proof['until'] ?? 0) > now()->timestamp
            && hash_equals(self::stamp($user, $owner), $proof['stamp'] ?? '');
    }

    public static function admit(Request $request, User $user): void
    {
        $request->session()->regenerate(true);
        $request->session()->put(['userId' => $user->id, 'rol' => $user->rol]);
    }

    public static function profile(User $user): array
    {
        return $user->only('id', 'naam', 'email', 'rol', 'kleur', 'avatar');
    }
}

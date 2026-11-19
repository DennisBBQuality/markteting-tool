<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\OwnerAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class RequireAuth
{
    public function handle(Request $request, Closure $next)
    {
        $userId = $request->session()->get('userId');

        if (! $userId) {
            $response = response()->json(['error' => 'Niet ingelogd'], 401);
            if ($request->is('api/auth/me') && (! Schema::hasTable('pitboard_owner_security') || ! OwnerAccess::record()->user_id)) {
                $response->headers->set('X-Pitboard-Owner-Setup', 'available');
            }

            return $response;
        }

        $user = User::find($userId);

        if (! $user || ! $user->actief) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['error' => 'Niet ingelogd'], 401);
        }

        if (! Schema::hasTable('pitboard_owner_security')) {
            return response()->json(['error' => 'Log opnieuw in om de beveiligingsupdate af te ronden.'], 401);
        }
        OwnerAccess::ensureStorage();
        $owner = OwnerAccess::record();
        $isOwner = $user->id === $owner->user_id;
        $factor = $isOwner ? $owner : OwnerAccess::member($user->id)->first();
        if ($factor) {
            if (! $factor->secret || ! OwnerAccess::valid($request->session()->get('owner_proof'), $user, $factor)) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json(['error' => 'Meld je opnieuw aan met je authenticator.'], 401);
            }
        }
        if (! $isOwner && $user->rol === 'admin') {
            $user->rol = 'manager';
        }

        $request->session()->put('rol', $user->rol);
        $request->attributes->set('authenticatedUser', $user);

        return $next($request);
    }
}

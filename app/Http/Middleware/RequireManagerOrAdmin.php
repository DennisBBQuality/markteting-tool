<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class RequireManagerOrAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $userId = $request->session()->get('userId');

        if (! $userId) {
            return response()->json(['error' => 'Niet ingelogd'], 401);
        }

        $user = $request->attributes->get('authenticatedUser')
            ?? User::select('id', 'actief', 'rol')->find($userId);

        if (! $user || ! $user->actief) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['error' => 'Niet ingelogd'], 401);
        }

        $request->session()->put('rol', $user->rol);

        if (! in_array($user->rol, ['admin', 'manager'], true)) {
            return response()->json(['error' => 'Geen toegang'], 403);
        }

        return $next($request);
    }
}

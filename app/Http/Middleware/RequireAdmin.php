<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $userId = $request->session()->get('userId');

        if (! $userId) {
            return response()->json(['error' => 'Niet ingelogd'], 401);
        }

        $user = $request->attributes->get('authenticatedUser');

        if (! $user || ! $user->actief) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['error' => 'Niet ingelogd'], 401);
        }

        $request->session()->put('rol', $user->rol);

        if ($user->rol !== 'admin') {
            return response()->json(['error' => 'Geen toegang'], 403);
        }

        return $next($request);
    }
}

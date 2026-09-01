<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class RequireAuth
{
    public function handle(Request $request, Closure $next)
    {
        $userId = $request->session()->get('userId');

        if (! $userId) {
            return response()->json(['error' => 'Niet ingelogd'], 401);
        }

        $user = User::select('id', 'actief', 'rol')->find($userId);

        if (! $user || ! $user->actief) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['error' => 'Niet ingelogd'], 401);
        }

        $request->session()->put('rol', $user->rol);
        $request->attributes->set('authenticatedUser', $user);

        return $next($request);
    }
}

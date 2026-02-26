<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireManagerOrAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->session()->has('userId')) {
            return response()->json(['error' => 'Niet ingelogd'], 401);
        }
        $rol = $request->session()->get('rol');
        if ($rol !== 'admin' && $rol !== 'manager') {
            return response()->json(['error' => 'Geen toegang'], 403);
        }
        return $next($request);
    }
}

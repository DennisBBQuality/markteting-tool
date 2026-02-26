<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->session()->has('userId')) {
            return response()->json(['error' => 'Niet ingelogd'], 401);
        }
        if ($request->session()->get('rol') !== 'admin') {
            return response()->json(['error' => 'Geen toegang'], 403);
        }
        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->session()->has('userId')) {
            return response()->json(['error' => 'Niet ingelogd'], 401);
        }
        return $next($request);
    }
}

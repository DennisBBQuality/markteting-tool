<?php

namespace App\Http\Middleware;

use App\Services\Content\ContentStorage;
use Closure;
use Illuminate\Http\Request;

class EnsureContentStorage
{
    public function handle(Request $request, Closure $next)
    {
        if (! app(ContentStorage::class)->ready()) {
            return response()->json(['error' => 'De contentopslag is nog niet klaar. Een beheerder kan dit via Content → Kanalen controleren.'], 503);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerServiceEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('features.customer_service', false)) {
            return response()->json([
                'error' => 'Klantenservice is voorlopig uitgeschakeld.',
                'code' => 'customer_service_disabled',
            ], 404)->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}

<?php

use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireAuth;
use App\Http\Middleware\RequireManagerOrAdmin;
use BaconQrCode\Writer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PragmaRX\Google2FA\Google2FA;

if (! class_exists(Google2FA::class) || ! class_exists(Writer::class)) {
    require __DIR__.'/../resources/mfa/autoload.php';
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.custom' => RequireAuth::class,
            'admin' => RequireAdmin::class,
            'manager_or_admin' => RequireManagerOrAdmin::class,
        ]);

        // Resolve and validate our session user before per-user rate limiting.
        $middleware->prependToPriorityList(ThrottleRequests::class, RequireAuth::class);

        $middleware->validateCsrfTokens(except: [
            'api/trunkrs/scheduled-sync',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['wachtwoord', 'invitation', 'code']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );
    })->create();

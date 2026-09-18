<?php

namespace App\Providers;

use App\Services\AiCredentialStore;
use App\Services\FakeProductImageGenerator;
use App\Services\OpenAiProductImageGenerator;
use App\Services\ProductImageGenerator;
use App\Services\ProductImageRefiner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProductImageGenerator::class, function () {
            if ($this->app->make(AiCredentialStore::class)->openAiIsActive()) {
                return $this->app->make(OpenAiProductImageGenerator::class);
            }

            return match (config('services.product_images.driver')) {
                'fake' => new FakeProductImageGenerator,
                'openai' => $this->app->make(OpenAiProductImageGenerator::class),
                default => throw new InvalidArgumentException('Onbekende productafbeelding-driver.'),
            };
        });
        $this->app->bind(ProductImageRefiner::class, fn () => $this->app->make(ProductImageGenerator::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Numeric throttle middleware otherwise shares one IP bucket across all
        // routes: our custom auth does not populate Laravel's default guard.
        foreach ([
            'image-models' => 30,
            'image-model-refresh' => 3,
            'image-generation' => 3,
            'image-seo-generation' => 12,
            'image-refinement' => 6,
            'image-style-library' => 12,
        ] as $name => $attempts) {
            RateLimiter::for($name, function (Request $request) use ($attempts) {
                $user = $request->attributes->get('authenticatedUser');

                return Limit::perMinute($attempts)
                    ->by($user ? 'user:'.$user->id : 'ip:'.$request->ip())
                    ->response(function (Request $request, array $headers) {
                        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));

                        return response()->json([
                            'error' => "Deze fotofunctie is te vaak kort achter elkaar gebruikt. Probeer het over {$seconds} seconden opnieuw. Je invoer en bestaande foto's blijven bewaard.",
                            'retry_after' => $seconds,
                        ], 429, $headers);
                    });
            });
        }
    }
}

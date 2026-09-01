<?php

namespace App\Providers;

use App\Services\AiCredentialStore;
use App\Services\FakeProductImageGenerator;
use App\Services\OpenAiProductImageGenerator;
use App\Services\ProductImageGenerator;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

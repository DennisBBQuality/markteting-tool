<?php

namespace App\Providers;

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
            return match (config('services.product_images.driver')) {
                'fake' => new FakeProductImageGenerator,
                'openai' => new OpenAiProductImageGenerator,
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

<?php

namespace App\Providers;

use App\Domain\Metrics\MetricsConfig;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // A borda que lê config e injeta no domínio. As classes de
        // `app/Domain/Metrics/` nunca chamam `config()` — é o que as
        // mantém testáveis sem o container (NFR-101).
        $this->app->singleton(
            MetricsConfig::class,
            fn (): MetricsConfig => MetricsConfig::fromArray(config('clinical')),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

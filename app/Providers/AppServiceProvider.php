<?php

namespace App\Providers;

use App\Domain\Metrics\MetricsConfig;
use App\Domain\Presentation\MetricTranslator;
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

        // Mesma borda: o tradutor recebe as metas resolvidas, não chama
        // `config()`. Assim ele continua testável passando um array literal.
        $this->app->singleton(
            MetricTranslator::class,
            fn (): MetricTranslator => new MetricTranslator(config('clinical.targets')),
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

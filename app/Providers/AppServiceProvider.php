<?php

namespace App\Providers;

use App\Domain\Metrics\MetricsConfig;
use App\Domain\Patterns\PatternsConfig;
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

        // ⚠️ Limiares do motor de padrões (Spec 004, §D4). A construção VALIDA
        // que as dez regras têm todas as chaves que declaram exigir — então
        // config incompleta explode aqui, ao inicializar, e não no meio de uma
        // comparação onde `null >= 2.0` é `false` e a regra deixa de disparar
        // em silêncio. Falha silenciosa é o modo de falha que importa num motor
        // de detecção: as telas continuam funcionando e o relatório fica vazio,
        // parecendo boa notícia.
        $this->app->singleton(
            PatternsConfig::class,
            fn (): PatternsConfig => PatternsConfig::fromArray(config('patterns')),
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

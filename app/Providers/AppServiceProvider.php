<?php

namespace App\Providers;

use App\Domain\Ai\PayloadSanitizer;
use App\Domain\Metrics\MetricsConfig;
use App\Domain\Patterns\DaypartAggregator;
use App\Domain\Patterns\PatternEngine;
use App\Domain\Patterns\PatternsConfig;
use App\Domain\Patterns\ProseRenderer;
use App\Domain\Patterns\Rules;
use App\Domain\Presentation\LangProseRenderer;
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

        // ⚠️ Os limites dos períodos do dia vêm de `clinical.dayparts`, não de
        // `patterns`. A divisão é deliberada: clinical = o que as coisas
        // significam; patterns = quando uma regra dispara. Se "tarde" morasse no
        // arquivo de limiares, o dashboard e o motor poderiam discordar sobre o
        // que é tarde — e a agregação VALIDA que os quatro períodos cobrem as
        // 24 h sem sobreposição.
        $this->app->singleton(
            DaypartAggregator::class,
            fn (): DaypartAggregator => new DaypartAggregator(
                $this->app->make(MetricsConfig::class),
                config('clinical.dayparts'),
            ),
        );

        // ⚠️ A borda que resolve a colisão entre §D3 e NFR-401: a regra precisa
        // nascer com a prosa pronta (Artigo I) e não pode chamar `__()` (pureza).
        // A regra recebe a interface; quem lê `lang/` é esta implementação.
        $this->app->bind(ProseRenderer::class, LangProseRenderer::class);

        // ⚠️ Artigo VII — a ÚNICA porta de saída em direção a um provedor de
        // IA. A allowlist chega injetada; o domínio não chama `config()`.
        //
        // Registrado aqui, na fase 5, ANTES de existir qualquer provider —
        // se viesse depois, haveria uma janela em que o código chama a API
        // sem ele, e é nessa janela que alguém testa com dado real.
        $this->app->singleton(
            PayloadSanitizer::class,
            fn (): PayloadSanitizer => new PayloadSanitizer(config('ai.payload_allowlist')),
        );

        // ⚠️ As dez regras registradas num lugar só. A ORDEM AQUI NÃO IMPORTA
        // para o usuário: o motor ordena por (severidade, rank), e o rank vem do
        // enum `RuleId`. Estão em ordem numérica por legibilidade, e há teste
        // provando que embaralhá-las não muda a saída.
        $this->app->singleton(
            PatternEngine::class,
            fn (): PatternEngine => new PatternEngine([
                $this->app->make(Rules\R1DaypartDrift::class),
                $this->app->make(Rules\R2HypoCluster::class),
                $this->app->make(Rules\R3Rollercoaster::class),
                $this->app->make(Rules\R4OutlierDay::class),
                $this->app->make(Rules\R5SensorGapLoopImpact::class),
                $this->app->make(Rules\R6CarbRatioCoherence::class),
                $this->app->make(Rules\R7SensorAdherence::class),
                $this->app->make(Rules\R8ReservoirChanges::class),
                $this->app->make(Rules\R9CalibrationBurden::class),
                $this->app->make(Rules\R10SensorQuality::class),
            ]),
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

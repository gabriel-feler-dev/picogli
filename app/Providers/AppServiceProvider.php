<?php

namespace App\Providers;

use App\Domain\Ai\Chat\ArgumentValidator;
use App\Domain\Ai\Chat\ChatOrchestrator;
use App\Domain\Ai\Chat\ChatPromptBuilder;
use App\Domain\Ai\Chat\ChatProvider;
use App\Domain\Ai\Chat\EmergencyClassifier;
use App\Domain\Ai\Chat\Persistence as Chat;
use App\Domain\Ai\Chat\ToolRegistry;
use App\Domain\Ai\CooldownStore;
use App\Domain\Ai\ModelChain;
use App\Domain\Ai\NarrativeGenerator;
use App\Domain\Ai\NumberGuard;
use App\Domain\Ai\PayloadSanitizer;
use App\Domain\Ai\PromptBuilder;
use App\Domain\Ai\Provider;
use App\Domain\Metrics\MetricsConfig;
use App\Domain\Patterns\DaypartAggregator;
use App\Domain\Patterns\PatternEngine;
use App\Domain\Patterns\PatternsConfig;
use App\Domain\Patterns\ProseRenderer;
use App\Domain\Patterns\Rules;
use App\Domain\Presentation\LangProseRenderer;
use App\Domain\Presentation\MetricTranslator;
use App\Infrastructure\Ai\CacheCooldownStore;
use App\Infrastructure\Ai\FileChatPromptBuilder;
use App\Infrastructure\Ai\FilePromptBuilder;
use App\Infrastructure\Ai\GeminiProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // ⚠️ O cooldown é PERSISTIDO: na hospedagem compartilhada a fila roda
        // por cron com `--stop-when-empty` (ADR-5), e o processo morre entre
        // execuções. Em memória, o sistema reaprenderia que o modelo está
        // esgotado a cada chamada — gastando uma requisição por vez para
        // descobrir o que já sabia.
        $this->app->bind(CooldownStore::class, CacheCooldownStore::class);

        // ⚠️ A cadeia percorre SEMPRE do melhor modelo, saltando os de
        // castigo. Não guarda "onde parou": um cursor que descesse e ficasse
        // embaixo continuaria usando o modelo mais fraco depois de o melhor
        // ter voltado — e ninguém notaria, porque o texto continuaria saindo.
        $this->app->singleton(
            ModelChain::class,
            fn (): ModelChain => new ModelChain(
                config('ai.model_chain'),
                config('ai.cooldown_seconds'),
                $this->app->make(CooldownStore::class),
            ),
        );

        // ⚠️ A guarda de número inventado (§D5). Tolerância e isenções
        // chegam injetadas — o domínio não chama `config()`.
        //
        // Ela só é aplicável porque o `fallbackProse` da fase 4 é
        // publicável: descartar a narrativa devolve a tela ao estado de
        // ontem, não a um estado pior.
        $this->app->singleton(
            NumberGuard::class,
            fn (): NumberGuard => new NumberGuard(
                (float) config('ai.number_guard.rounding_tolerance'),
                config('ai.number_guard.exempt_numbers'),
            ),
        );

        // ⚠️⚠️ Artigo VI, camada 4 (Spec 006, §D4) — a ÚNICA camada da fronteira
        // clínica que roda ANTES do modelo. As outras quatro são instrução,
        // prosa ou interface; esta é um `if`.
        //
        // Registrada aqui na PRIMEIRA tarefa da fase, antes de existir
        // orquestrador — mesma razão do `PayloadSanitizer` na fase 5: guarda
        // depois da porta deixa uma janela, e é na janela que se testa com
        // perguntas reais.
        //
        // ⚠️ As listas vêm de `config/tone.php` (configuração de guarda); o
        // TEXTO vem de `lang/` (prosa voltada ao usuário, e por isso varrida
        // pelo teste do Artigo IV). O domínio não chama `config()` nem `__()`.
        $this->app->singleton(
            EmergencyClassifier::class,
            fn (): EmergencyClassifier => new EmergencyClassifier(
                config('tone.emergency.risk_terms'),
                config('tone.emergency.presence_markers'),
                config('tone.emergency.standalone_terms'),
                (int) config('tone.emergency.critical_low'),
                (int) config('tone.emergency.critical_high'),
                (string) __('chat.emergency_guidance'),
            ),
        );

        // ⚠️ As listas dos Artigos IV e VI vêm de `config/tone.php` e são
        // INTERPOLADAS no prompt. Fonte única: o arquivo do prompt fica limpo
        // para a varredura de vocabulário, e a instrução que chega ao modelo é
        // exatamente a que o teste cobra. Com as listas duplicadas, uma palavra
        // acrescentada ao teste não chegaria ao modelo.
        $this->app->singleton(
            PromptBuilder::class,
            fn (): PromptBuilder => new FilePromptBuilder(
                resource_path(config('ai.narrative.prompt_path')),
                config('tone.forbidden_vocabulary'),
                config('tone.forbidden_conduct'),
            ),
        );

        // ⚠️ O prompt de chat, com as MESMAS listas interpoladas (FR-606) e o
        // catálogo de ferramentas renderizado dos descritores reais — escrever a
        // lista à mão no arquivo criaria a divergência mais cara possível: o
        // prompt anunciando ferramenta que não existe, ou omitindo uma que
        // existe, sem nada denunciar.
        $this->app->singleton(
            ChatPromptBuilder::class,
            fn (): ChatPromptBuilder => new FileChatPromptBuilder(
                resource_path(config('chat.prompt_path')),
                config('tone.forbidden_vocabulary'),
                config('tone.forbidden_conduct'),
            ),
        );

        // ⚠️ O ÚNICO lugar do projeto que fala com um provedor de IA. A chave
        // pode ser `null`: sem ela o provider classifica como `Unauthorized`,
        // a cadeia devolve `null` e a tela cai para o fallback (Artigo I).
        // Nada quebra por falta de chave — é a propriedade que T408 verifica.
        $this->app->singleton(
            Provider::class,
            fn (): Provider => new GeminiProvider(
                $this->app->make(HttpFactory::class),
                config('ai.gemini.api_key'),
                (int) config('ai.gemini.timeout_seconds'),
            ),
        );

        // O orquestrador: sanitiza (VII), monta o prompt, percorre a cadeia
        // (§D4) e confronta os números (§D5). Nunca lança — devolve um
        // `NarrativeAttempt` com a razão do descarte, e quem loga é a borda.
        $this->app->singleton(
            NarrativeGenerator::class,
            fn (): NarrativeGenerator => new NarrativeGenerator(
                $this->app->make(PayloadSanitizer::class),
                $this->app->make(PromptBuilder::class),
                $this->app->make(ModelChain::class),
                $this->app->make(Provider::class),
                $this->app->make(NumberGuard::class),
                (int) config('ai.narrative.max_words'),
            ),
        );

        /*
         * ⚠️ As dez ferramentas do chat (Spec 006, §9.3).
         *
         * O modelo recebe ESTE catálogo, não os dados — o Artigo III virando
         * arquitetura. E o `ToolRegistry` é o único caminho de execução: ele
         * valida argumento (§D2) e confere a saída contra `emittedKeys` (§D7).
         *
         * ⚠️ A ordem aqui não importa para o modelo; ele escolhe pela descrição.
         * Estão agrupadas como nas tarefas T503–T505 por legibilidade.
         */
        $this->app->singleton(
            ToolRegistry::class,
            fn (): ToolRegistry => new ToolRegistry([
                // T503 — métrica
                $this->app->make(Chat\PeriodMetricsTool::class),
                $this->app->make(Chat\HourlyProfileTool::class),
                $this->app->make(Chat\DailySeriesTool::class),
                $this->app->make(Chat\InsulinSummaryTool::class),
                // T504 — evento e refeição
                $this->app->make(Chat\EpisodesTool::class),
                $this->app->make(Chat\SensorGapsTool::class),
                $this->app->make(Chat\DeviceEventsTool::class),
                $this->app->make(Chat\MealsTool::class),
                // T505 — compostas
                $this->app->make(Chat\ComparePeriodsTool::class),
                $this->app->make(Chat\FindingsTool::class),
            ], new ArgumentValidator),
        );

        /*
         * ⚠️ A porta do Artigo VII para o chat (§D7) — MESMA classe, outra lista.
         *
         * A allowlist é a união dos `emittedKeys` das dez ferramentas, derivada
         * delas em vez de mantida à mão: uma lista paralela divergiria no
         * primeiro dia corrido, e a divergência é silenciosa.
         *
         * ⚠️ **Instância separada, e não uma lista somada à da narrativa.** Uma
         * allowlist maior protege MENOS: a narrativa passaria a poder emitir
         * `by_date` e `peak_2h` sem ninguém revisar.
         */
        $this->app->singleton(
            'ai.chat.sanitizer',
            fn (): PayloadSanitizer => new PayloadSanitizer(
                $this->app->make(ToolRegistry::class)->allowedKeys(),
            ),
        );

        // ⚠️ A evidência dos achados sai pela MESMA allowlist da fase 5. Uma
        // segunda lista, paralela, divergiria no primeiro achado novo — e o
        // Artigo VII passaria a ter duas respostas para "o que sai daqui?".
        $this->app->singleton(
            Chat\FindingsTool::class,
            fn (): Chat\FindingsTool => new Chat\FindingsTool(config('ai.payload_allowlist')),
        );

        /*
         * ⚠️ O laço (Spec 006, T510). Recebe TUDO montado: o classificador que
         * roda antes da rede, o registry que é o único caminho de execução de
         * ferramenta, a porta do Artigo VII com a allowlist do chat, a cadeia de
         * modelos da fase 5 e a guarda de número.
         *
         * ⚠️ **Nunca lança e nunca loga.** Devolve um `ChatTurn` com o desfecho;
         * quem registra é o controller.
         */
        $this->app->singleton(
            ChatOrchestrator::class,
            fn (): ChatOrchestrator => new ChatOrchestrator(
                $this->app->make(EmergencyClassifier::class),
                $this->app->make(ToolRegistry::class),
                $this->app->make(ChatPromptBuilder::class),
                $this->app->make('ai.chat.sanitizer'),
                $this->app->make(ModelChain::class),
                $this->app->make(ChatProvider::class),
                $this->app->make(NumberGuard::class),
                (int) config('chat.max_tool_iterations'),
            ),
        );

        // A MESMA classe que responde por `Provider` — um arquivo só conhece o
        // endpoint (Artigo VII), e há teste varrendo `app/` para provar.
        $this->app->bind(ChatProvider::class, fn (): ChatProvider => $this->app->make(Provider::class));

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
        /*
         * ⚠️ O rate limit do chat (§D12, §11.3) — independente do cooldown da
         * `ModelChain`, e os dois protegem coisas diferentes:
         *
         *   - o cooldown protege a COTA do provedor;
         *   - este protege o PRODUTO.
         *
         * Um laço acidental no front consumiria as 1.500 requisições do dia
         * antes de qualquer cooldown perceber que há algo errado.
         *
         * Dois limites, porque as duas falhas têm formas diferentes: rajada
         * (um laço) e maratona (uma sessão longa demais, que também é sinal de
         * que algo não está funcionando).
         */
        RateLimiter::for('chat', fn (Request $request): array => [
            Limit::perMinute((int) config('chat.rate_limit.messages_per_minute'))
                ->by((string) $request->user()?->id),
            Limit::perDay((int) config('chat.rate_limit.messages_per_day'))
                ->by((string) $request->user()?->id),
        ]);
    }
}

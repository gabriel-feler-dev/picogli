<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat;

use InvalidArgumentException;

/**
 * A camada 4 do Artigo VI (Spec 006, §D4, FR-604).
 *
 * ## Por que esta classe é a PRIMEIRA da fase
 *
 * ⚠️ É a mesma razão que fez o `PayloadSanitizer` ser a primeira da fase 5: **a
 * guarda antes da porta**. Um classificador escrito depois do orquestrador deixa
 * uma janela em que o chat responde a uma mensagem de crise — e é exatamente na
 * janela que alguém testa com perguntas reais.
 *
 * > *"Não dependa do modelo para segurança."* — `PicoGli.md` §9.4
 *
 * Depois do modelo, o comportamento em emergência seria **probabilístico**: bom
 * em 99 casos e péssimo no centésimo, sem nada denunciando qual foi qual. Antes
 * do modelo, é um `if`.
 *
 * ## O desenho: DUAS marcas, nunca uma
 *
 * ```
 * termo de risco   +   marca de presente   →   dispara
 * termo de risco       (sem presente)      →   não dispara
 * valor crítico    +   marca de presente   →   dispara
 * termo isolado de urgência                →   dispara (lista curta)
 * ```
 *
 * ⚠️ **O lado difícil não é o que ele tem de pegar — é o que ele não pode
 * acusar.** Metade das perguntas legítimas deste produto menciona hipoglicemia:
 * *"minhas hipos estão diminuindo?"*, *"qual foi minha pior hipo?"*, *"o que é
 * cetoacidose?"*. Um classificador que dispara com o termo sozinho responde
 * "procure um serviço médico" para todas elas, é desligado na primeira semana —
 * e uma camada 4 desligada não protege ninguém.
 *
 * Por isso o autoteste cobre os dois lados (T500.4 e T500.5), como o do
 * `NumberGuard`. Foi o lado "não pode acusar" que revelou o defeito de desenho
 * daquele — e é o lado que define este.
 *
 * ## PHP puro
 *
 * Listas, limiares e o texto de orientação chegam pelo construtor. A classe não
 * chama `config()` nem `__()` (NFR-401), o que a torna testável sem container —
 * e o teste de unidade é literalmente uma tabela de frases.
 */
final class EmergencyClassifier
{
    /**
     * Números seguidos por estas palavras não são glicose.
     *
     * Sem isto, *"sou diabético há 40 anos e estou preocupado"* dispararia: tem
     * marca de presente, tem um número abaixo do limiar crítico, e o `40` não
     * mede glicose nenhuma.
     */
    private const NON_GLUCOSE_UNITS = [
        'anos', 'ano', 'g', 'grama', 'gramas', 'dias', 'dia', 'kg', 'mg',
        'u', 'ui', 'unidade', 'unidades', 'min', 'minuto', 'minutos',
        'hora', 'horas', 'h', 'vez', 'vezes', '%',
    ];

    /**
     * Números precedidos por estas palavras também não são glicose.
     *
     * ⚠️ `dia` é a que importa: *"e agora, o dia 25 continua estranho?"* tem
     * marca de presente e um número abaixo de 54.
     *
     * ⚠️ Os verbos de comer e aplicar estão aqui porque *"comi 40 agora"* e
     * *"tomei 40 agora"* falam de carboidrato e de insulina — nunca de glicose.
     */
    private const NON_GLUCOSE_CONTEXT = [
        'dia', 'dias', 'ano', 'anos', 'às', 'as',
        'comi', 'comeu', 'tomei', 'apliquei', 'bolus', 'basal',
    ];

    /**
     * Um número só conta como glicose se vier depois de uma destas — ou com
     * `mg/dl` colado.
     *
     * ⚠️ **`de` e `em` ficaram DE FORA de propósito.** Com `de` na lista,
     * *"estou vendo uma média de 40 no gráfico"* dispararia — e é uma frase
     * absolutamente normal aqui. A exigência de pista é o que separa
     * *"estou com 40"* de *"média de 40"*.
     */
    /**
     * ⚠️ Fora da lista de marcas de presente por ser ambíguo — "neste instante"
     * e "a esta altura" — mas válido **colado a um valor crítico**.
     */
    private const NOW_ADVERB = 'agora';

    private const GLUCOSE_CUES = [
        'com', 'glicose', 'glicemia', 'sensor', 'deu', 'marcou', 'bateu',
        'está', 'esta', 'tá', 'ta', 'to', 'tô',
    ];

    /**
     * @param  list<string>  $riskTerms  sintomas e quadros, casados por prefixo
     * @param  list<string>  $presenceMarkers  o que situa o quadro em AGORA
     * @param  list<string>  $standaloneTerms  dispensam a segunda marca
     * @param  int  $criticalLow  abaixo disto, valor relatado é crítico
     * @param  int  $criticalHigh  daqui para cima, idem
     * @param  string  $guidance  a orientação fixa, de `lang/pt_BR/chat.php`
     */
    public function __construct(
        private readonly array $riskTerms,
        private readonly array $presenceMarkers,
        private readonly array $standaloneTerms,
        private readonly int $criticalLow,
        private readonly int $criticalHigh,
        private readonly string $guidance,
    ) {
        // ⚠️ Lista vazia faria a guarda passar tudo, calada. É o modo de falha
        // que importa numa camada de segurança: ela continua "funcionando".
        foreach ([
            'risk_terms' => $this->riskTerms,
            'presence_markers' => $this->presenceMarkers,
            'standalone_terms' => $this->standaloneTerms,
        ] as $nome => $lista) {
            if ($lista === []) {
                throw new InvalidArgumentException(
                    "A lista '{$nome}' do classificador de emergência está vazia. "
                    .'Confira config/tone.emergency — vazia, a guarda deixaria tudo passar.'
                );
            }
        }

        if ($this->criticalLow >= $this->criticalHigh) {
            throw new InvalidArgumentException(
                "Faixa crítica invertida: {$this->criticalLow} >= {$this->criticalHigh}."
            );
        }

        if (trim($this->guidance) === '') {
            throw new InvalidArgumentException(
                'A orientação de emergência está vazia. Ela é a resposta que substitui '
                .'a do modelo — sem texto, o disparo devolveria silêncio.'
            );
        }
    }

    /**
     * A mensagem descreve uma situação de risco acontecendo AGORA?
     *
     * `true` → o turno termina aqui, com `guidance()`, **sem tocar a rede**.
     */
    public function isEmergency(string $message): bool
    {
        $text = $this->normalise($message);

        if ($text === '') {
            return false;
        }

        // Exceção à regra das duas marcas: a urgência está na própria palavra.
        if ($this->matchesAny($text, $this->standaloneTerms)) {
            return true;
        }

        // ⚠️ Segunda exceção, e ela é estreita de propósito: um valor crítico
        // COLADO em "agora" carrega a própria marca de presente. "40 agora" é
        // relato; "quantas hipos tenho agora no total" é análise, e não tem
        // número crítico adjacente.
        //
        // *Por quê existe:* `agora` foi removido da lista de marcas justamente
        // por ser ambíguo em português — "neste instante" e "a esta altura". Sem
        // esta exceção, o relato terso de quem está em crise ficaria de fora.
        if ($this->mentionsCriticalGlucose($text, requireNowAdjacent: true)) {
            return true;
        }

        // ⚠️ O PORTÃO. Sem marca de presente, nada dispara — é esta linha que
        // permite conversar sobre hipoglicemia sem ser interrompido a cada
        // pergunta.
        if (! $this->matchesAny($text, $this->presenceMarkers)) {
            return false;
        }

        return $this->matchesAny($text, $this->riskTerms)
            || $this->mentionsCriticalGlucose($text);
    }

    /** A orientação fixa que substitui a resposta do modelo. */
    public function guidance(): string
    {
        return $this->guidance;
    }

    /**
     * Minúsculas e espaços colapsados — **e o acento preservado**.
     *
     * ⚠️ Remover acento parece uma boa ideia e não é: sem ele, o prefixo `hipo`
     * passaria a casar com `hipotese`, e "minha hipótese é que a tarde é pior"
     * viraria emergência. Quem escreve sem acento é atendido pelas entradas
     * duplicadas em `config/tone.emergency`.
     */
    private function normalise(string $message): string
    {
        $text = mb_strtolower(trim($message));

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * Algum termo aparece em início de palavra?
     *
     * ⚠️ Início de palavra, não `str_contains`: `hipo` casa com "hipoglicemia" e
     * com "hipo", mas não com "anti-hipo..." no meio de outra palavra. O
     * lookbehind por "não-letra" funciona onde `\b` falharia — os termos
     * acentuados (`vômit`, `açúcar`) começam com caractere que o `\b` do PCRE
     * não reconhece como fronteira.
     *
     * @param  list<string>  $terms
     */
    private function matchesAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            $needle = $this->normalise($term);

            if ($needle === '') {
                continue;
            }

            if (preg_match('/(?<!\p{L})'.preg_quote($needle, '/').'/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Há um valor de glicose em faixa crítica relatado no texto?
     *
     * Só entra número **solto** (ou com `mg/dl` colado): `25/07` e `18:06` não
     * são valores, e nunca chegam a ser avaliados.
     *
     * @param  bool  $requireNowAdjacent  exige `agora` logo depois do valor —
     *                                    é o que torna "40 agora" relato e
     *                                    "média de 40" análise
     */
    private function mentionsCriticalGlucose(string $text, bool $requireNowAdjacent = false): bool
    {
        $tokens = preg_split('/\s+/u', $text) ?: [];

        foreach ($tokens as $position => $raw) {
            $token = $this->strip($raw);

            if (preg_match('/^(\d{1,3})(mg\/dl)?$/u', $token, $matches) !== 1) {
                continue;
            }

            $value = (int) $matches[1];

            if ($value >= $this->criticalLow && $value < $this->criticalHigh) {
                continue;
            }

            $before = $this->strip($tokens[$position - 1] ?? '');
            $after = $this->strip($tokens[$position + 1] ?? '');

            if ($requireNowAdjacent && $after !== self::NOW_ADVERB) {
                continue;
            }

            if (in_array($after, self::NON_GLUCOSE_UNITS, true)) {
                continue;
            }

            if (in_array($before, self::NON_GLUCOSE_CONTEXT, true)) {
                continue;
            }

            // Sem `mg/dl` colado, o número precisa de uma pista antes dele.
            //
            // ⚠️ Exceto quando o `agora` adjacente já é a pista: "40 agora" não
            // tem palavra ANTES do número, e é justamente o relato terso de quem
            // não está em condição de escrever uma frase inteira.
            $needsCue = ($matches[2] ?? '') === '' && ! $requireNowAdjacent;

            if ($needsCue && ! in_array($before, self::GLUCOSE_CUES, true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** Tira a pontuação que gruda no token: `"40,"`, `"(38)"`, `"480!"`. */
    private function strip(string $token): string
    {
        return trim($token, " \t\n\r\0\x0B.,;:!?()[]\"'“”…");
    }
}

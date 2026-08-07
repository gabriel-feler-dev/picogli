<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use App\Domain\Import\Pdf\PdfAggregateReader;
use App\Domain\Import\Pdf\Value\PdfAggregate;
use App\Domain\Import\Pdf\Value\PdfMetric;
use DateTimeImmutable;
use Throwable;

/**
 * Lê agregados do TEXTO de um PDF (Spec 007, FR-705, §D5, §6.3).
 *
 * ## ⚠️ Só texto. Nunca pixel.
 *
 * > *"Não tente extrair valores numéricos dos gráficos por visão computacional ou
 * > modelo multimodal. Um modelo lendo pixels de uma curva de CGM chuta valores."*
 * > — `PicoGli.md` §6.3
 *
 * Esta classe abre o arquivo, descomprime os fluxos de conteúdo e lê os
 * operadores de texto. Não há imagem, não há OCR, não há rede. Há teste varrendo
 * este diretório para garantir.
 *
 * ## Como funciona, e por que é suficiente
 *
 * Um PDF gerado por programa — como os relatórios do CareLink — guarda o texto
 * como texto, em fluxos normalmente comprimidos com `FlateDecode`. O caminho é:
 *
 * ```
 * 1. acha os blocos `stream ... endstream`
 * 2. tenta `gzuncompress` em cada um (falha em silêncio se não for zlib)
 * 3. extrai os operandos de `Tj` e `TJ` — os operadores de mostrar texto
 * 4. casa rótulo com número, numa janela curta
 * ```
 *
 * ⚠️ **PDF escaneado devolve vazio, e está certo.** Nele o texto é imagem, e
 * imagem está fora do escopo por decisão, não por limitação.
 *
 * ## ⚠️ Os PADRÕES não foram verificados contra um PDF real
 *
 * Não havia PDF de amostra no projeto (07/08/2026). A **mecânica** está testada
 * com PDF sintético; os **rótulos** de `config/pdf.php` são hipótese até alguém
 * rodar contra um relatório de verdade. Ver o cabeçalho daquele arquivo.
 */
final class TextPdfReader implements PdfAggregateReader
{
    /**
     * @param  array<string, list<string>>  $labels  métrica → rótulos possíveis
     * @param  list<string>  $periodLabels
     */
    public function __construct(
        private readonly array $labels,
        private readonly array $periodLabels,
        private readonly int $valueWindowChars,
    ) {}

    /** @return list<PdfAggregate> */
    public function read(string $path): array
    {
        $texto = $this->textOf($path);

        if ($texto === '') {
            return [];
        }

        $periodo = $this->periodOf($texto);

        // ⚠️ Sem período, nada é gravável: o agregado não teria a que se referir.
        // Devolver vazio (e não explodir) é a mesma disciplina do CSV sem bloco.
        if ($periodo === null) {
            return [];
        }

        [$from, $to] = $periodo;
        $agregados = [];

        foreach ($this->labels as $metrica => $rotulos) {
            $enum = PdfMetric::tryFrom((string) $metrica);

            // Métrica desconhecida em config é ignorada, não inventada. A lista
            // do enum é fechada de propósito.
            if ($enum === null) {
                continue;
            }

            $valor = $this->valueNear($texto, $rotulos);

            if ($valor === null) {
                continue;
            }

            try {
                $agregados[] = new PdfAggregate(
                    metric: $enum,
                    value: $valor,
                    periodStart: $from,
                    periodEnd: $to,
                );
            } catch (Throwable) {
                // ⚠️ Valor implausível é DESCARTADO, não gravado. O construtor do
                // `PdfAggregate` recusa "tempo na faixa 1.483%" — extração torta
                // grava número que aparece na tela igual aos corretos.
                continue;
            }
        }

        return $agregados;
    }

    /**
     * O texto do PDF, concatenado.
     *
     * ⚠️ Erro de leitura devolve string vazia. Arquivo corrompido, criptografado
     * ou escaneado é caso previsto — e o chamador trata "nenhum agregado" de um
     * jeito só, independente do motivo.
     */
    private function textOf(string $path): string
    {
        $bruto = @file_get_contents($path);

        if ($bruto === false || $bruto === '') {
            return '';
        }

        $pedacos = [];

        // Os fluxos de conteúdo. `s` para o `.` cruzar linha; `U` para não
        // engolir vários `stream` num só casamento.
        preg_match_all('/stream\r?\n?(.*?)endstream/sU', $bruto, $fluxos);

        foreach ($fluxos[1] ?? [] as $fluxo) {
            $conteudo = @gzuncompress(trim($fluxo, "\r\n"));

            // Fluxo não comprimido, ou comprimido de outra forma: usa como está.
            $pedacos[] = is_string($conteudo) ? $conteudo : $fluxo;
        }

        // PDF sem fluxo reconhecível: tenta o arquivo inteiro. Relatório simples
        // às vezes guarda texto sem comprimir.
        if ($pedacos === []) {
            $pedacos[] = $bruto;
        }

        return $this->normalise($this->showTextOperands(implode("\n", $pedacos)));
    }

    /**
     * Os operandos dos operadores de mostrar texto: `(...) Tj` e `[...] TJ`.
     *
     * ⚠️ São os únicos lugares de um PDF em que texto é texto. Ler o fluxo cru
     * traria nome de fonte, matriz de transformação e coordenada — e um número de
     * coordenada casaria com rótulo por acidente.
     */
    private function showTextOperands(string $fluxo): string
    {
        $saida = [];

        // Cada literal entre parênteses, com escape de parêntese respeitado.
        preg_match_all('/\((?:\\\\.|[^\\\\()])*\)/s', $fluxo, $literais);

        foreach ($literais[0] ?? [] as $literal) {
            $texto = substr($literal, 1, -1);
            $texto = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $texto);

            $saida[] = $texto;
        }

        return implode(' ', $saida);
    }

    /** Minúsculas, espaço colapsado — para o casamento de rótulo ser estável. */
    private function normalise(string $texto): string
    {
        $texto = str_replace(["\r", "\n", "\t"], ' ', $texto);

        return $this->fold(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $texto))));
    }

    /**
     * Remove acento, mantendo **um caractere para cada caractere**.
     *
     * ⚠️ **Aqui remover acento é a decisão certa — e no classificador de emergência
     * era a errada.** A diferença é o tamanho da agulha:
     *
     * | | Agulha | Sem acento |
     * |---|---|---|
     * | `EmergencyClassifier` | prefixo curto (`hipo`) | passa a casar `hipotese` |
     * | aqui | rótulo de várias palavras (`media de glicose do sensor`) | nada colide |
     *
     * *Por quê é necessário:* um PDF codifica acento de formas que não se
     * controlam — octal escape, encoding próprio da fonte, ou byte cru. Exigir
     * `média` com acento faria o casamento depender de como o gerador do
     * relatório resolveu escrever, e o sintoma seria "não extraiu nada" sem pista
     * do motivo.
     *
     * ⚠️ **O mapa é 1:1 em caracteres**, de propósito: as posições de
     * `mb_strpos`/`mb_substr` continuam alinhadas com o texto original, e a janela
     * de busca do número não desloca.
     */
    private function fold(string $texto): string
    {
        return strtr($texto, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
    }

    /**
     * O intervalo de datas do relatório.
     *
     * Aceita `DD/MM/AAAA` e `AAAA-MM-DD`, os dois formatos que o CareLink usa em
     * lugares diferentes (armadilha A2 do CSV, e não há razão para o PDF ser
     * mais consistente).
     *
     * @return array{0: string, 1: string}|null
     */
    private function periodOf(string $texto): ?array
    {
        foreach ($this->periodLabels as $rotulo) {
            $posicao = mb_strpos($texto, $this->fold(mb_strtolower($rotulo)));

            if ($posicao === false) {
                continue;
            }

            $janela = mb_substr($texto, $posicao, 120);

            preg_match_all('#(\d{4}-\d{2}-\d{2}|\d{1,2}/\d{1,2}/\d{4})#', $janela, $datas);

            if (count($datas[1] ?? []) < 2) {
                continue;
            }

            $inicio = $this->toIsoDate($datas[1][0]);
            $fim = $this->toIsoDate($datas[1][1]);

            if ($inicio === null || $fim === null || $inicio > $fim) {
                continue;
            }

            return [$inicio, $fim];
        }

        return null;
    }

    private function toIsoDate(string $bruto): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bruto) === 1) {
            return $bruto;
        }

        [$dia, $mes, $ano] = array_map('intval', explode('/', $bruto));

        if (! checkdate($mes, $dia, $ano)) {
            return null;
        }

        return (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $ano, $mes, $dia)))->format('Y-m-d');
    }

    /**
     * O primeiro número depois de um dos rótulos, dentro da janela.
     *
     * ⚠️ **Janela curta** (`config('pdf.value_window_chars')`). Rótulo e valor
     * ficam próximos num relatório; janela larga casaria o rótulo de um bloco com
     * o número do bloco seguinte — e o resultado sairia plausível.
     *
     * @param  list<string>  $rotulos
     */
    private function valueNear(string $texto, array $rotulos): ?float
    {
        foreach ($rotulos as $rotulo) {
            // ⚠️ O rótulo da config passa pela MESMA dobra que o texto — senão
            // `média` nunca casaria com `media`.
            $posicao = mb_strpos($texto, $this->fold(mb_strtolower($rotulo)));

            if ($posicao === false) {
                continue;
            }

            $depois = mb_substr(
                $texto,
                $posicao + mb_strlen($rotulo),
                $this->valueWindowChars,
            );

            // ⚠️ Decimal com vírgula OU ponto: o CareLink usa vírgula no CSV
            // (armadilha A1) e o PDF segue o locale da conta.
            if (preg_match('/-?\d+(?:[.,]\d+)?/', $depois, $achado) !== 1) {
                continue;
            }

            return (float) str_replace(',', '.', $achado[0]);
        }

        return null;
    }
}

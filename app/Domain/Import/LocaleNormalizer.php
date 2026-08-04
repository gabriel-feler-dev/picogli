<?php

declare(strict_types=1);

namespace App\Domain\Import;

use DateTimeImmutable;

/**
 * Normalização de células do CSV do CareLink.
 *
 * Esta classe existe isolada de propósito.
 *
 * PicoGli.md lista "decimal com vírgula mal parseado" como risco nº 1 do
 * projeto, e por um motivo específico: ele é crítico (erra dose de insulina)
 * E silencioso (não lança exceção, apenas produz um número errado que parece
 * plausível). Praticamente todo outro bug do importador quebra de forma
 * visível; este não.
 *
 * A mitigação é concentrar toda conversão de texto→valor num único ponto
 * exaustivamente testado, e nunca fazer `(float) $cell` espalhado pelo código.
 *
 * Referências: PicoGli.md §3.4 — armadilhas A1 (decimal/delimitador),
 * A2 (dois formatos de data) e A7 (unidade não garantida).
 */
final class LocaleNormalizer
{
    /**
     * Formato de data das LINHAS DE DADOS (blocos Pump/Auto/Sensor).
     * Ex.: "2026/07/29" + "17:00:00"
     */
    private const ROW_DATE_FORMAT = 'Y/m/d H:i:s';

    /**
     * Formato de data do CABEÇALHO (linhas 1-3 do arquivo).
     * Ex.: "16/07/26 00:00:00" — ano de 2 dígitos, dia primeiro.
     *
     * ⚠️ A2: é DIFERENTE do formato das linhas de dados. Nunca reutilize
     * um parser para o outro — "16/07/26" lido como Y/m/d daria ano 16.
     */
    private const HEADER_DATE_FORMAT = 'd/m/y H:i:s';

    /**
     * Limpa uma célula bruta.
     *
     * O CareLink emite valores às vezes entre aspas duplas ("Feler") e às
     * vezes sem (NG3670115H), na mesma linha. Normalizamos ambos.
     *
     * Célula vazia retorna null, NUNCA string vazia ou zero. Essa distinção
     * é essencial: em `Bolus Volume Delivered`, vazio significa "esta linha
     * não é uma entrega de bolus" — tratar como 0 criaria milhares de doses
     * fantasma de zero unidade.
     */
    public function cell(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = $this->stripBom($raw);
        $value = trim($value);

        // Remove aspas envolventes, se houver, e re-trima o conteúdo.
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = trim(substr($value, 1, -1));
        }

        return $value === '' ? null : $value;
    }

    /**
     * Remove o BOM UTF-8 (`EF BB BF`) do início de uma string.
     *
     * O export do CareLink É gravado com BOM. Sem remover, a primeira chave do
     * cabeçalho vira `"\u{FEFF}Last Name"` em vez de `"Last Name"` — o campo
     * não é reconhecido e o **nome do paciente escapa do agrupamento de PII**,
     * caindo em `unknownKeys`. Ou seja: um caractere invisível de 3 bytes
     * furava o Artigo VII.
     *
     * Descoberto pelo teste contra o arquivo real (Artigo XI). Uma análise
     * prévia em Python não pegou porque `encoding="utf-8-sig"` remove BOM
     * silenciosamente — é o tipo de bug que só aparece quando o código de
     * produção encara o arquivo de verdade.
     *
     * Aplicado em `cell()` (defesa em profundidade, pega qualquer chamador) e
     * disponível separadamente para o leitor usar no nível de linha.
     */
    public function stripBom(string $raw): string
    {
        return str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;
    }

    /**
     * Converte uma célula numérica no locale do arquivo para float.
     *
     * ⚠️ A1 — o arquivo usa `;` como delimitador e `,` como separador
     * decimal. "8,0" são oito unidades de insulina. Um parser que assuma
     * ponto decimal lê isso errado sem reclamar.
     *
     * Estratégia:
     *   - só vírgula        → vírgula é decimal:      "8,0"     → 8.0
     *   - vírgula E ponto   → ponto é milhar (pt):    "1.234,5" → 1234.5
     *   - só ponto          → ponto é decimal:        "8.0"     → 8.0
     *                         (tolerância para exports em locale en)
     *
     * Valores não numéricos retornam null, não 0. O CSV mistura texto e
     * número nas mesmas colunas em blocos diferentes — por exemplo
     * `Scroll Step Size` traz "STEP_0_POINT_025". Devolver 0 ali
     * silenciaria o problema; null torna explícito que não há número.
     */
    public function number(?string $raw): ?float
    {
        $value = $this->cell($raw);

        if ($value === null) {
            return null;
        }

        $hasComma = str_contains($value, ',');
        $hasDot = str_contains($value, '.');

        if ($hasComma && $hasDot) {
            // Locale pt: ponto agrupa milhar, vírgula é decimal.
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif ($hasComma) {
            $value = str_replace(',', '.', $value);
        }

        // Validação estrita DEPOIS da normalização. Sem isso, "STEP_0_POINT_025"
        // passaria por (float) e viraria 0.0 — exatamente o bug silencioso
        // que esta classe existe para impedir.
        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Converte célula numérica para int, quando a semântica é inteira
     * (glicose em mg/dL, minutos, contadores).
     *
     * Passa por number() de propósito: a mesma normalização de locale se
     * aplica, e o arredondamento fica explícito num só lugar.
     */
    public function integer(?string $raw): ?int
    {
        $number = $this->number($raw);

        return $number === null ? null : (int) round($number);
    }

    /**
     * Monta o timestamp local de uma linha de dados a partir das colunas
     * separadas `Date` e `Time`.
     *
     * ⚠️ A5 — o valor retornado é HORA LOCAL DE PAREDE DO DISPOSITIVO. O
     * arquivo não carrega fuso nem offset. Converter para UTC exige o fuso
     * informado na importação; isso é responsabilidade de quem chama, não
     * desta classe.
     */
    public function rowDateTime(?string $date, ?string $time): ?DateTimeImmutable
    {
        $date = $this->cell($date);
        $time = $this->cell($time);

        if ($date === null || $time === null) {
            return null;
        }

        return $this->exact(self::ROW_DATE_FORMAT, "{$date} {$time}");
    }

    /**
     * Converte uma data do cabeçalho do arquivo (linhas 1-3).
     *
     * Aceita com ou sem a parte de hora: "16/07/26 00:00:00" e "16/07/26".
     */
    public function headerDateTime(?string $raw): ?DateTimeImmutable
    {
        $value = $this->cell($raw);

        if ($value === null) {
            return null;
        }

        if (! str_contains($value, ' ')) {
            $value .= ' 00:00:00';
        }

        return $this->exact(self::HEADER_DATE_FORMAT, $value);
    }

    /**
     * Parse estrito: só aceita a data se ela reproduz exatamente a string
     * de entrada. `createFromFormat` é permissivo por padrão — aceita
     * "2026/13/45" e rola para o mês seguinte. Aqui uma data inválida
     * retorna null e a linha vai para `parse_warnings`, em vez de virar
     * um timestamp errado que ninguém percebe.
     */
    private function exact(string $format, string $value): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat($format, $value);

        if ($parsed === false) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $parsed->format($format) === $value ? $parsed : null;
    }
}

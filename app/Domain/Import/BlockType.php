<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Os blocos que compõem um export CSV do CareLink.
 *
 * O arquivo tem TRÊS blocos, não dois — e o do meio é fácil de perder
 * porque tem ~14 linhas espremidas entre dois blocos de centenas/milhares.
 * Ignorá-lo subestima a insulina total em ~60% num usuário de 780G com
 * SmartGuard ativo.
 *
 * Os três compartilham o MESMO cabeçalho de 54 colunas, mas cada um
 * preenche um subconjunto diferente — logo a semântica de uma linha
 * depende de em qual bloco ela está. Daí este enum existir: o
 * EventExploder despacha por ele.
 *
 * Referência: PicoGli.md §3.2 e §3.5.
 */
enum BlockType: string
{
    /**
     * Eventos da bomba: bolus, refeições (BWZ), basal manual, glicemia
     * capilar, alertas, trocas de reservatório, suspensões.
     * Separador no arquivo: `-------;<modelo>;Pump;<serial>;-------`
     */
    case Pump = 'pump';

    /**
     * Total diário de insulina entregue automaticamente pelo SmartGuard.
     * Uma linha por dia, sempre com Time=00:00:00 e
     * Bolus Source=CLOSED_LOOP_AUTO_INSULIN.
     * Separador: `-------;<modelo>;Pump;<serial>;Aggregated Auto Insulin Data`
     */
    case AutoInsulin = 'auto_insulin';

    /**
     * Leituras de CGM a cada 5 minutos, mais ISIG e estado do sensor.
     * É o volume do arquivo e a base de todas as métricas de glicose.
     * Separador: `-------;<modelo>;Sensor;<serial>;-------`
     */
    case Sensor = 'sensor';

    /**
     * Reconhece o bloco a partir da linha separadora.
     *
     * O separador tem a forma:
     *   -------;MiniMed 780G MMT-1886;Pump;NG3670115H;-------
     *   -------;MiniMed 780G MMT-1886;Pump;NG3670115H;Aggregated Auto Insulin Data
     *   -------;MiniMed 780G MMT-1886;Sensor;NG3670115H;-------
     *
     * Note que o campo 3 é "Pump" nos DOIS primeiros casos — o que os
     * distingue é o campo 5. Por isso a checagem do rótulo agregado vem
     * primeiro; inverter a ordem faria o bloco de insulina automática ser
     * classificado como Pump e seus totais diários entrariam como bolus
     * comuns, inflando a insulina do dia.
     *
     * Retorna null para separador desconhecido — o chamador registra em
     * parse_warnings e segue, em vez de abortar o import inteiro.
     */
    public static function fromSeparator(string $line): ?self
    {
        $fields = array_map('trim', explode(';', $line));

        $kind = $fields[2] ?? '';
        $label = $fields[4] ?? '';

        if (str_contains($label, 'Aggregated Auto Insulin')) {
            return self::AutoInsulin;
        }

        return match ($kind) {
            'Pump' => self::Pump,
            'Sensor' => self::Sensor,
            default => null,
        };
    }
}

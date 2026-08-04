<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Value;

use DateTimeImmutable;

/**
 * A série do sensor — ponto de entrada de TODA calculadora de métrica.
 *
 * ⚠️ Construída apenas de `sensor_readings`. Glicemia capilar tem tabela
 * própria e não entra aqui: a frequência é irregular e o propósito é outro, e
 * misturá-la distorce TIR, GMI e CV de forma silenciosa.
 *
 * As calculadoras recebem esta classe, nunca uma query ou um model. É o que
 * mantém o domínio testável com cinco leituras escritas à mão, e o que impede
 * alguém de escrever `SensorReading::where(...)` dentro de um cálculo — o erro
 * que dissolveria a separação.
 */
final readonly class GlucoseSeries
{
    /** @param list<GlucoseReading> $readings ordenadas cronologicamente */
    private function __construct(public array $readings) {}

    /**
     * Ordena na construção, sempre.
     *
     * Confiar na ordem de quem chama é convite a bug: um `orderBy` esquecido
     * numa query faria o detector de lacunas achar intervalos negativos e o de
     * episódios abrir e fechar fora de hora — sem erro nenhum, só números
     * errados.
     *
     * @param  iterable<GlucoseReading>  $readings
     */
    public static function of(iterable $readings): self
    {
        $list = is_array($readings) ? array_values($readings) : iterator_to_array($readings, false);

        usort($list, fn (GlucoseReading $a, GlucoseReading $b): int => $a->at <=> $b->at);

        return new self($list);
    }

    /** @param list<array{0: string, 1: int}> $pairs [timestamp local, mg/dL] */
    public static function fromPairs(array $pairs): self
    {
        return self::of(array_map(
            fn (array $p): GlucoseReading => new GlucoseReading(new DateTimeImmutable($p[0]), $p[1]),
            $pairs,
        ));
    }

    public function count(): int
    {
        return count($this->readings);
    }

    public function isEmpty(): bool
    {
        return $this->readings === [];
    }

    public function first(): ?GlucoseReading
    {
        return $this->readings[0] ?? null;
    }

    public function last(): ?GlucoseReading
    {
        return $this->readings[array_key_last($this->readings)] ?? null;
    }

    /**
     * Intervalo entre a primeira e a última leitura, em dias fracionários.
     *
     * ⚠️ É o SPAN, não dias de calendário (spec.md §D2). Usar calendário
     * puniria um export que começa às 18h do primeiro dia, mostrando cobertura
     * baixa onde o sensor funcionou perfeitamente.
     */
    public function spanInDays(): float
    {
        if ($this->count() < 2) {
            return 0.0;
        }

        $seconds = $this->last()->at->getTimestamp() - $this->first()->at->getTimestamp();

        return $seconds / 86400;
    }

    /** @return list<int> */
    public function values(): array
    {
        return array_map(fn (GlucoseReading $r): int => $r->mgdl, $this->readings);
    }
}

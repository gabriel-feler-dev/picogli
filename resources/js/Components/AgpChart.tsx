import {
    Area,
    ComposedChart,
    Line,
    ReferenceArea,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import type { HourlyPercentilePayload, PeriodSummaryPayload } from '@/types';

interface Props {
    percentiles: HourlyPercentilePayload[];
    ranges: PeriodSummaryPayload['ranges'];
}

/**
 * Perfil diário sobreposto (AGP) — FR-205.
 *
 * ⚠️ Os percentis chegam CALCULADOS do servidor (FR-202). Este componente não
 * calcula estatística nenhuma: o único cálculo aqui é `p95 - p75` para empilhar
 * as áreas do Recharts, que é geometria de desenho, não medida clínica.
 *
 * ⚠️ D4 — duas regras que fazem o gráfico não mentir:
 *
 * 1. **Lacuna é descontinuidade.** Hora sem leitura vem com percentis `null`, e
 *    `connectNulls` fica FALSO. Ligar os pontos por cima do vazio inventaria
 *    medição — e visualmente convence.
 * 2. **Eixo Y nunca truncado.** Começa em 0. Truncar exagera a variação e
 *    assusta sem motivo.
 *
 * A banda-alvo é desenhada a partir de `ranges`, que vem do servidor. Assim
 * `70` e `180` não aparecem como constante em JS.
 */
export function AgpChart({ percentiles, ranges }: Props) {
    const target = ranges.target;

    // Empilhamento das áreas: o Recharts soma as séries de um mesmo `stackId`,
    // então as bandas superiores viram deltas. Geometria, não estatística.
    const data = percentiles.map((bucket) => ({
        hour: bucket.hour,
        p5: bucket.p5,
        band25: bucket.p5 === null || bucket.p25 === null ? null : bucket.p25 - bucket.p5,
        band75: bucket.p25 === null || bucket.p75 === null ? null : bucket.p75 - bucket.p25,
        band95: bucket.p75 === null || bucket.p95 === null ? null : bucket.p95 - bucket.p75,
        p50: bucket.p50,
        count: bucket.count,
    }));

    return (
        <figure aria-label="Perfil de um dia típico, sobrepondo todos os dias do período">
            <ResponsiveContainer width="100%" height={260}>
                <ComposedChart data={data} margin={{ top: 8, right: 8, bottom: 4, left: 0 }}>
                    {/* Faixa-alvo sombreada: a referência de leitura do gráfico. */}
                    <ReferenceArea
                        y1={target.min ?? 0}
                        y2={target.max ?? 0}
                        fill="#10b981"
                        fillOpacity={0.08}
                        ifOverflow="extendDomain"
                    />

                    <XAxis
                        dataKey="hour"
                        tickFormatter={(hour: number) => `${String(hour).padStart(2, '0')}h`}
                        interval={2}
                        tick={{ fontSize: 11 }}
                        stroke="currentColor"
                        className="text-slate-400"
                    />

                    {/* ⚠️ Começa em 0. Nunca truncado (D4). */}
                    <YAxis
                        domain={[0, 'dataMax + 20']}
                        tick={{ fontSize: 11 }}
                        stroke="currentColor"
                        className="text-slate-400"
                        label={{ value: 'mg/dL', angle: -90, position: 'insideLeft', fontSize: 11 }}
                    />

                    <Tooltip
                        contentStyle={{ fontSize: 12, borderRadius: 8 }}
                        labelFormatter={(hour) => `${String(hour).padStart(2, '0')}h`}
                        formatter={(value, name) => [value, name === 'p50' ? 'mediana' : name]}
                    />

                    {/* connectNulls ausente = false: a lacuna aparece como lacuna. */}
                    <Area dataKey="p5" stackId="agp" stroke="none" fill="transparent" isAnimationActive={false} />
                    <Area dataKey="band25" stackId="agp" stroke="none" fill="#38bdf8" fillOpacity={0.15} isAnimationActive={false} />
                    <Area dataKey="band75" stackId="agp" stroke="none" fill="#38bdf8" fillOpacity={0.3} isAnimationActive={false} />
                    <Area dataKey="band95" stackId="agp" stroke="none" fill="#38bdf8" fillOpacity={0.15} isAnimationActive={false} />

                    <Line
                        dataKey="p50"
                        stroke="#0284c7"
                        strokeWidth={2}
                        dot={false}
                        isAnimationActive={false}
                    />
                </ComposedChart>
            </ResponsiveContainer>

            <figcaption className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                A linha é a mediana de cada hora. As faixas mostram onde ficaram 50% e 90% das
                leituras. A área verde é a faixa-alvo. Buracos no gráfico são horas em que o sensor
                não estava medindo.
            </figcaption>
        </figure>
    );
}

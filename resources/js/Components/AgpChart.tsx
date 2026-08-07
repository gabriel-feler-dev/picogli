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
            {/* ⚠️ Altura por CSS, não fixa em pixel (Spec 008 §D7): 224 px no
                celular, 288 no desktop. Uma altura única deixaria o gráfico
                espremido numa ponta e esticado na outra. */}
            <div className="h-56 w-full sm:h-72">
            <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={data} margin={{ top: 8, right: 8, bottom: 4, left: 0 }}>
                    {/* §V6 — gradiente vertical nas bandas: densidade visual maior
                        embaixo, onde a mediana passa. */}
                    <defs>
                        <linearGradient id="agp-banda" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#8f86d6" stopOpacity={0.45} />
                            <stop offset="100%" stopColor="#8f86d6" stopOpacity={0.12} />
                        </linearGradient>
                    </defs>

                    {/* Faixa-alvo sombreada: a referência de leitura do gráfico. */}
                    <ReferenceArea
                        y1={target.min ?? 0}
                        y2={target.max ?? 0}
                        fill="#10b981"
                        fillOpacity={0.08}
                        ifOverflow="extendDomain"
                    />

                    {/* ⚠️ `minTickGap` em vez de `interval` fixo (§D7): com
                        `interval={2}` os rótulos ficavam colados em 375 px e
                        sobravam no desktop. Assim o Recharts remove rótulo
                        conforme a largura real — e **nenhum dado some**, só o
                        rótulo do eixo. */}
                    <XAxis
                        dataKey="hour"
                        tickFormatter={(hour: number) => `${String(hour).padStart(2, '0')}h`}
                        interval="preserveStartEnd"
                        minTickGap={28}
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

                    {/* connectNulls ausente = false: a lacuna aparece como lacuna.
                        ⚠️ Bandas e mediana em ROXO da marca (§D2). Antes eram azul
                        `#38bdf8`/`#0284c7` — funcionava, mas deixava duas cores
                        disputando "isto é normal" com o verde da faixa-alvo. Agora
                        o ÚNICO verde do gráfico é o `#10b981` da `ReferenceArea`,
                        que é o vocabulário clínico. */}
                    <Area dataKey="p5" stackId="agp" stroke="none" fill="transparent" isAnimationActive={false} />
                    <Area dataKey="band25" stackId="agp" stroke="none" fill="url(#agp-banda)" fillOpacity={0.5} isAnimationActive={false} />
                    <Area dataKey="band75" stackId="agp" stroke="none" fill="url(#agp-banda)" isAnimationActive={false} />
                    <Area dataKey="band95" stackId="agp" stroke="none" fill="url(#agp-banda)" fillOpacity={0.5} isAnimationActive={false} />

                    {/* ⚠️ §V11 — a LINHA anima, o NÚMERO não.
                        O traçado se desenhando mostra a forma aparecendo, e a forma
                        é a mesma no fim: é orientação. Um contador subindo até
                        "83,9%" encenaria uma conquista que o valor não tem — e
                        obrigaria o produto a ter encenação para 61%.
                        Anime o continente, nunca o conteúdo numérico. */}
                    <Line
                        dataKey="p50"
                        stroke="#443c9b"
                        strokeWidth={2.5}
                        strokeLinecap="round"
                        dot={false}
                        isAnimationActive={true}
                        animationDuration={600}
                        animationEasing="ease-out"
                    />
                </ComposedChart>
            </ResponsiveContainer>
            </div>

            <figcaption className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                A linha é a mediana de cada hora. As faixas mostram onde ficaram 50% e 90% das
                leituras. A área verde é a faixa-alvo. Buracos no gráfico são horas em que o sensor
                não estava medindo.
            </figcaption>
        </figure>
    );
}

import type { DailyMetricPayload } from '@/types';

/**
 * Grade de dias — FR-206.
 *
 * ⚠️ Duas informações por célula, e as duas vêm do servidor:
 *
 * - `tir_status` decide o MATIZ (verde ou âmbar), derivado da meta em config.
 * - `low_coverage` marca o dia com hachura, porque 34% de captura não é
 *   comparável com 100% e a grade não pode fazer os dois parecerem iguais
 *   (Artigo V no nível do dia).
 *
 * A INTENSIDADE é proporcional ao TIR. Isso é escala visual, não classificação:
 * não há limiar inventado aqui, só o valor mapeado em opacidade.
 */
export function DayGrid({ days }: { days: DailyMetricPayload[] }) {
    return (
        <figure aria-label="Tempo na faixa em cada dia do período">
            <div className="grid grid-cols-7 gap-1.5" role="list">
                {days.map((day) => {
                    const hue = day.tir_status === 'met' ? '16, 185, 129' : '251, 191, 36';
                    // 0,25 a 1,0 — o dia com TIR baixo não desaparece.
                    const intensity = 0.25 + (day.tir_pct / 100) * 0.75;

                    const label = [
                        new Date(`${day.local_date}T12:00:00`).toLocaleDateString('pt-BR', {
                            day: '2-digit',
                            month: '2-digit',
                        }),
                        `${day.tir_pct}% na faixa`,
                        day.low_coverage ? `cobertura baixa (${day.coverage_pct}%)` : null,
                    ]
                        .filter((part) => part !== null)
                        .join(' · ');

                    return (
                        <div
                            key={day.local_date}
                            role="listitem"
                            aria-label={label}
                            title={label}
                            className="relative flex h-14 flex-col items-center justify-center rounded-md text-[11px] font-medium"
                            style={{ backgroundColor: `rgba(${hue}, ${intensity})` }}
                        >
                            {/* Cobertura baixa: hachura + asterisco. Nunca só cor. */}
                            {day.low_coverage && (
                                <span
                                    aria-hidden="true"
                                    className="absolute inset-0 rounded-md"
                                    style={{
                                        backgroundImage:
                                            'repeating-linear-gradient(45deg, rgba(255,255,255,.55) 0 2px, transparent 2px 6px)',
                                    }}
                                />
                            )}
                            <span className="relative tabular-nums text-slate-900">
                                {day.local_date.slice(8, 10)}
                            </span>
                            <span className="relative tabular-nums text-slate-800">
                                {Math.round(day.tir_pct)}%
                                {day.low_coverage && <span aria-hidden="true">*</span>}
                            </span>
                        </div>
                    );
                })}
            </div>

            <figcaption className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                Cada célula é um dia, com o tempo na faixa. Dias marcados com{' '}
                <span aria-hidden="true">*</span> e hachura tiveram cobertura de sensor abaixo do
                mínimo — os números deles são menos comparáveis.
            </figcaption>
        </figure>
    );
}

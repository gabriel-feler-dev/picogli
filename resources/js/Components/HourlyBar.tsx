import type { HourlyBucketPayload } from '@/types';

import { emptyCell, rangePalette } from './rangePalette';

/**
 * Barra de 24 horas — FR-206.
 *
 * ⚠️ A faixa de cada hora vem CLASSIFICADA do servidor (`dominant_range`). Este
 * componente só traduz faixa em cor. Se classificasse aqui, o dashboard poderia
 * discordar da fase 4 sobre o que é "hora alta".
 *
 * NFR-203 — cada célula tem `aria-label` e `title` com o rótulo textual da
 * faixa. Cor nunca é o único sinal.
 */
export function HourlyBar({ profile }: { profile: HourlyBucketPayload[] }) {
    return (
        <figure aria-label="Faixa predominante da glicose em cada hora do dia">
            <div className="flex gap-0.5" role="list">
                {profile.map((bucket) => {
                    const style = bucket.dominant_range === null
                        ? emptyCell
                        : rangePalette[bucket.dominant_range];

                    const label = bucket.count === 0
                        ? `${String(bucket.hour).padStart(2, '0')}h: sem leitura`
                        : `${String(bucket.hour).padStart(2, '0')}h: ${style.label}, média ${bucket.mean} mg/dL`;

                    return (
                        <div
                            key={bucket.hour}
                            role="listitem"
                            aria-label={label}
                            title={label}
                            className="h-8 flex-1 rounded-sm"
                            style={{ backgroundColor: style.fill, opacity: bucket.count === 0 ? 0.35 : 1 }}
                        />
                    );
                })}
            </div>

            <div className="mt-1 flex justify-between text-[10px] tabular-nums text-slate-400">
                <span>00h</span>
                <span>06h</span>
                <span>12h</span>
                <span>18h</span>
                <span>23h</span>
            </div>
        </figure>
    );
}

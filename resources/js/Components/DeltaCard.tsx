import type { ComparedMetricPayload } from '@/types';

/**
 * Uma métrica nos dois períodos, com a diferença (Spec 007, FR-704, §D4).
 *
 * ⚠️⚠️ **O aviso de cobertura baixa é PARTE DO NÚMERO, não rodapé.**
 *
 * "Melhorei 12% em relação ao mês passado" é a frase mais convincente que este
 * produto pode escrever, e a mais perigosa. Se um dos lados tem 6 dias e 61% de
 * captura, o 12% é ruído com aparência de conclusão — e ninguém pergunta pela
 * cobertura antes de acreditar. Por isso o aviso vive dentro do card, colado ao
 * delta, e não numa nota no pé da página que a pessoa rola sem ler.
 *
 * ⚠️ **`conclusive: false` NÃO esconde o número.** Esconder deixaria a tela vazia
 * sem explicar por quê. O número aparece com o motivo do lado.
 *
 * ⚠️ **E nada aqui calcula** (NFR-703). O delta e a decisão de "é conclusivo?"
 * vêm do servidor — escolher se uma diferença pode ser lida como tendência é
 * significado clínico, não layout.
 */
export function DeltaCard({ metric }: { metric: ComparedMetricPayload }) {
    const semDados = metric.value_a === null || metric.value_b === null;

    return (
        <article
            className={`rounded-lg border px-4 py-4 ${
                metric.conclusive
                    ? 'border-slate-200 dark:border-slate-800'
                    : 'border-amber-300/70 bg-amber-50/40 dark:border-amber-800/50 dark:bg-amber-950/10'
            }`}
        >
            <h3 className="text-sm font-medium text-slate-600 dark:text-slate-300">{metric.label}</h3>

            <div className="mt-2 flex items-baseline gap-2 tabular-nums">
                <span className="text-slate-500 dark:text-slate-400">
                    {metric.value_a !== null ? metric.value_a : '—'}
                </span>
                <span aria-hidden="true" className="text-slate-400 dark:text-slate-500">
                    →
                </span>
                <span className="text-xl font-semibold">
                    {metric.value_b !== null ? metric.value_b : '—'}
                </span>
                <span className="text-xs text-slate-500 dark:text-slate-400">{metric.unit}</span>
            </div>

            <p className="mt-1 text-sm tabular-nums">
                {metric.delta !== null ? (
                    <>
                        <span className="font-medium">
                            {metric.delta > 0 ? '+' : ''}
                            {metric.delta}
                        </span>{' '}
                        <span className="text-slate-500 dark:text-slate-400">
                            {metric.unit === '%' ? 'pontos' : metric.unit}
                        </span>
                    </>
                ) : (
                    <span className="text-xs text-slate-500 dark:text-slate-400">
                        {semDados
                            ? 'Um dos períodos não tem esse número apurado.'
                            : 'Sem diferença calculável.'}
                    </span>
                )}
            </p>

            {/* ⚠️ Colado ao número, de propósito — ver o bloco do componente. */}
            {!metric.conclusive && metric.inconclusive_reason !== null && (
                <p className="mt-2 border-t border-amber-200/70 pt-2 text-xs leading-relaxed text-amber-800 dark:border-amber-900/50 dark:text-amber-500">
                    ⚠️ {metric.inconclusive_reason}
                </p>
            )}
        </article>
    );
}

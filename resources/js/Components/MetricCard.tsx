import type { TranslatedMetricPayload } from '@/types';

/**
 * Um card de métrica.
 *
 * ⚠️ Recebe tudo resolvido: valor traduzido, valor técnico, meta e status. NÃO
 * formata número, NÃO compara com meta, NÃO escolhe cor a partir do valor.
 *
 * O mapa abaixo traduz STATUS em aparência — nunca valor em aparência. Se algum
 * dia aparecer aqui um `metric.value > 70 ? ... : ...`, a decisão clínica
 * escapou para o cliente.
 */
const statusStyles: Record<TranslatedMetricPayload['status'], { ring: string; text: string; label: string }> = {
    met: {
        ring: 'ring-emerald-500/30',
        text: 'text-emerald-700 dark:text-emerald-400',
        label: 'meta atingida',
    },
    not_met: {
        ring: 'ring-amber-500/30',
        text: 'text-amber-700 dark:text-amber-400',
        label: 'fora da meta',
    },
    unreliable: {
        ring: 'ring-slate-400/30',
        text: 'text-slate-600 dark:text-slate-400',
        label: 'estimativa pouco confiável',
    },
};

export function MetricCard({ metric }: { metric: TranslatedMetricPayload }) {
    const style = statusStyles[metric.status];

    return (
        <article
            className={`rounded-xl bg-white p-5 ring-1 ${style.ring} dark:bg-slate-900`}
            aria-label={`${metric.label}: ${metric.plain_value}, ${style.label}`}
        >
            <h2 className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                {metric.label}
            </h2>

            <p className="mt-2 text-2xl font-semibold tracking-tight tabular-nums">
                {metric.plain_value}
            </p>

            {/* Artigo III — o valor técnico acompanha sempre o traduzido. */}
            <p className="mt-1 text-sm tabular-nums text-slate-500 dark:text-slate-400">
                {metric.technical_value}
            </p>

            <p className={`mt-3 text-xs font-medium ${style.text}`}>
                {/* NFR-203 — a cor nunca é o único sinal: há rótulo textual. */}
                {style.label}
                {metric.target_label !== null && (
                    <span className="ml-1.5 font-normal text-slate-500 dark:text-slate-400">
                        {metric.target_label}
                    </span>
                )}
            </p>

            <p className="mt-3 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                {metric.explanation}
            </p>
        </article>
    );
}

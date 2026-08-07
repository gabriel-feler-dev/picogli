import type { TranslatedMetricPayload } from '@/types';

import { Explainer } from './ui/Explainer';

/**
 * Um card de métrica — Spec 008 §V3, §V4, §V12.
 *
 * ⚠️ Recebe tudo resolvido: valor traduzido, valor técnico, meta e status. NÃO
 * formata número, NÃO compara com meta, NÃO escolhe cor a partir do valor.
 *
 * O mapa abaixo traduz STATUS em aparência — nunca valor em aparência. Se algum
 * dia aparecer aqui um `metric.value > 70 ? ... : ...`, a decisão clínica
 * escapou para o cliente.
 *
 * ⚠️ **O número domina o card** (§V4). Antes, valor e título tinham quase o
 * mesmo peso e o olho não sabia onde pousar.
 *
 * ⚠️ **A explicação saiu do rodapé cinza e virou disclosure** (§V12). O texto é
 * o mesmo, do mesmo campo do payload — mudou o enquadramento.
 */
const statusStyles: Record<
    TranslatedMetricPayload['status'],
    { chip: string; dot: string; label: string }
> = {
    met: {
        chip: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300',
        dot: 'bg-emerald-500',
        label: 'meta atingida',
    },
    not_met: {
        chip: 'bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300',
        dot: 'bg-amber-500',
        label: 'fora da meta',
    },
    unreliable: {
        chip: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
        dot: 'bg-slate-400',
        label: 'estimativa pouco confiável',
    },
};

export function MetricCard({
    metric,
    size = 'md',
}: {
    metric: TranslatedMetricPayload;
    size?: 'md' | 'lg';
}) {
    const style = statusStyles[metric.status];

    return (
        <article
            className="flex h-full flex-col rounded-2xl border border-slate-200 bg-white p-5 transition-shadow duration-150 hover:shadow-sm dark:border-slate-800 dark:bg-slate-900"
            aria-label={`${metric.label}: ${metric.plain_value}, ${style.label}`}
        >
            <div className="flex items-start justify-between gap-3">
                <h2 className="text-xs font-medium tracking-wide text-slate-500 uppercase dark:text-slate-400">
                    {metric.label}
                </h2>

                {/* NFR-203 — a cor nunca é o único sinal: ponto E rótulo textual. */}
                <span
                    className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium ${style.chip}`}
                >
                    <span className={`size-1.5 rounded-full ${style.dot}`} aria-hidden="true" />
                    {style.label}
                </span>
            </div>

            {/* ⚠️ §D4 — valor e valor técnico no MESMO bloco. Separá-los abriria
                espaço para animá-los em tempos diferentes, e existiria um instante
                em que a tela mostra o número traduzido sem a procedência dele. */}
            <div className="mt-3">
                <p
                    className={`font-semibold tracking-tight tabular-nums ${
                        size === 'lg' ? 'text-4xl sm:text-5xl' : 'text-3xl'
                    }`}
                >
                    {metric.plain_value}
                </p>

                {/* Artigo III — o valor técnico acompanha sempre o traduzido. */}
                <p className="mt-1 text-sm tabular-nums text-slate-500 dark:text-slate-400">
                    {metric.technical_value}
                    {metric.target_label !== null && (
                        <span className="ml-2 text-slate-400 dark:text-slate-500">
                            · {metric.target_label}
                        </span>
                    )}
                </p>
            </div>

            <div className="mt-auto">
                <Explainer>{metric.explanation}</Explainer>
            </div>
        </article>
    );
}

import type { FindingSeverity } from '@/types';

/**
 * Selo de severidade.
 *
 * ⚠️ **A cor NUNCA é o único sinal** (NFR-203). Cada selo carrega o rótulo
 * textual vindo do servidor, um símbolo, e `aria-label`. Vermelho/âmbar/cinza é
 * exatamente a codificação que o daltonismo mais comum apaga.
 *
 * O mapa traduz SEVERIDADE em aparência — nunca valor em aparência. Se um dia
 * aparecer aqui um `finding.evidence.ratio > 5 ? ... : ...`, a decisão escapou
 * do servidor para o cliente (NFR-201/NFR-404).
 */
const styles: Record<FindingSeverity, { chip: string; mark: string }> = {
    priority: {
        chip: 'bg-rose-100 text-rose-900 dark:bg-rose-950 dark:text-rose-200',
        mark: '▲',
    },
    attention: {
        chip: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
        mark: '●',
    },
    info: {
        chip: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
        mark: '■',
    },
};

export function SeverityBadge({
    severity,
    label,
}: {
    severity: FindingSeverity;
    label: string;
}) {
    const style = styles[severity];

    return (
        <span
            className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${style.chip}`}
            aria-label={`Prioridade: ${label}`}
        >
            <span aria-hidden="true">{style.mark}</span>
            {label}
        </span>
    );
}

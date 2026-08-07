import type { ReactNode } from 'react';

/**
 * Estado vazio — Spec 008 §D8, T702.3 e T702.4.
 *
 * ⚠️⚠️ **Há DOIS vazios diferentes, e confundi-los é o defeito.**
 *
 *   `pending`  nunca importou nada        → convite, sem urgência
 *   `settled`  rodou e não achou nada     → **BOA NOTÍCIA**
 *
 * Confundi-los faria o app dizer "nada encontrado" para quem nunca importou, e
 * "importe algo" para quem está com tudo em ordem. A distinção nasceu em
 * `Evaluation.tsx` na fase 4 e aqui vira padrão do produto.
 *
 * ⚠️ **`settled` precisa SOAR como boa notícia.** A pressão de mostrar algo é o
 * que transforma produto de saúde em gerador de ansiedade: um achado de
 * enchimento é pior que nenhum achado.
 */
type Kind = 'pending' | 'settled' | 'blocked';

const KINDS: Record<Kind, string> = {
    pending: 'border-slate-200 dark:border-slate-800',
    settled:
        'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900/50 dark:bg-emerald-950/20',
    blocked: 'border-amber-300 bg-amber-50/60 dark:border-amber-900/50 dark:bg-amber-950/20',
};

const TITLE: Record<Kind, string> = {
    pending: '',
    settled: 'text-emerald-900 dark:text-emerald-200',
    blocked: 'text-amber-900 dark:text-amber-200',
};

interface Props {
    kind?: Kind;
    title: string;
    children?: ReactNode;
    action?: ReactNode;
}

export function EmptyState({ kind = 'pending', title, children, action }: Props) {
    return (
        <section className={`rounded-xl border p-6 ${KINDS[kind]}`}>
            <h2 className={`text-base font-semibold ${TITLE[kind]}`}>{title}</h2>

            {children !== undefined && (
                <div className="mt-2 text-sm text-slate-600 dark:text-slate-300">{children}</div>
            )}

            {action !== undefined && <div className="mt-4">{action}</div>}
        </section>
    );
}

import type { ReactNode } from 'react';

/**
 * Aviso — Spec 008 §D10, T702.2.
 *
 * ⚠️ **Cor nunca é o único sinal.** Todo aviso tem ícone e, quando há, título.
 * Daltonismo para vermelho/verde é comum — e vermelho/verde é exatamente a
 * codificação natural de glicemia. A regra vem do NFR-203, que até aqui vivia
 * só no `rangePalette.ts`.
 *
 * ⚠️ **Substitui o card âmbar copiado quatro vezes**: `Evaluation.tsx:66` e
 * `:122`, `Dashboard.tsx:53`, `Import.tsx:78`. Cada cópia tinha um tom de borda
 * ligeiramente diferente, e ninguém percebia porque estavam em telas distintas.
 */
type Tone = 'note' | 'caution' | 'good';

const TONES: Record<Tone, { box: string; icon: ReactNode }> = {
    note: {
        box: 'border-brand-100 bg-brand-50 text-brand-900 dark:border-brand-500/40 dark:bg-brand-500/10 dark:text-brand-100',
        icon: (
            <path d="M12 16v-4M12 8h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
        ),
    },
    caution: {
        box: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200',
        icon: (
            <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01" />
        ),
    },
    good: {
        box: 'border-emerald-200 bg-emerald-50/60 text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/20 dark:text-emerald-200',
        icon: <path d="M20 6 9 17l-5-5" />,
    },
};

interface Props {
    tone?: Tone;
    title?: string;
    children: ReactNode;
    className?: string;
}

export function Alert({ tone = 'caution', title, children, className }: Props) {
    const style = TONES[tone];

    return (
        <div
            role="status"
            className={`flex gap-3 rounded-xl border px-4 py-3 text-sm ${style.box} ${className ?? ''}`}
        >
            <svg
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
                className="mt-0.5 shrink-0"
            >
                {style.icon}
            </svg>

            <div className="min-w-0">
                {title !== undefined && <p className="font-medium">{title}</p>}
                <div className={title !== undefined ? 'mt-1' : undefined}>{children}</div>
            </div>
        </div>
    );
}

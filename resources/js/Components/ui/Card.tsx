import type { ReactNode } from 'react';

/** Superfície padrão. Uma borda, um raio, um respiro — para as seis telas. */
export function Card({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <div
            className={`rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900/40 ${className ?? ''}`}
        >
            {children}
        </div>
    );
}

/**
 * Seção com título e uma linha de contexto.
 *
 * O padrão "título + explicação em cinza + conteúdo" já se repetia em quatro
 * telas escrito à mão, com espaçamentos diferentes em cada uma.
 */
export function Section({
    title,
    hint,
    children,
    className,
}: {
    title: string;
    hint?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={className}>
            <h2 className="text-sm font-semibold">{title}</h2>
            {hint !== undefined && (
                <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">{hint}</p>
            )}
            {children}
        </section>
    );
}

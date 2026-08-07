import type { ReactNode } from 'react';

/**
 * "O que isso quer dizer?" — Spec 008 §V12, T715.
 *
 * ⚠️ **O texto já existia e estava escondido.** `metric.explanation` vem no
 * payload desde a fase 3 e era renderizado como cinza `text-xs` no rodapé do
 * card — o lugar onde ninguém lê. Zero mudança de payload aqui (§D1): só o
 * enquadramento muda.
 *
 * ⚠️ **`<details>`/`<summary>` NATIVOS, não estado em React.** Funcionam sem
 * JavaScript, entram na busca da página (Ctrl+F acha texto que está fechado), já
 * são anunciados por leitor de tela, e o navegador cuida do teclado.
 *
 * ⚠️ **Fechado por padrão, e isso é decisão.** Aberto, o card volta a ser um
 * parágrafo cinza e a hierarquia tipográfica do §V4 morre. Fechado, quem já sabe
 * lê o número e quem não sabe tem a porta visível.
 */
export function Explainer({
    children,
    label = 'o que isso quer dizer?',
}: {
    children: ReactNode;
    label?: string;
}) {
    return (
        <details className="group mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
            <summary className="flex cursor-pointer list-none items-center gap-1.5 text-xs font-medium text-brand-700 transition-colors duration-150 hover:text-brand-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 dark:text-brand-300 [&::-webkit-details-marker]:hidden">
                <svg
                    width="12"
                    height="12"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="3"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    aria-hidden="true"
                    className="transition-transform duration-150 group-open:rotate-90 motion-reduce:transition-none"
                >
                    <path d="M9 18l6-6-6-6" />
                </svg>
                {label}
            </summary>

            <div className="mt-2 text-xs leading-relaxed text-slate-600 dark:text-slate-400">
                {children}
            </div>
        </details>
    );
}

/**
 * Sigla com a forma longa na primeira aparição — §V13, T715.4.
 *
 * "GMI 6,70%" para quem não é médico é ruído com aparência de autoridade. O
 * `title` dá a forma longa no hover e o `<abbr>` a anuncia por leitor de tela.
 */
export function Sigla({ sigla, significado }: { sigla: string; significado: string }) {
    return (
        <abbr
            title={significado}
            className="cursor-help decoration-slate-300 decoration-dotted underline-offset-4 hover:underline dark:decoration-slate-600"
        >
            {sigla}
        </abbr>
    );
}

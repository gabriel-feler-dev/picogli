import { useState } from 'react';

import { SeverityBadge } from '@/Components/SeverityBadge';
import type { PresentedFindingPayload } from '@/types';

/**
 * Um achado do motor de padrões.
 *
 * ⚠️ Recebe tudo resolvido: título, prosa, rótulo de severidade e a evidência já
 * com rótulos legíveis e valores formatados. **Não decide nada** — nem ordem,
 * nem severidade, nem formato de número (NFR-404).
 *
 * ⚠️ **A evidência vem recolhida por padrão.** O Artigo III exige que todo número
 * rastreie até uma linha do banco e que isso seja VISÍVEL — não exige que a
 * tabela de números dispute atenção com a explicação. Quem quer conferir,
 * expande; e o botão está sempre lá, sem esconder que os dados existem.
 */
export function FindingCard({ finding }: { finding: PresentedFindingPayload }) {
    const [showEvidence, setShowEvidence] = useState(false);

    return (
        <article className="rounded-xl border border-slate-200 p-5 dark:border-slate-800">
            <header className="flex flex-wrap items-start justify-between gap-3">
                <h2 className="text-base font-semibold tracking-tight">{finding.title}</h2>
                <SeverityBadge severity={finding.severity} label={finding.severity_label} />
            </header>

            <p className="mt-3 text-sm leading-relaxed text-slate-700 dark:text-slate-300">
                {finding.prose}
            </p>

            {/* Artigo VI, camada 3 — R6 termina devolvendo a pergunta ao médico,
                e o selo torna isso visível antes mesmo de ler o texto. */}
            {finding.requires_clinical_handoff && (
                <p className="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-brand-50 px-3 py-1.5 text-xs font-medium text-brand-900 dark:bg-brand-500/15 dark:text-brand-100">
                    <span aria-hidden="true">✚</span>
                    Vale conversar com seu médico
                </p>
            )}

            <div className="mt-4">
                <button
                    type="button"
                    onClick={() => setShowEvidence(!showEvidence)}
                    aria-expanded={showEvidence}
                    className="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300"
                >
                    {showEvidence ? 'Ocultar os números' : 'Ver os números'}
                </button>

                {showEvidence && (
                    <div className="mt-3 overflow-x-auto">
                        <table className="w-full text-xs">
                            <tbody>
                                {finding.evidence.map((row) => (
                                    <tr
                                        key={row.key}
                                        className="border-b border-slate-100 last:border-0 dark:border-slate-800"
                                    >
                                        <th
                                            scope="row"
                                            className="py-1.5 pr-4 text-left font-normal text-slate-500 dark:text-slate-400"
                                        >
                                            {row.label}
                                        </th>
                                        <td className="py-1.5 text-right font-medium tabular-nums">
                                            {row.value}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                            Todo número acima vem de uma leitura registrada no seu export.
                        </p>
                    </div>
                )}
            </div>
        </article>
    );
}

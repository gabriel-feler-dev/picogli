import { useState } from 'react';

import type { ConsultedTool } from '@/types';

/**
 * O rodapé "dados consultados" (Spec 006, FR-608, §10.3).
 *
 * ⚠️⚠️ **Isto é o Artigo III virando recurso de interface, não promessa.** Todo
 * número da resposta veio de uma destas consultas — e o usuário pode conferir,
 * ferramenta por ferramenta, valor por valor.
 *
 * ⚠️ **É lido do que foi GRAVADO no turno, nunca remontado.** Executar as
 * ferramentas de novo mostraria o resultado de agora; o que torna o número
 * auditável é ver o que foi consultado **naquele momento**. Por isso o servidor
 * lê `chat_messages.tool_results` e este componente só formata.
 *
 * ⚠️ **Não calcula nada** (NFR-404). Formatar JSON é apresentação; qualquer
 * conta aqui seria o Artigo I sendo violado no lugar mais difícil de auditar.
 */
export function ToolTrace({ consulted }: { consulted: ConsultedTool[] }) {
    const [open, setOpen] = useState(false);

    if (consulted.length === 0) {
        return null;
    }

    return (
        <div className="mt-3 border-t border-slate-200 pt-2 dark:border-slate-700">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                className="flex items-center gap-1.5 text-xs text-slate-500 transition hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200"
            >
                <span aria-hidden="true" className={open ? 'rotate-90 transition' : 'transition'}>
                    ›
                </span>
                Dados consultados ({consulted.length})
            </button>

            {open && (
                <div className="mt-2 space-y-2">
                    <p className="text-xs text-slate-500 dark:text-slate-400">
                        Todo número da resposta veio destas consultas.
                    </p>

                    {consulted.map((tool, index) => (
                        <details
                            key={`${tool.name}-${index}`}
                            className="rounded border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900/50"
                        >
                            <summary className="cursor-pointer px-3 py-2 font-mono text-xs text-slate-700 dark:text-slate-300">
                                {tool.name}
                                {tool.error !== undefined && (
                                    <span className="ml-2 font-sans text-amber-700 dark:text-amber-500">
                                        — consulta recusada
                                    </span>
                                )}
                            </summary>

                            <div className="px-3 pb-3">
                                {tool.error !== undefined ? (
                                    <p className="text-xs text-slate-600 dark:text-slate-400">{tool.error}</p>
                                ) : (
                                    <pre className="overflow-x-auto text-[11px] leading-relaxed text-slate-600 tabular-nums dark:text-slate-400">
                                        {JSON.stringify(tool.result ?? {}, null, 2)}
                                    </pre>
                                )}
                            </div>
                        </details>
                    ))}
                </div>
            )}
        </div>
    );
}

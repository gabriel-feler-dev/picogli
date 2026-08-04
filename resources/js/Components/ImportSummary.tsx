import type { ImportSummaryPayload } from '@/types';

const statusStyles: Record<ImportSummaryPayload['status'], { chip: string; label: string }> = {
    pending: { chip: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300', label: 'Na fila' },
    processing: { chip: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300', label: 'Importando' },
    done: { chip: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300', label: 'Concluída' },
    failed: { chip: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300', label: 'Não concluída' },
};

/**
 * Resumo auditável de uma importação (FR-207).
 *
 * ⚠️ **Este componente existe para o usuário poder CONFERIR, não confiar.**
 *
 * Para cada bloco do arquivo ele mostra quantas linhas havia e o que cada uma
 * virou. No export de referência, o bloco Sensor tem 3.749 linhas que se
 * desdobram em 3.616 leituras + 77 eventos de sensor + 56 marcadores de dia — e
 * a soma aparece na tela.
 *
 * Sem isso, uma importação que perdesse 700 leituras diria "Concluída" e o erro
 * só apareceria semanas depois, numa métrica errada.
 *
 * Os números chegam prontos do `ImportSummaryPresenter` (NFR-201).
 */
export function ImportSummary({ summary }: { summary: ImportSummaryPayload }) {
    const status = statusStyles[summary.status];

    return (
        <article className="rounded-xl border border-slate-200 p-5 dark:border-slate-800">
            <header className="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h3 className="text-sm font-medium">{summary.filename}</h3>
                    <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {summary.imported_at}
                        {summary.device !== null && ` · ${summary.device}`}
                        {summary.firmware !== null && ` · firmware ${summary.firmware}`}
                    </p>
                    {summary.period.from !== null && (
                        <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            Período {summary.period.from} a {summary.period.to} · fuso{' '}
                            {summary.timezone} · {summary.glucose_unit}
                        </p>
                    )}
                </div>

                <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${status.chip}`}>
                    {status.label}
                </span>
            </header>

            {summary.blocks.some((block) => block.lines > 0) && (
                <div className="mt-4 space-y-3">
                    {summary.blocks
                        .filter((block) => block.lines > 0)
                        .map((block) => (
                            <div key={block.key} className="text-xs">
                                <div className="flex items-baseline justify-between">
                                    <span className="font-medium">{block.label}</span>
                                    <span className="tabular-nums text-slate-500 dark:text-slate-400">
                                        {block.lines.toLocaleString('pt-BR')} linhas
                                    </span>
                                </div>

                                <ul className="mt-1 space-y-0.5 border-l-2 border-slate-200 pl-3 dark:border-slate-700">
                                    {block.breakdown.map((item) => (
                                        <li
                                            key={item.label}
                                            className={`flex justify-between ${
                                                item.discarded
                                                    ? 'text-slate-400 dark:text-slate-500'
                                                    : 'text-slate-600 dark:text-slate-300'
                                            }`}
                                        >
                                            <span>
                                                {item.label}
                                                {item.discarded && ' (descartadas)'}
                                            </span>
                                            <span className="tabular-nums">
                                                {item.count.toLocaleString('pt-BR')}
                                            </span>
                                        </li>
                                    ))}
                                </ul>

                                {/* A conferência que importa: nenhuma linha ficou sem
                                    classificação. Uma linha pode gerar mais de um evento,
                                    então a soma pode passar do total de linhas. */}
                                <p
                                    className={`mt-1 pl-3 ${
                                        block.reconciles
                                            ? 'text-emerald-700 dark:text-emerald-400'
                                            : 'text-amber-700 dark:text-amber-400'
                                    }`}
                                >
                                    {block.reconciles
                                        ? `✓ ${block.events_and_discards.toLocaleString('pt-BR')} itens classificados — nada ficou de fora`
                                        : '⚠ há linhas sem classificação neste bloco'}
                                </p>
                            </div>
                        ))}
                </div>
            )}

            {/* ⚠️ Avisos aparecem. Esconder aviso é o mesmo que não ter aviso. */}
            {summary.warnings.length > 0 && (
                <div className="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-900/50 dark:bg-amber-950/30">
                    <p className="text-xs font-medium text-amber-900 dark:text-amber-200">
                        {summary.warnings.length === 1
                            ? '1 aviso durante a leitura'
                            : `${summary.warnings.length} avisos durante a leitura`}
                    </p>
                    <ul className="mt-1 space-y-0.5 text-xs text-amber-800 dark:text-amber-300">
                        {summary.warnings.slice(0, 10).map((warning, index) => (
                            <li key={index}>{warning}</li>
                        ))}
                    </ul>
                    {summary.warnings.length > 10 && (
                        <p className="mt-1 text-xs text-amber-700 dark:text-amber-400">
                            e mais {summary.warnings.length - 10}…
                        </p>
                    )}
                </div>
            )}
        </article>
    );
}

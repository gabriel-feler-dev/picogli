import type { PdfAggregatePayload } from '@/types';

/**
 * Os resumos vindos de PDF (Spec 007, FR-706, §D7).
 *
 * ⚠️⚠️ **Bloco SEPARADO, e nunca ao lado de métrica de CSV.**
 *
 * É o Artigo V por analogia: aquele artigo proíbe exibir métrica inválida como
 * válida; este bloco existe para não exibir procedência mais fraca como se tivesse
 * a mesma força. Um "tempo na faixa 78%" resumido pela Medtronic e um calculado
 * sobre 3.616 leituras não são o mesmo número — e sem a separação a pessoa não
 * teria como saber qual está olhando.
 *
 * ⚠️ **Não renderiza nada quando não há agregado** — e é isso que mantém a tela
 * de importação idêntica à de antes da fase 7 (T607).
 */
export function PdfAggregateBlock({ aggregates }: { aggregates: PdfAggregatePayload[] }) {
    if (aggregates.length === 0) {
        return null;
    }

    return (
        <section className="mt-10 rounded-lg border border-slate-300 border-dashed p-5 dark:border-slate-700">
            <h2 className="font-medium">Resumos de relatório em PDF</h2>

            {/* ⚠️ A marcação de procedência, antes dos números. */}
            <p className="mt-1.5 text-sm leading-relaxed text-slate-600 dark:text-slate-400">
                Números lidos de um relatório em PDF. São resumos prontos, não cálculos sobre suas
                leituras — por isso aparecem separados.
            </p>

            <div className="mt-4 space-y-3">
                {aggregates.map((agregado) => (
                    <div
                        key={`${agregado.metric}-${agregado.period_start}`}
                        className="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-100 pb-2 last:border-0 dark:border-slate-800"
                    >
                        <div className="min-w-0">
                            <p className="text-sm font-medium">{agregado.label}</p>
                            <p className="text-xs text-slate-500 tabular-nums dark:text-slate-400">
                                {agregado.period_start} a {agregado.period_end}
                            </p>
                            {agregado.superseded_by_csv && (
                                <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    Este período também tem export em CSV. Os números das outras telas
                                    vêm dele, que é mais detalhado.
                                </p>
                            )}
                        </div>

                        <p className="shrink-0 tabular-nums">
                            <span className="text-lg font-semibold">{agregado.value}</span>{' '}
                            <span className="text-xs text-slate-500 dark:text-slate-400">
                                {agregado.unit}
                            </span>
                            {/* ⚠️ O selo é por número, não só por bloco: quem copia
                                uma linha leva a procedência junto. */}
                            <span className="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                PDF
                            </span>
                        </p>
                    </div>
                ))}
            </div>
        </section>
    );
}

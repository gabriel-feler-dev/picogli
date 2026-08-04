import { Head } from '@inertiajs/react';

import { MetricCard } from '@/Components/MetricCard';
import { ClinicalFooter } from '@/Components/ClinicalFooter';
import type { PeriodSummaryPayload } from '@/types';

interface Props {
    summary: PeriodSummaryPayload;
    isEmpty: boolean;
}

/**
 * Dashboard.
 *
 * ⚠️ NFR-201 — nenhum cálculo aqui. Os quatro cards chegam traduzidos, a
 * cobertura chega formatada, e o status de cada métrica já foi comparado com a
 * meta no servidor. Este componente escolhe layout, não significado.
 *
 * Os gráficos (AGP, barra de 24 h, grade de dias) entram no T205.
 */
export default function Dashboard({ summary, isEmpty }: Props) {
    return (
        <>
            <Head title="Painel" />

            <div className="mx-auto max-w-4xl px-6 py-10">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">Seus últimos dias</h1>

                    {/* Artigo V — o denominador nunca fica escondido. */}
                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {summary.coverage.summary}
                        <span className="mx-1.5 text-slate-300 dark:text-slate-600">·</span>
                        {summary.coverage.span_note}
                    </p>
                    <p className="mt-0.5 text-xs text-slate-400 dark:text-slate-500">
                        {summary.coverage.readings_note}
                    </p>
                </header>

                {summary.validity.message !== null && (
                    <p className="mt-6 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
                        {summary.validity.message}
                    </p>
                )}

                {summary.stale_message !== null && (
                    <p className="mt-3 rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900">
                        {summary.stale_message}
                    </p>
                )}

                {isEmpty ? (
                    <p className="mt-10 text-sm text-slate-500 dark:text-slate-400">
                        Nenhuma leitura importada ainda.
                    </p>
                ) : (
                    <section className="mt-8 grid gap-4 sm:grid-cols-2">
                        {summary.metrics.map((metric) => (
                            <MetricCard key={metric.key} metric={metric} />
                        ))}
                    </section>
                )}

                <ClinicalFooter />
            </div>
        </>
    );
}

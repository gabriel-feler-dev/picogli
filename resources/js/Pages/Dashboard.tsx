import { Head, Link } from '@inertiajs/react';

import { AgpChart } from '@/Components/AgpChart';
import { DayGrid } from '@/Components/DayGrid';
import { HourlyBar } from '@/Components/HourlyBar';
import { MetricCard } from '@/Components/MetricCard';
import type { PeriodSummaryPayload } from '@/types';
import AppShell from '@/Layouts/AppShell';
import { Alert } from '@/Components/ui/Alert';
import { ButtonLink } from '@/Components/ui/Button';
import { Section } from '@/Components/ui/Card';
import { EmptyState } from '@/Components/ui/EmptyState';

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

            <AppShell>
                <header>
                    <div className="flex items-baseline justify-between gap-4">
                        <h1 className="text-2xl font-semibold tracking-tight">Seus últimos dias</h1>
                        <Link
                            href="/importar"
                            className="text-sm font-medium text-brand-700 hover:underline dark:text-brand-300"
                        >
                            Importar arquivo
                        </Link>
                    </div>

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
                    <Alert tone="caution" className="mt-6">
                        {summary.validity.message}
                    </Alert>
                )}

                {summary.stale_message !== null && (
                    <Alert tone="note" className="mt-3">
                        {summary.stale_message}
                    </Alert>
                )}

                {isEmpty ? (
                    <div className="mt-10">
                        <EmptyState
                            kind="pending"
                            title="Nenhuma leitura importada ainda"
                            action={<ButtonLink href="/importar">Importar um export</ButtonLink>}
                        >
                            Assim que um export do CareLink for lido, os números deste período
                            aparecem aqui.
                        </EmptyState>
                    </div>
                ) : (
                    <>
                        {/*
                          ⚠️ HIERARQUIA DE LEITURA (T704.1) — a primeira métrica ocupa a
                          largura toda; as demais dividem a grade.

                          A ORDEM continua vindo do servidor. Escolher aqui qual é a
                          principal seria decidir significado clínico no cliente — a
                          mesma linha que a `Evaluation` não cruza ao renderizar os
                          achados na ordem em que chegam.
                        */}
                        <section className="mt-8 grid gap-4 sm:grid-cols-2">
                            {summary.metrics.map((metric, index) => (
                                <div key={metric.key} className={index === 0 ? 'sm:col-span-2' : undefined}>
                                    <MetricCard metric={metric} />
                                </div>
                            ))}
                        </section>

                        <Section
                            title="Seu dia típico"
                            hint="Todos os dias do período sobrepostos, hora por hora."
                            className="mt-10"
                        >
                            <AgpChart percentiles={summary.hourly_percentiles} ranges={summary.ranges} />
                        </Section>

                        <Section
                            title="Onde estão os problemas"
                            hint="A faixa em que sua glicose mais ficou, em cada hora."
                            className="mt-10"
                        >
                            <HourlyBar profile={summary.hourly_profile} />
                        </Section>

                        <Section
                            title="Dia por dia"
                            hint="Quanto tempo na faixa boa em cada dia."
                            className="mt-10"
                        >
                            <DayGrid days={summary.daily_metrics} />
                        </Section>
                    </>
                )}

            </AppShell>
        </>
    );
}

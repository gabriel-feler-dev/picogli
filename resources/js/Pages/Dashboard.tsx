import { Head } from '@inertiajs/react';

import { AgpChart } from '@/Components/AgpChart';
import { DayGrid } from '@/Components/DayGrid';
import { HourlyBar } from '@/Components/HourlyBar';
import { MetricCard } from '@/Components/MetricCard';
import { Alert } from '@/Components/ui/Alert';
import { ButtonLink } from '@/Components/ui/Button';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Explainer, Sigla } from '@/Components/ui/Explainer';
import AppShell, { Grid, PageHeader } from '@/Layouts/AppShell';
import type { PeriodSummaryPayload } from '@/types';

interface Props {
    summary: PeriodSummaryPayload;
    isEmpty: boolean;
}

/**
 * Painel — Spec 008 §V2, §7 do `design.md`.
 *
 * ⚠️ NFR-201 — nenhum cálculo aqui. Os cards chegam traduzidos, a cobertura
 * chega formatada, e o status de cada métrica já foi comparado com a meta no
 * servidor. Este componente escolhe layout, não significado.
 *
 * ⚠️ **A ORDEM das métricas continua vindo do servidor.** A primeira ganha o
 * bloco herói porque chegou primeiro — escolher aqui qual é a principal seria
 * decidir significado clínico no cliente, a mesma linha que a `Evaluation` não
 * cruza ao renderizar os achados.
 */
export default function Dashboard({ summary, isEmpty }: Props) {
    const [heroi, ...demais] = summary.metrics;

    return (
        <>
            <Head title="Painel" />

            <AppShell>
                <PageHeader
                    title="Seus últimos dias"
                    subtitle={
                        <>
                            {/* Artigo V — o denominador nunca fica escondido, e
                                vem junto do título, não num rodapé. */}
                            <span className="tabular-nums">{summary.coverage.summary}</span>
                            <span className="mx-2 text-slate-300 dark:text-slate-600">·</span>
                            <span className="tabular-nums">{summary.coverage.span_note}</span>
                            <span className="mx-2 text-slate-300 dark:text-slate-600">·</span>
                            <span className="tabular-nums">{summary.coverage.readings_note}</span>
                        </>
                    }
                    aside={
                        <ButtonLink href="/importar" variant="secondary" size="sm">
                            Importar arquivo
                        </ButtonLink>
                    }
                />

                {summary.validity.message !== null && (
                    <Alert tone="caution" className="mb-6">
                        {summary.validity.message}
                    </Alert>
                )}

                {summary.stale_message !== null && (
                    <Alert tone="note" className="mb-6">
                        {summary.stale_message}
                    </Alert>
                )}

                {isEmpty ? (
                    <EmptyState
                        kind="pending"
                        title="Nenhuma leitura importada ainda"
                        action={<ButtonLink href="/importar">Importar um export</ButtonLink>}
                    >
                        Assim que um export do CareLink for lido, os números deste período
                        aparecem aqui.
                    </EmptyState>
                ) : (
                    <div className="space-y-4">
                        {/* ── Faixa 1: herói + as demais métricas ────────────── */}
                        <Grid>
                            {heroi !== undefined && (
                                <div className="lg:col-span-5">
                                    <MetricCard metric={heroi} size="lg" />
                                </div>
                            )}

                            <div className="grid gap-4 sm:col-span-2 sm:grid-cols-2 lg:col-span-7 lg:grid-cols-2">
                                {demais.map((metric) => (
                                    <MetricCard key={metric.key} metric={metric} />
                                ))}
                            </div>
                        </Grid>

                        {/* ── Faixa 2: o dia típico, largo ───────────────────── */}
                        <Grid>
                            <section className="rounded-2xl border border-slate-200 bg-white p-5 sm:col-span-2 lg:col-span-12 dark:border-slate-800 dark:bg-slate-900">
                                <h2 className="text-sm font-semibold">Seu dia típico</h2>
                                <p className="mt-0.5 mb-4 text-xs text-slate-500 dark:text-slate-400">
                                    Todos os dias do período sobrepostos, hora por hora.
                                </p>

                                <AgpChart
                                    percentiles={summary.hourly_percentiles}
                                    ranges={summary.ranges}
                                />

                                <Explainer>
                                    A linha do meio é a mediana: metade das leituras daquele horário
                                    ficou acima dela e metade abaixo. As faixas mais claras mostram
                                    onde ficaram 50% e 90% das leituras — quanto mais larga, mais a
                                    glicose variou naquele horário entre um dia e outro.
                                </Explainer>
                            </section>
                        </Grid>

                        {/* ── Faixa 3: duas colunas ──────────────────────────── */}
                        <Grid>
                            <section className="rounded-2xl border border-slate-200 bg-white p-5 lg:col-span-5 dark:border-slate-800 dark:bg-slate-900">
                                <h2 className="text-sm font-semibold">Onde estão os problemas</h2>
                                <p className="mt-0.5 mb-4 text-xs text-slate-500 dark:text-slate-400">
                                    A faixa em que sua glicose mais ficou, em cada hora.
                                </p>

                                <HourlyBar profile={summary.hourly_profile} />

                                <Explainer>
                                    Cada fatia é uma hora do dia, colorida pela faixa em que a
                                    glicose mais ficou naquele horário ao longo do período. Serve
                                    para achar o horário que mais se repete — madrugada, depois do
                                    almoço, fim de tarde.
                                </Explainer>
                            </section>

                            <section className="rounded-2xl border border-slate-200 bg-white p-5 lg:col-span-7 dark:border-slate-800 dark:bg-slate-900">
                                <h2 className="text-sm font-semibold">Dia por dia</h2>
                                <p className="mt-0.5 mb-4 text-xs text-slate-500 dark:text-slate-400">
                                    Quanto tempo na faixa boa em cada dia.
                                </p>

                                <DayGrid days={summary.daily_metrics} />

                                <Explainer>
                                    <Sigla
                                        sigla="TIR"
                                        significado="Time in Range — tempo na faixa de 70 a 180 mg/dL"
                                    />{' '}
                                    é a fatia do dia em que a glicose ficou entre 70 e 180 mg/dL.
                                    Dias com hachura tiveram menos leituras do sensor, então o
                                    número deles é menos comparável com os outros.
                                </Explainer>
                            </section>
                        </Grid>
                    </div>
                )}
            </AppShell>
        </>
    );
}

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

                            <div className="grid gap-4 sm:grid-cols-2 lg:col-span-7 lg:grid-cols-2">
                                {demais.map((metric) => (
                                    <MetricCard key={metric.key} metric={metric} />
                                ))}
                            </div>
                        </Grid>

                        {/* ── Faixa 2: o dia típico, largo ───────────────────── */}
                        <Grid>
                            <section className="rounded-2xl border border-slate-200 bg-white p-5 lg:col-span-12 dark:border-slate-800 dark:bg-slate-900">
                                <h2 className="text-sm font-semibold">Seu dia típico</h2>
                                <p className="mt-0.5 mb-4 text-xs text-slate-500 dark:text-slate-400">
                                    Todos os dias do período sobrepostos, hora por hora.
                                </p>

                                <AgpChart
                                    percentiles={summary.hourly_percentiles}
                                    ranges={summary.ranges}
                                />

                                <Explainer>
                                    <p>
                                        Este gráfico empilha todos os dias do período num dia só. A
                                        linha escura é a <strong className="font-medium">mediana</strong>:
                                        naquele horário, metade das leituras ficou acima dela e metade
                                        abaixo. A faixa clara em volta mostra onde ficaram 90% delas.
                                    </p>
                                    <p className="mt-2">
                                        <strong className="font-medium">O que procurar:</strong> onde a
                                        faixa fica <em>larga</em>, a glicose variou muito naquele
                                        horário de um dia para o outro — é um horário imprevisível.
                                        Onde ela fica estreita, os dias se repetem.
                                    </p>
                                    <p className="mt-2">
                                        Buracos no traçado são horas em que o sensor não estava
                                        medindo. A linha não atravessa o vazio de propósito: ligar os
                                        pontos ali inventaria uma medição que não existiu.
                                    </p>
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
                                    <p>
                                        Cada fatia é uma hora do dia, pintada com a faixa em que sua
                                        glicose mais ficou naquele horário ao longo de todo o período.
                                    </p>
                                    <p className="mt-2">
                                        <strong className="font-medium">O que procurar:</strong> blocos
                                        de cor seguidos. Uma hora amarela isolada é ruído; três horas
                                        amarelas em sequência todo fim de tarde é um padrão — e é o
                                        tipo de coisa que a tela de avaliação transforma em achado.
                                    </p>
                                    <p className="mt-2">
                                        Fatias mais apagadas são horas com poucas leituras do sensor.
                                    </p>
                                </Explainer>
                            </section>

                            <section className="rounded-2xl border border-slate-200 bg-white p-5 lg:col-span-7 dark:border-slate-800 dark:bg-slate-900">
                                <h2 className="text-sm font-semibold">Dia por dia</h2>
                                <p className="mt-0.5 mb-4 text-xs text-slate-500 dark:text-slate-400">
                                    Quanto tempo na faixa boa em cada dia.
                                </p>

                                <DayGrid days={summary.daily_metrics} />

                                <Explainer>
                                    <p>
                                        Cada quadrado é um dia, e o número dentro dele é o{' '}
                                        <Sigla
                                            sigla="TIR"
                                            significado="Time in Range — tempo na faixa de 70 a 180 mg/dL"
                                        />{' '}
                                        — a fatia daquele dia em que a glicose ficou entre 70 e
                                        180 mg/dL. Quanto mais forte a cor, maior o tempo na faixa.
                                    </p>
                                    <p className="mt-2">
                                        <strong className="font-medium">O que procurar:</strong> a
                                        posição na semana. Se os quadrados fracos caem sempre na mesma
                                        coluna, o padrão é de dia da semana — fim de semana, dia de
                                        treino, dia de plantão.
                                    </p>
                                    <p className="mt-2">
                                        ⚠️ Dias com hachura e <span aria-hidden="true">*</span>{' '}
                                        tiveram menos leituras do sensor. O número deles não é
                                        comparável com o dos outros: 80% de meio dia de dado não é a
                                        mesma coisa que 80% de um dia inteiro.
                                    </p>
                                </Explainer>
                            </section>
                        </Grid>
                    </div>
                )}
            </AppShell>
        </>
    );
}

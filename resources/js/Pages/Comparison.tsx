import { Head, Link } from '@inertiajs/react';

import { DeltaCard } from '@/Components/DeltaCard';
import type { ComparisonPagePayload } from '@/types';
import AppShell from '@/Layouts/AppShell';

/**
 * Tela de comparação entre períodos (Spec 007, FR-704, §8.4).
 *
 * ⚠️ **Cada lado mostra o próprio denominador** (Artigo V): span em dias,
 * captura do sensor e o veredito de validade. Comparar 14 dias com 3 e apresentar
 * o delta como fato é exatamente o que aquele artigo existe para impedir — e é a
 * comparação que o usuário pede sem perceber, porque "melhorei em relação à
 * semana passada?" não menciona cobertura.
 *
 * ⚠️ **Nada aqui calcula** (NFR-703).
 */
export default function Comparison({
    has_data,
    error,
    period_a,
    period_b,
    metrics,
}: ComparisonPagePayload) {
    return (
        <>
            <Head title="Comparar períodos" />

            <AppShell>
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">Comparar períodos</h1>
                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Os últimos 7 dias contra os 7 anteriores
                    </p>
                </header>

                {!has_data && (
                    <div className="mt-8 rounded-lg border border-slate-200 p-6 dark:border-slate-800">
                        <h2 className="font-medium">Ainda não há dados para comparar</h2>
                        <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">
                            Importe um export do CareLink e a comparação passa a funcionar.
                        </p>
                        <Link
                            href="/importar"
                            className="mt-4 inline-block text-sm font-medium text-brand-700 hover:underline dark:text-brand-300"
                        >
                            Ir para importação →
                        </Link>
                    </div>
                )}

                {error !== undefined && error !== null && (
                    <p className="mt-8 rounded-lg border border-amber-300 bg-amber-50/50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800/50 dark:bg-amber-950/10 dark:text-amber-500">
                        {error}
                    </p>
                )}

                {period_a !== undefined && period_b !== undefined && (
                    <>
                        {/* ⚠️ Artigo V — o denominador de cada lado, antes de qualquer delta. */}
                        <section className="mt-8 grid gap-3 sm:grid-cols-2">
                            {[
                                { titulo: 'Período anterior', lado: period_a },
                                { titulo: 'Período atual', lado: period_b },
                            ].map(({ titulo, lado }) => (
                                <div
                                    key={titulo}
                                    className="rounded-lg border border-slate-200 px-4 py-3 dark:border-slate-800"
                                >
                                    <p className="text-xs tracking-wide text-slate-500 dark:text-slate-400">
                                        {titulo}
                                    </p>
                                    <p className="mt-0.5 text-sm font-medium tabular-nums">
                                        {lado.from} a {lado.to}
                                    </p>
                                    <p className="mt-1 text-xs text-slate-600 tabular-nums dark:text-slate-400">
                                        {lado.days_span} dias · {lado.coverage_percent}% de captura ·{' '}
                                        {lado.reading_count} leituras
                                    </p>
                                    {!lado.is_valid && (
                                        <p className="mt-1.5 text-xs text-amber-700 dark:text-amber-500">
                                            ⚠️ Abaixo do mínimo para métricas como GMI e variabilidade
                                        </p>
                                    )}
                                </div>
                            ))}
                        </section>

                        <section className="mt-6 grid gap-3 sm:grid-cols-2">
                            {metrics?.map((metric) => (
                                <DeltaCard key={metric.key} metric={metric} />
                            ))}
                        </section>
                    </>
                )}

            </AppShell>
        </>
    );
}

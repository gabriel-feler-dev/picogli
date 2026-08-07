import { Head } from '@inertiajs/react';

import { ClinicalFooter } from '@/Components/ClinicalFooter';
import { MealRow } from '@/Components/MealRow';
import type { MealsPagePayload } from '@/types';

/**
 * Tela de refeições (Spec 007, FR-702, §10.5).
 *
 * ⚠️ **O agrupamento não conclui nada** (§D3). Ele ordena por subida média e
 * mostra a contagem. Dizer "pizza é pior que arroz" seria a R11 — regra
 * determinística nova, com limiar calibrado sobre amostra que esta fase apenas
 * começa a coletar.
 *
 * ⚠️ **A contagem aparece ao lado de toda média** (Artigo V). "Pizza sobe
 * 87 mg/dL" sobre duas refeições é ruído com cara de conclusão, e o denominador é
 * o que impede a leitura errada.
 *
 * ⚠️ **Nada aqui calcula** (NFR-703). Média e agrupamento vêm do servidor.
 */
export default function Meals({ period, meals, groups, meal_count, labelled_count }: MealsPagePayload) {
    return (
        <>
            <Head title="Refeições" />

            <div className="mx-auto max-w-4xl px-6 py-10">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">Refeições</h1>
                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        O que aconteceu com a glicose depois de cada uma
                    </p>
                    <p className="mt-3 text-sm text-slate-600 tabular-nums dark:text-slate-300">
                        {period.from} a {period.to} · {meal_count} refeições · {labelled_count} com rótulo
                    </p>
                </header>

                {meal_count === 0 && (
                    <div className="mt-8 rounded-lg border border-slate-200 p-6 dark:border-slate-800">
                        <h2 className="font-medium">Nenhuma refeição no período</h2>
                        <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">
                            As refeições vêm das linhas da calculadora de bolus do export. Se você não
                            usou a calculadora, elas não aparecem aqui.
                        </p>
                    </div>
                )}

                {groups.length > 0 && (
                    <section className="mt-10">
                        <h2 className="text-lg font-medium">Por rótulo</h2>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Ordenado pela maior subida média. A contagem diz quantas refeições
                            sustentam cada número.
                        </p>

                        <div className="mt-4 space-y-2">
                            {groups.map((grupo) => (
                                <div
                                    key={grupo.label}
                                    className="flex items-baseline justify-between rounded-lg border border-slate-200 px-4 py-3 dark:border-slate-800"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium">{grupo.label}</p>
                                        {/* ⚠️ O denominador, sempre visível. */}
                                        <p className="mt-0.5 text-xs text-slate-500 tabular-nums dark:text-slate-400">
                                            {grupo.meal_count === 1
                                                ? '1 refeição'
                                                : `${grupo.meal_count} refeições`}
                                            {grupo.with_response_count !== grupo.meal_count && (
                                                <> · {grupo.with_response_count} com leitura de sensor</>
                                            )}
                                        </p>
                                        {!grupo.has_enough_sample && (
                                            <p className="mt-1 text-xs text-amber-700 dark:text-amber-500">
                                                Poucas refeições ainda — a média muda bastante com a próxima.
                                            </p>
                                        )}
                                    </div>

                                    <div className="shrink-0 pl-4 text-right tabular-nums">
                                        {grupo.mean_delta_2h !== null ? (
                                            <>
                                                <p className="text-lg font-semibold">
                                                    {grupo.mean_delta_2h > 0 ? '+' : ''}
                                                    {grupo.mean_delta_2h}
                                                </p>
                                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                                    subida média
                                                </p>
                                            </>
                                        ) : (
                                            <p className="text-sm text-slate-400 dark:text-slate-500">
                                                sem leitura
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>
                )}

                {meal_count > 0 && groups.length === 0 && (
                    <p className="mt-10 text-sm text-slate-600 dark:text-slate-400">
                        Rotule algumas refeições e elas aparecem agrupadas aqui.
                    </p>
                )}

                {meal_count > 0 && (
                    <section className="mt-10">
                        <h2 className="text-lg font-medium">Todas as refeições</h2>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Nomeie refeições parecidas com o mesmo rótulo e elas passam a aparecer
                            agrupadas acima.
                        </p>

                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-left">
                                <thead>
                                    <tr className="border-b border-slate-200 text-xs tracking-wide text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                        <th className="pb-2 pr-4 font-medium">Quando</th>
                                        <th className="pb-2 pr-4 font-medium">Rótulo</th>
                                        <th className="pb-2 pr-4 font-medium">Carboidrato</th>
                                        <th className="pb-2 pr-4 font-medium">Glicose na hora</th>
                                        <th className="pb-2 pr-4 font-medium">Pico em 2 h</th>
                                        <th className="pb-2 pr-4 font-medium">Subida</th>
                                        <th className="pb-2 font-medium">Depois de 4 h</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {meals.map((refeicao) => (
                                        <MealRow key={refeicao.id} meal={refeicao} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                <ClinicalFooter />
            </div>
        </>
    );
}

import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import type { MealRowPayload } from '@/types';

/**
 * Uma linha da tela de refeições (Spec 007, FR-702).
 *
 * ⚠️ **Não calcula nada** (NFR-703). `delta_2h` chega pronto do servidor, que o
 * lê da coluna que o `MealEnricher` preencheu na importação. Subtrair aqui
 * criaria a terceira versão do mesmo número — e a fase 6 já provou que a segunda
 * divergiu.
 *
 * ⚠️ **`null` é estado normal.** Refeição sem leitura de sensor por perto não tem
 * resposta glicêmica apurável, e um `0` no lugar pareceria "não subiu nada".
 */
export function MealRow({ meal }: { meal: MealRowPayload }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, patch, processing } = useForm({ label: meal.label ?? '' });

    function salvar(e: React.FormEvent) {
        e.preventDefault();

        patch(`/refeicoes/${meal.id}/rotulo`, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    }

    return (
        <tr className="border-b border-slate-100 last:border-0 dark:border-slate-800">
            <td className="py-3 pr-4 text-sm whitespace-nowrap text-slate-500 tabular-nums dark:text-slate-400">
                {meal.at}
            </td>

            <td className="py-3 pr-4">
                {editing ? (
                    <form onSubmit={salvar} className="flex gap-1.5">
                        <input
                            type="text"
                            value={data.label}
                            onChange={(e) => setData('label', e.target.value)}
                            placeholder="pizza, feijoada…"
                            maxLength={60}
                            autoFocus
                            className="w-40 rounded border border-slate-300 px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-900"
                        />
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded bg-slate-900 px-2.5 py-1 text-xs font-medium text-white disabled:opacity-40 dark:bg-slate-100 dark:text-slate-900"
                        >
                            Salvar
                        </button>
                    </form>
                ) : (
                    <button
                        type="button"
                        onClick={() => setEditing(true)}
                        className="text-sm text-left transition hover:underline"
                    >
                        {meal.label !== null ? (
                            <span className="rounded-full bg-slate-100 px-2.5 py-1 dark:bg-slate-800">
                                {meal.label}
                            </span>
                        ) : (
                            <span className="text-slate-400 dark:text-slate-500">+ rótulo</span>
                        )}
                    </button>
                )}
            </td>

            <td className="py-3 pr-4 text-sm tabular-nums">
                {meal.carbs_g !== null ? `${meal.carbs_g} g` : '—'}
            </td>

            <td className="py-3 pr-4 text-sm tabular-nums text-slate-600 dark:text-slate-300">
                {meal.bg_input !== null ? meal.bg_input : '—'}
            </td>

            <td className="py-3 pr-4 text-sm tabular-nums">
                {meal.peak_2h !== null ? meal.peak_2h : '—'}
            </td>

            {/* A subida vem calculada do servidor — ver o bloco da classe. */}
            <td className="py-3 pr-4 text-sm font-medium tabular-nums">
                {meal.delta_2h !== null ? (
                    <>
                        {meal.delta_2h > 0 ? '+' : ''}
                        {meal.delta_2h}
                    </>
                ) : (
                    <span
                        className="text-xs font-normal text-slate-400 dark:text-slate-500"
                        title="Sem leitura de sensor por perto"
                    >
                        —
                    </span>
                )}
            </td>

            <td className="py-3 text-sm tabular-nums text-slate-600 dark:text-slate-300">
                {meal.glucose_4h !== null ? meal.glucose_4h : '—'}
            </td>
        </tr>
    );
}

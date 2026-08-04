import { Head } from '@inertiajs/react';

/**
 * Página mínima que prova o wiring do Inertia (T200.6).
 *
 * Existe só para o critério de aceite: props tipados vindos do controller
 * chegando ao React. Será substituída pelo dashboard real no T204.
 */
interface Props {
    appName: string;
    phase: string;
    importsCount: number;
    readingsCount: number;
}

export default function Health({ appName, phase, importsCount, readingsCount }: Props) {
    return (
        <>
            <Head title="Diagnóstico" />

            <main className="mx-auto max-w-xl px-6 py-16">
                <h1 className="text-2xl font-semibold tracking-tight">{appName}</h1>
                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{phase}</p>

                <dl className="mt-8 grid grid-cols-2 gap-4">
                    <div className="rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                        <dt className="text-xs uppercase tracking-wide text-slate-500">Importações</dt>
                        <dd className="mt-1 text-2xl font-semibold tabular-nums">{importsCount}</dd>
                    </div>
                    <div className="rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                        <dt className="text-xs uppercase tracking-wide text-slate-500">Leituras de sensor</dt>
                        <dd className="mt-1 text-2xl font-semibold tabular-nums">
                            {readingsCount.toLocaleString('pt-BR')}
                        </dd>
                    </div>
                </dl>

                <p className="mt-8 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                    Wiring do Inertia verificado. Os números acima vêm do servidor como props —
                    nenhuma contagem é feita aqui.
                </p>
            </main>
        </>
    );
}

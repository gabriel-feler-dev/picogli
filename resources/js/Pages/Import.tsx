import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { ImportSummary } from '@/Components/ImportSummary';
import { PdfAggregateBlock } from '@/Components/PdfAggregateBlock';
import type { ImportSummaryPayload, PdfAggregatePayload } from '@/types';
import AppShell from '@/Layouts/AppShell';
import { ImportProgress } from '@/Components/ImportProgress';
import { Alert } from '@/Components/ui/Alert';
import { Button } from '@/Components/ui/Button';

interface Props {
    imports: ImportSummaryPayload[];
    timezones: string[];
    defaultTimezone: string;
    /**
     * ⚠️ Resumos de PDF (Spec 007, §D7). Bloco SEPARADO, nunca misturado com
     * métrica de CSV — e vazio quando nenhum PDF foi importado, o que mantém
     * esta tela idêntica à de antes da fase 7.
     */
    pdfAggregates: PdfAggregatePayload[];
}

/**
 * Tela de importação (FR-207).
 *
 * ⚠️ Existe porque o pior cenário da fase 1 é importação **silenciosamente
 * parcial**. Uma tela que só dissesse "importado com sucesso" não protegeria
 * contra nada — o resumo detalhado é o requisito, não um extra.
 */
export default function Import({ imports, timezones, defaultTimezone,
    pdfAggregates,
}: Props) {
    const { data, setData, post, processing, errors, progress } = useForm<{
        file: File | null;
        timezone: string;
    }>({
        file: null,
        timezone: defaultTimezone,
    });

    const [dragging, setDragging] = useState(false);
    const flash = (usePage().props as { status?: string }).status;

    // Enquanto há importação em andamento, recarrega só os dados dela. Em
    // produção a fila é acionada por cron (ADR-5), então o resumo pode demorar
    // até um minuto para aparecer — e uma tela parada pareceria travada.
    const emAndamento = imports.find(
        (item) => item.status === 'pending' || item.status === 'processing',
    );

    const running = emAndamento !== undefined;

    useEffect(() => {
        if (!running) {
            return;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['imports'] });
        }, 3000);

        return () => window.clearInterval(timer);
    }, [running]);

    return (
        <>
            <Head title="Importar" />

            <AppShell>
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">Importar export do CareLink</h1>
                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Envie o arquivo CSV exportado do CareLink. O arquivo é apagado depois da
                        importação — os dados ficam no banco.
                    </p>
                </header>

                {flash !== undefined && (
                    <Alert tone="note" className="mt-6">
                        {flash}
                    </Alert>
                )}

                {/* ⚠️ O estado da importação em andamento vem ANTES do formulário:
                    é a resposta à pergunta que a pessoa acabou de fazer ao enviar o
                    arquivo. Embaixo da lista, ela reenviaria antes de rolar até lá. */}
                {emAndamento !== undefined && (
                    <div className="mt-6">
                        <ImportProgress status={emAndamento.status} />
                    </div>
                )}

                <form
                    className="mt-8 space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        post('/importar', { forceFormData: true });
                    }}
                >
                    <div
                        onDragOver={(event) => {
                            event.preventDefault();
                            setDragging(true);
                        }}
                        onDragLeave={() => setDragging(false)}
                        onDrop={(event) => {
                            event.preventDefault();
                            setDragging(false);
                            const dropped = event.dataTransfer.files[0];
                            if (dropped !== undefined) {
                                setData('file', dropped);
                            }
                        }}
                        className={`rounded-xl border-2 border-dashed px-6 py-10 text-center transition ${
                            dragging
                                ? 'border-brand-500 bg-brand-50 dark:bg-brand-500/10'
                                : 'border-slate-300 dark:border-slate-700'
                        }`}
                    >
                        <label htmlFor="file" className="cursor-pointer text-sm font-medium text-brand-700 dark:text-brand-300">
                            Escolher arquivo
                            <input
                                id="file"
                                type="file"
                                accept=".csv,text/csv"
                                className="sr-only"
                                onChange={(event) => setData('file', event.target.files?.[0] ?? null)}
                            />
                        </label>
                        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            ou arraste o CSV até aqui
                        </p>
                        {data.file !== null && (
                            <p className="mt-3 text-sm tabular-nums">{data.file.name}</p>
                        )}
                        {errors.file !== undefined && (
                            <p className="mt-2 text-sm text-red-600 dark:text-red-400">{errors.file}</p>
                        )}
                    </div>

                    <div>
                        <label htmlFor="timezone" className="block text-sm font-medium">
                            Fuso do aparelho
                        </label>
                        {/* §A5 — o CSV não carrega fuso, e errá-lo desloca todo
                            insight de horário mantendo os números plausíveis. */}
                        <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            O arquivo não informa o fuso. Confirme o do aparelho no período exportado.
                        </p>
                        <select
                            id="timezone"
                            value={data.timezone}
                            onChange={(event) => setData('timezone', event.target.value)}
                            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 dark:border-slate-700 dark:bg-slate-900"
                        >
                            {timezones.map((timezone) => (
                                <option key={timezone} value={timezone}>
                                    {timezone}
                                </option>
                            ))}
                        </select>
                        {errors.timezone !== undefined && (
                            <p className="mt-1 text-sm text-red-600 dark:text-red-400">{errors.timezone}</p>
                        )}
                    </div>

                    {progress !== null && progress !== undefined && (
                        <div className="h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                            <div
                                className="h-full bg-brand-700 transition-all"
                                style={{ width: `${progress.percentage ?? 0}%` }}
                            />
                        </div>
                    )}

                    <Button type="submit" disabled={processing || data.file === null}>
                        {processing ? 'Enviando…' : 'Importar'}
                    </Button>
                </form>

                <section className="mt-12">
                    <h2 className="text-sm font-semibold">Importações</h2>

                    {imports.length === 0 ? (
                        <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                            Nenhuma importação ainda.
                        </p>
                    ) : (
                        <div className="mt-4 space-y-5">
                            {imports.map((item) => (
                                <ImportSummary key={item.id} summary={item} />
                            ))}
                        </div>
                    )}
                </section>

                {/* ⚠️ Não renderiza nada quando a lista é vazia (§D7, T607). */}
                <PdfAggregateBlock aggregates={pdfAggregates} />

            </AppShell>
        </>
    );
}

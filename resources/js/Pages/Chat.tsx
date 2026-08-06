import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

import { ClinicalFooter } from '@/Components/ClinicalFooter';
import { ToolTrace } from '@/Components/ToolTrace';
import type { ChatPagePayload } from '@/types';

/**
 * Tela de conversa (Spec 006, FR-610, §10.3).
 *
 * ⚠️ **Sem streaming, e é decisão** (§D11). A resposta é persistida antes de
 * chegar aqui, e a conversa renderiza inteira a partir das props. ADR-5b avisa
 * que hospedagem compartilhada tem timeout curto e buffer que não se controla do
 * código — um chat que DEPENDE de SSE é um chat que pode não funcionar no
 * destino, e a descoberta seria no deploy.
 *
 * ⚠️ **Nada aqui calcula** (NFR-404). Nenhum `reduce`, `toFixed` ou `Math.`: os
 * números da resposta vieram das ferramentas, e o rodapé de procedência mostra
 * exatamente o que foi consultado.
 */
export default function Chat({
    conversations,
    conversation,
    messages,
    suggestions,
    has_data,
}: ChatPagePayload) {
    const { data, setData, post, processing, reset, errors } = useForm({ message: '' });
    const fim = useRef<HTMLDivElement>(null);

    useEffect(() => {
        fim.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages.length]);

    function enviar(texto: string) {
        if (conversation === null) {
            // Sem conversa aberta, a primeira mensagem cria uma.
            router.post('/conversar');

            return;
        }

        setData('message', texto);
    }

    function submeter(e: React.FormEvent) {
        e.preventDefault();

        if (conversation === null || data.message.trim() === '') {
            return;
        }

        post(`/conversar/${conversation.id}/mensagens`, {
            preserveScroll: true,
            onSuccess: () => reset('message'),
        });
    }

    return (
        <>
            <Head title="Conversar" />

            <div className="mx-auto flex max-w-5xl gap-8 px-6 py-10">
                <aside className="hidden w-56 shrink-0 lg:block">
                    <Link
                        href="/conversar"
                        method="post"
                        as="button"
                        className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-medium transition hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
                    >
                        Nova conversa
                    </Link>

                    <nav className="mt-4 space-y-1">
                        {conversations.map((c) => (
                            <Link
                                key={c.id}
                                href={`/conversar/${c.id}`}
                                className={`block truncate rounded px-3 py-2 text-sm transition ${
                                    conversation?.id === c.id
                                        ? 'bg-slate-100 font-medium dark:bg-slate-800'
                                        : 'text-slate-600 hover:bg-slate-50 dark:text-slate-400 dark:hover:bg-slate-800/50'
                                }`}
                            >
                                {c.title ?? 'Sem título'}
                            </Link>
                        ))}
                    </nav>
                </aside>

                <main className="min-w-0 flex-1">
                    <header>
                        <h1 className="text-2xl font-semibold tracking-tight">Conversar com meus dados</h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            As respostas usam só os números que o PicoGli calculou
                        </p>
                    </header>

                    {!has_data && (
                        <div className="mt-8 rounded-lg border border-slate-200 p-6 dark:border-slate-800">
                            <h2 className="font-medium">Ainda não há dados para conversar</h2>
                            <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">
                                Importe um export do CareLink e o chat passa a responder sobre ele.
                            </p>
                            <Link
                                href="/importar"
                                className="mt-4 inline-block text-sm font-medium text-sky-700 hover:underline dark:text-sky-400"
                            >
                                Ir para importação →
                            </Link>
                        </div>
                    )}

                    {has_data && messages.length === 0 && (
                        <div className="mt-8">
                            <h2 className="font-medium">Pergunte o que quiser sobre seus dados</h2>
                            <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">
                                Cada resposta mostra quais dados foram consultados.
                            </p>

                            <div className="mt-4 flex flex-wrap gap-2">
                                {suggestions.map((sugestao) => (
                                    <button
                                        key={sugestao}
                                        type="button"
                                        onClick={() => enviar(sugestao)}
                                        className="rounded-full border border-slate-300 px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                                    >
                                        {sugestao}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    <div className="mt-8 space-y-6">
                        {messages.map((m) => (
                            <article
                                key={m.id}
                                className={
                                    m.role === 'user'
                                        ? 'ml-auto max-w-[85%] rounded-lg bg-slate-100 px-4 py-3 dark:bg-slate-800'
                                        : 'max-w-[95%]'
                                }
                            >
                                {m.role === 'assistant' && (
                                    <p className="mb-1 text-xs font-medium tracking-wide text-slate-500 dark:text-slate-400">
                                        ✦ PicoGli
                                    </p>
                                )}

                                {/* Parágrafos: formatação, não cálculo. */}
                                {m.content.split(/\n{2,}/).map((paragrafo, i) => (
                                    <p key={i} className="mt-2 first:mt-0 text-[15px] leading-relaxed">
                                        {paragrafo}
                                    </p>
                                ))}

                                {m.role === 'assistant' && <ToolTrace consulted={m.consulted} />}
                            </article>
                        ))}

                        <div ref={fim} />
                    </div>

                    {has_data && conversation !== null && (
                        <form onSubmit={submeter} className="mt-8">
                            <div className="flex gap-2">
                                <input
                                    type="text"
                                    value={data.message}
                                    onChange={(e) => setData('message', e.target.value)}
                                    placeholder="Pergunte sobre seus números…"
                                    maxLength={2000}
                                    disabled={processing}
                                    className="flex-1 rounded-md border border-slate-300 px-4 py-2.5 text-[15px] outline-none focus:border-sky-500 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900"
                                />
                                <button
                                    type="submit"
                                    disabled={processing || data.message.trim() === ''}
                                    className="rounded-md bg-slate-900 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-40 dark:bg-slate-100 dark:text-slate-900"
                                >
                                    {processing ? 'Consultando…' : 'Enviar'}
                                </button>
                            </div>

                            {errors.message !== undefined && (
                                <p className="mt-2 text-sm text-amber-700 dark:text-amber-500">{errors.message}</p>
                            )}
                        </form>
                    )}

                    {has_data && conversation === null && (
                        <Link
                            href="/conversar"
                            method="post"
                            as="button"
                            className="mt-8 rounded-md bg-slate-900 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900"
                        >
                            Começar uma conversa
                        </Link>
                    )}

                    <ClinicalFooter />
                </main>
            </div>
        </>
    );
}

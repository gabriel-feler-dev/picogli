import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

import { ToolTrace } from '@/Components/ToolTrace';
import type { ChatPagePayload } from '@/types';
import AppShell, { PageHeader } from '@/Layouts/AppShell';
import { Button, ButtonLink } from '@/Components/ui/Button';

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

            <AppShell>
                {/* ⚠️ A lista de conversas some abaixo de lg, não abaixo de md:
                    a coluna principal precisa de largura para a conversa caber, e
                    a lista continua alcançável pelo botão "Nova conversa". */}
                <div className="flex gap-8">
                <aside className="hidden w-56 shrink-0 lg:block">
                    <ButtonLink
                        href="/conversar"
                        method="post"
                        as="button"
                        className="w-full"
                        variant="secondary"
                        size="sm"
                    >
                        Nova conversa
                    </ButtonLink>

                    <nav className="mt-4 space-y-1">
                        {conversations.map((c) => (
                            <Link
                                key={c.id}
                                href={`/conversar/${c.id}`}
                                className={`block truncate rounded px-3 py-2 text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 ${
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
                    <PageHeader
                        title="Conversar com meus dados"
                        subtitle="As respostas usam só os números que o PicoGli calculou"
                    />

                    {!has_data && (
                        <div className="mt-8 rounded-lg border border-slate-200 p-6 dark:border-slate-800">
                            <h2 className="font-medium">Ainda não há dados para conversar</h2>
                            <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">
                                Importe um export do CareLink e o chat passa a responder sobre ele.
                            </p>
                            <Link
                                href="/importar"
                                className="mt-4 inline-block text-sm font-medium text-brand-700 hover:underline dark:text-brand-300"
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
                                        className="rounded-full border border-slate-300 px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
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

                        {/* ⚠️ O turno demora: o modelo pode chamar ferramenta até
                            cinco vezes antes de responder (§D5 da Spec 006), e cada
                            chamada é uma consulta ao banco. Sem este bloco, a tela
                            fica idêntica por vários segundos depois do envio — e a
                            pessoa reenvia a pergunta.

                            "Consultando seus dados" é literal: é exatamente o que
                            está acontecendo. O modelo não recebe os dados, ele
                            recebe ferramentas (Artigo III). */}
                        {processing && (
                            <div
                                role="status"
                                aria-live="polite"
                                className="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400"
                            >
                                <span
                                    aria-hidden="true"
                                    className="size-3.5 animate-spin rounded-full border-2 border-slate-300 border-t-brand-500 motion-reduce:animate-none dark:border-slate-700 dark:border-t-brand-300"
                                />
                                Consultando seus dados…
                            </div>
                        )}

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
                                    className="flex-1 rounded-md border border-slate-300 px-4 py-2.5 text-[15px] outline-none focus:border-brand-500 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900"
                                />
                                <Button type="submit" disabled={processing || data.message.trim() === ''}>
                                    {processing ? 'Consultando…' : 'Enviar'}
                                </Button>
                            </div>

                            {errors.message !== undefined && (
                                <p className="mt-2 text-sm text-amber-700 dark:text-amber-500">{errors.message}</p>
                            )}
                        </form>
                    )}

                    {has_data && conversation === null && (
                        <ButtonLink
                            href="/conversar"
                            method="post"
                            as="button"
                            className="mt-8"
                        >
                            Começar uma conversa
                        </ButtonLink>
                    )}

                </main>
                </div>
            </AppShell>
        </>
    );
}

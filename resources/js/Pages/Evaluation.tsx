import { Head } from '@inertiajs/react';

import { FindingCard } from '@/Components/FindingCard';
import { NarrativeBlock } from '@/Components/NarrativeBlock';
import type { EvaluationPayload } from '@/types';
import AppShell from '@/Layouts/AppShell';
import { ButtonLink } from '@/Components/ui/Button';
import { Alert } from '@/Components/ui/Alert';
import { EmptyState } from '@/Components/ui/EmptyState';

/**
 * Tela de avaliação (FR-414, FR-415).
 *
 * ⚠️ Os achados chegam **ordenados pelo servidor** — severidade e depois rank.
 * Reordenar aqui desfaria a decisão de produto registrada no enum `RuleId`:
 * hipoglicemia primeiro porque é o risco agudo, e o reenquadramento (R4) antes
 * do detalhe do dia ruim (R3), para que a pessoa leia a perspectiva antes do
 * episódio.
 *
 * ⚠️ **Dois estados vazios diferentes**, e a distinção é o requisito:
 *
 *   sem relatório .......... "ainda não há o que analisar"
 *   zero achados ........... "nenhum padrão para apontar" — BOA NOTÍCIA
 *
 * Confundi-los faria o app dizer "nada encontrado" para quem nunca importou, e
 * "importe algo" para quem está com tudo em ordem.
 */
export default function Evaluation({
    has_report,
    period,
    coverage,
    findings,
    rule_failures,
    is_stale,
    generated_at,
    narrative,
}: EvaluationPayload) {
    return (
        <>
            <Head title="Avaliação" />

            <AppShell>
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">Avaliação do período</h1>
                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        O que os números mostram além da média
                    </p>

                    {/* Artigo V — o denominador ao lado, sempre. E é o denominador
                        DAQUELE relatório, não o de hoje. */}
                    {period !== null && coverage !== null && (
                        <p className="mt-3 text-sm tabular-nums text-slate-600 dark:text-slate-300">
                            {period.label} · {coverage.summary}
                        </p>
                    )}

                    {generated_at !== null && (
                        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Gerado em {generated_at}
                        </p>
                    )}
                </header>

                {/* §D9 — sinaliza, nunca recalcula em silêncio. */}
                {is_stale && (
                    <Alert tone="caution" className="mt-6">
                        Este relatório foi gerado por uma versão anterior das verificações. Os
                        achados continuam válidos para o que mediam, mas vale gerar de novo.
                    </Alert>
                )}

                {!has_report && (
                    <div className="mt-10">
                        <EmptyState
                            kind="pending"
                            title="Ainda não há avaliação"
                            action={<ButtonLink href="/importar">Importar</ButtonLink>}
                        >
                            Importe um export do CareLink para que as verificações possam rodar
                            sobre os seus dados.
                        </EmptyState>
                    </div>
                )}

                {/* ⚠️ §D10 — ESTADO VAZIO DIGNO. Nenhum padrão detectado é boa
                    notícia, e precisa soar como uma. Sem achado de enchimento: a
                    pressão de mostrar algo é o que faz um produto de saúde virar
                    gerador de ansiedade. */}
                {has_report && findings.length === 0 && (
                    <div className="mt-10">
                        <EmptyState kind="settled" title="Nenhum padrão para apontar neste período">
                            <p className="text-emerald-900/80 dark:text-emerald-200/80">
                                As dez verificações rodaram e nenhuma encontrou algo que valesse
                                destacar. Isso é uma boa notícia: quer dizer que não houve
                                concentração de quedas, nem um dia dominando os números, nem
                                descompasso entre a configuração da bomba e o resultado.
                            </p>
                        </EmptyState>
                    </div>
                )}

                {/* ⚠️ ACIMA dos achados, e só quando existe. A narrativa
                    enriquece a tela; não substitui nada. Sem ela, a página é
                    exatamente a de ontem (Artigo I, §D3). */}
                {narrative !== null && <NarrativeBlock narrative={narrative} />}

                {findings.length > 0 && (
                    <section className="mt-8 space-y-4">
                        {findings.map((finding) => (
                            <FindingCard key={finding.rule_id + finding.rank} finding={finding} />
                        ))}
                    </section>
                )}

                {/* Falha de regra aparece. Esconder falha é o mesmo que não ter
                    falha — mesma decisão dos avisos da tela de importação. */}
                {rule_failures.length > 0 && (
                    <Alert
                        tone="caution"
                        title="Verificações que não puderam ser concluídas"
                        className="mt-8"
                    >
                        <ul className="space-y-1 text-xs">
                            {rule_failures.map((failure) => (
                                <li key={failure.rule_id}>
                                    {failure.rule_id}: {failure.message}
                                </li>
                            ))}
                        </ul>
                        {/* ⚠️ Falha de regra APARECE. Esconder falha é o mesmo que
                            não ter falha — mesma decisão dos avisos da importação. */}
                        <p className="mt-2 text-xs opacity-80">
                            As demais rodaram normalmente — o que aparece acima está completo para
                            elas.
                        </p>
                    </Alert>
                )}

            </AppShell>
        </>
    );
}

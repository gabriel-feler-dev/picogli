import type { ImportSummaryPayload } from '@/types';

/**
 * O que está acontecendo agora — Spec 008 §D9, T707.
 *
 * ⚠️⚠️ **NÃO EXISTE BARRA DE PROGRESSO AQUI, e a ausência é a decisão.**
 *
 * Uma barra que avança por tempo é número sem procedência na tela — o Artigo III
 * aplicado à interface. Ela pareceria informação, seria invenção, e o momento em
 * que ela chegasse a 90% e parasse destruiria mais confiança do que a espera.
 *
 * O que existe é: em que estado a importação está, e quanto isso costuma levar.
 *
 * ⚠️ **A espera é real e chega a 60 segundos.** Em produção a fila é acionada
 * por cron de um minuto (ADR-5) — hospedagem compartilhada não roda worker em
 * daemon. Sem esta tela, esse minuto parece travamento, e a pessoa reenvia o
 * arquivo.
 *
 * ⚠️ **Os estados são os QUATRO que existem** em `imports.status`. A cadeia tem
 * mais etapas (métricas, padrões, narrativa), mas elas não estão neste payload —
 * e inventá-las exigiria prop nova, o que reprova os três testes de identidade
 * (§D1). Narrar o que se sabe é melhor que narrar bonito.
 */
const ESTADOS: Record<
    ImportSummaryPayload['status'],
    { titulo: string; detalhe: string; ativo: boolean }
> = {
    pending: {
        titulo: 'Na fila',
        detalhe: 'O processamento começa em até um minuto. Pode fechar esta página — ele continua.',
        ativo: true,
    },
    processing: {
        titulo: 'Lendo o arquivo',
        detalhe: 'Cada linha do export está sendo classificada. O resumo aparece aqui ao terminar.',
        ativo: true,
    },
    done: {
        titulo: 'Importação concluída',
        detalhe: 'Os números do painel e da avaliação já consideram este arquivo.',
        ativo: false,
    },
    failed: {
        titulo: 'A importação não foi concluída',
        detalhe: 'O resumo abaixo mostra até onde a leitura chegou.',
        ativo: false,
    },
};

export function ImportProgress({ status }: { status: ImportSummaryPayload['status'] }) {
    const estado = ESTADOS[status];

    if (!estado.ativo) {
        return null;
    }

    return (
        <div
            role="status"
            aria-live="polite"
            className="flex items-start gap-3 rounded-xl border border-brand-100 bg-brand-50 px-4 py-3 dark:border-brand-500/40 dark:bg-brand-500/10"
        >
            {/* ⚠️ Indicador INDETERMINADO — gira enquanto há trabalho de fato
                acontecendo, e some quando acaba. É a exceção legítima ao "nada em
                laço" do §D3: representa espera real, não decoração. */}
            <span
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0 animate-spin rounded-full border-2 border-brand-300 border-t-brand-700 motion-reduce:animate-none dark:border-brand-500/40 dark:border-t-brand-300"
            />

            <div className="min-w-0 text-sm">
                <p className="font-medium text-brand-900 dark:text-brand-100">{estado.titulo}</p>
                <p className="mt-0.5 text-brand-900/80 dark:text-brand-100/80">{estado.detalhe}</p>
            </div>
        </div>
    );
}

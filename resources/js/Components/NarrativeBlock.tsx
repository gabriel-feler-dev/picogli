import type { NarrativePayload } from '@/types';

/**
 * O texto corrido escrito por IA, acima dos achados (FR-507, §D3).
 *
 * ⚠️ **A marcação de origem não é aviso legal — é a distinção que o produto
 * inteiro depende.** Os números vêm de regras determinísticas; o texto é uma
 * REDAÇÃO deles. O usuário precisa saber qual é qual, porque a confiança que ele
 * deposita nos dois é legitimamente diferente.
 *
 * ⚠️ Este componente pode não existir na tela, e isso é o normal. Sem narrativa,
 * a página é exatamente a de ontem: dez achados com evidência expansível. É o
 * Artigo I por construção — a narrativa ENRIQUECE, nunca substitui.
 */
export function NarrativeBlock({ narrative }: { narrative: NarrativePayload }) {
    // O texto vem em parágrafos separados por quebra de linha. Dividir aqui é
    // formatação, não cálculo — nenhum número é tocado (NFR-404).
    const paragraphs = narrative.text
        .split('\n')
        .map((paragraph) => paragraph.trim())
        .filter((paragraph) => paragraph.length > 0);

    return (
        <section
            className="mt-8 rounded-xl border border-sky-200 bg-sky-50/40 p-5 dark:border-sky-900/50 dark:bg-sky-950/20"
            aria-label="Resumo escrito por inteligência artificial"
        >
            <header className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="inline-flex items-center gap-1.5 text-sm font-semibold text-sky-900 dark:text-sky-200">
                    <span aria-hidden="true">✦</span>
                    Resumo escrito por IA
                </h2>

                {narrative.model !== null && (
                    <span className="text-xs text-sky-800/70 dark:text-sky-300/70">
                        {narrative.model}
                        {narrative.generated_at !== null && ` · ${narrative.generated_at}`}
                    </span>
                )}
            </header>

            {/* ⚠️ A frase que separa o que é calculado do que é escrito. Sem ela,
                o leitor não tem como saber que os números vêm de outro lugar. */}
            <p className="mt-1 text-xs text-sky-800/80 dark:text-sky-300/80">
                Escrito a partir dos achados abaixo. Os números são calculados pelo PicoGli;
                o texto é uma redação deles.
            </p>

            <div className="mt-4 space-y-3">
                {paragraphs.map((paragraph, index) => (
                    <p
                        key={index}
                        className="text-sm leading-relaxed text-slate-700 dark:text-slate-200"
                    >
                        {paragraph}
                    </p>
                ))}
            </div>
        </section>
    );
}

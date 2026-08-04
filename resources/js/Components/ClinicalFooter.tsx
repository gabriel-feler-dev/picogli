/**
 * Rodapé de limite clínico — Artigo VI, camada 5.
 *
 * Não é disclaimer jurídico decorativo: é a quinta das cinco camadas que
 * impedem o produto de escorregar de "descrever" para "prescrever". As outras
 * quatro estão no código (prompt de narrativa, guardrails do chat, construção da
 * regra R6, classificador de emergência) e chegam nas fases 4 a 6.
 */
export function ClinicalFooter() {
    return (
        <footer className="mt-14 border-t border-slate-200 pt-6 text-xs leading-relaxed text-slate-500 dark:border-slate-800 dark:text-slate-400">
            <p>
                O PicoGli é uma ferramenta de leitura dos seus próprios dados.{' '}
                <strong className="font-medium">
                    Não substitui avaliação médica e não recomenda mudanças de tratamento.
                </strong>{' '}
                Decisões sobre insulina são da sua equipe médica.
            </p>
        </footer>
    );
}

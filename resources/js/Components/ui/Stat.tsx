import type { ReactNode } from 'react';

/**
 * Um número com o seu denominador — Spec 008 §D4, Artigo V.
 *
 * ⚠️⚠️ **O NÚMERO E O DENOMINADOR SÃO O MESMO ELEMENTO, de propósito.**
 *
 * Não é organização de código: é o Artigo V aplicado ao tempo. Se o valor e a
 * cobertura fossem dois blocos com animação de entrada escalonada, existiria um
 * instante real — 200 ms bastam — em que a tela mostra "média 142" sem "sobre
 * 91,1% de cobertura". O artigo diz que o denominador nunca fica escondido, e
 * 200 ms é um lugar onde ele fica escondido.
 *
 * Mantendo-os num componente só, não há como animá-los separadamente sem que a
 * intenção fique explícita no código.
 *
 * ⚠️ **O valor NÃO anima** (§D3). Sem contador subindo até `83,9%`: isso
 * transforma o dado em conquista, e no mês do `61%` a mesma animação vira
 * derrota. O Artigo IV proíbe tom acusatório — o tom premiativo é o mesmo
 * defeito com o sinal trocado.
 *
 * ⚠️ **Nada aqui calcula** (NFR-404). Valor, unidade e nota chegam prontos.
 */
interface Props {
    label: string;
    value: string;
    unit?: string;
    /** O denominador: cobertura, contagem, período. */
    note?: ReactNode;
    /** Sinal que não depende de cor (§D10). */
    badge?: ReactNode;
}

export function Stat({ label, value, unit, note, badge }: Props) {
    return (
        <div className="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
            <div className="flex items-baseline justify-between gap-3">
                <p className="text-sm text-slate-600 dark:text-slate-400">{label}</p>
                {badge}
            </div>

            <p className="mt-1 text-3xl font-semibold tabular-nums">
                {value}
                {unit !== undefined && (
                    <span className="ml-1 text-base font-normal text-slate-500 dark:text-slate-400">
                        {unit}
                    </span>
                )}
            </p>

            {note !== undefined && (
                <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{note}</p>
            )}
        </div>
    );
}

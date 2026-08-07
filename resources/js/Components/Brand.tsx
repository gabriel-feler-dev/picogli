/**
 * A marca — o único lugar do front onde a menta pode aparecer (Spec 008 §D2).
 *
 * ⚠️⚠️ **Há varredura proibindo `#5DCAA5` em qualquer outro arquivo**, e este é
 * a exceção declarada. `rangePalette.target` é `#10b981` e significa "na faixa"
 * — significado clínico. A menta da marca é vizinha dela. Menta em botão ou em
 * link ensinaria dois significados para o mesmo verde, e um deles decide se a
 * pessoa acha que está bem.
 *
 * Dentro do logo isso não acontece: ali a menta é assinatura, não estado.
 *
 * Traçado extraído de `picogli_logo_dark_purple_pop_mint.svg`.
 */

const MINT = '#5DCAA5';
const DEEP = '#26215C';

export function BrandMark({ size = 28 }: { size?: number }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="60 50 160 160"
            aria-hidden="true"
            className="shrink-0"
        >
            <rect x="60" y="50" width="160" height="160" rx="36" fill={DEEP} />
            <path
                d="M140,80 C160,110 175,128 175,152 A35,35 0 1,1 105,152 C105,128 120,110 140,80 Z"
                fill="#FFFFFF"
            />
            <polyline
                points="112,158 126,150 135,132 144,155 158,148 168,155"
                fill="none"
                stroke={MINT}
                strokeWidth="5"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <circle cx="135" cy="132" r="5" fill={MINT} />
        </svg>
    );
}

export function BrandWordmark() {
    return (
        <span className="text-base font-semibold tracking-tight text-slate-900 dark:text-slate-100">
            Pico<span className="text-brand-ink dark:text-brand-300">Gli</span>
        </span>
    );
}

/** Marca completa, com a tagline do logo. Usada no login (T710). */
export function BrandLockup() {
    return (
        <div className="flex items-center gap-3">
            <BrandMark size={44} />
            <div>
                <span className="block text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">
                    Pico<span className="text-brand-ink dark:text-brand-300">Gli</span>
                </span>
                <span className="block text-sm text-ink-muted dark:text-slate-400">
                    leituras simples do seu sensor de glicemia
                </span>
            </div>
        </div>
    );
}

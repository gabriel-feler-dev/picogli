import { Link } from '@inertiajs/react';
import type { ComponentProps, ReactNode } from 'react';

/**
 * Botão — Spec 008 §D2, T702.1.
 *
 * ⚠️ A cor de ação é o **roxo da marca**, nunca a menta. `rangePalette.target`
 * é `#10b981` e significa "na faixa": um botão verde ensinaria a pessoa a ler
 * verde como aprovação, e ela levaria isso para o gráfico.
 *
 * Antes disto, `bg-sky-600` estava copiado em quatro telas com sombreamentos
 * diferentes.
 */
type Variant = 'primary' | 'secondary' | 'ghost';
type Size = 'sm' | 'md';

const BASE =
    'inline-flex items-center justify-center gap-2 rounded-lg font-medium ' +
    'transition-colors duration-150 ' +
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 ' +
    'disabled:cursor-not-allowed disabled:opacity-50';

const VARIANTS: Record<Variant, string> = {
    primary: 'bg-brand-700 text-white hover:bg-brand-500 dark:bg-brand-500 dark:hover:bg-brand-300 dark:hover:text-brand-900',
    secondary:
        'border border-slate-300 text-slate-800 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800',
    ghost: 'text-brand-700 hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-slate-800',
};

const SIZES: Record<Size, string> = {
    sm: 'px-3 py-1.5 text-sm',
    md: 'px-4 py-2.5 text-sm',
};

function classes(variant: Variant, size: Size, extra?: string): string {
    return [BASE, VARIANTS[variant], SIZES[size], extra ?? ''].join(' ').trim();
}

interface Common {
    variant?: Variant;
    size?: Size;
    children: ReactNode;
    className?: string;
}

export function Button({
    variant = 'primary',
    size = 'md',
    className,
    children,
    ...rest
}: Common & ComponentProps<'button'>) {
    return (
        <button className={classes(variant, size, className)} {...rest}>
            {children}
        </button>
    );
}

/**
 * Mesma aparência, navegando pelo Inertia.
 *
 * ⚠️ `Omit<..., 'size'>`: o `Link` é polimórfico (`as="button"`), então o tipo
 * dele já declara um `size` próprio. Sem o Omit, a interseção com o nosso
 * `'sm' | 'md'` colapsa para `never` e o erro aparece na chamada, não aqui.
 */
export function ButtonLink({
    variant = 'primary',
    size = 'md',
    className,
    children,
    ...rest
}: Common & Omit<ComponentProps<typeof Link>, 'size'>) {
    return (
        <Link className={classes(variant, size, className)} {...rest}>
            {children}
        </Link>
    );
}

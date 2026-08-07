import { useEffect, useState } from 'react';

/**
 * Troca de tema — claro, escuro, seguir o sistema (Spec 008 §D5).
 *
 * ⚠️ O que fica no `localStorage` é a PREFERÊNCIA, não o resultado. "Seguir o
 * sistema" precisa continuar seguindo depois que a pessoa fecha o app: gravar
 * "escuro" porque o sistema estava escuro congelaria a escolha dela.
 *
 * ⚠️ Este componente NÃO decide o tema na primeira pintura. Quem faz isso é o
 * script inline do `app.blade.php`, síncrono, no `<head>`. Um `useEffect` roda
 * depois da primeira pintura, e quem abre o app de madrugada para conferir uma
 * hipo levaria um flash de tela branca na cara.
 */
type Preference = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'theme';

const OPTIONS: { value: Preference; label: string }[] = [
    { value: 'light', label: 'Claro' },
    { value: 'dark', label: 'Escuro' },
    { value: 'system', label: 'Sistema' },
];

function systemPrefersDark(): boolean {
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function apply(preference: Preference): void {
    const dark = preference === 'dark' || (preference === 'system' && systemPrefersDark());

    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
}

function stored(): Preference {
    const value = localStorage.getItem(STORAGE_KEY);

    return value === 'light' || value === 'dark' ? value : 'system';
}

export function ThemeToggle() {
    // `system` como estado inicial em vez de ler o `localStorage` aqui: o
    // primeiro render precisa ser igual no servidor e no cliente.
    const [preference, setPreference] = useState<Preference>('system');

    useEffect(() => {
        setPreference(stored());
    }, []);

    // Enquanto a preferência for "sistema", mudanças do sistema operacional
    // valem em tempo real. Sem isto, o tema só acompanharia depois de recarregar.
    useEffect(() => {
        if (preference !== 'system') {
            return;
        }

        const query = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = () => apply('system');

        query.addEventListener('change', onChange);

        return () => query.removeEventListener('change', onChange);
    }, [preference]);

    const choose = (value: Preference) => {
        if (value === 'system') {
            localStorage.removeItem(STORAGE_KEY);
        } else {
            localStorage.setItem(STORAGE_KEY, value);
        }

        setPreference(value);
        apply(value);
    };

    return (
        <div
            role="radiogroup"
            aria-label="Tema"
            className="inline-flex items-center gap-0.5 rounded-lg border border-slate-200 p-0.5 dark:border-slate-800"
        >
            {OPTIONS.map((option) => {
                const active = preference === option.value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        title={option.label}
                        onClick={() => choose(option.value)}
                        className={
                            'rounded-md p-1.5 transition-colors duration-150 ' +
                            (active
                                ? 'bg-brand-100 text-brand-700 dark:bg-brand-500/25 dark:text-brand-100'
                                : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-900 dark:hover:text-slate-100')
                        }
                    >
                        <ThemeIcon value={option.value} />
                        <span className="sr-only">{option.label}</span>
                    </button>
                );
            })}
        </div>
    );
}

/**
 * Ícones em SVG inline.
 *
 * Sem biblioteca de ícones: seriam quilobytes e uma dependência nova para três
 * desenhos (NFR-807).
 */
function ThemeIcon({ value }: { value: Preference }) {
    const common = {
        width: 16,
        height: 16,
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: 2,
        strokeLinecap: 'round' as const,
        strokeLinejoin: 'round' as const,
        'aria-hidden': true,
    };

    if (value === 'light') {
        return (
            <svg {...common}>
                <circle cx="12" cy="12" r="4" />
                <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
            </svg>
        );
    }

    if (value === 'dark') {
        return (
            <svg {...common}>
                <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
            </svg>
        );
    }

    return (
        <svg {...common}>
            <rect x="2" y="4" width="20" height="14" rx="2" />
            <path d="M8 21h8M12 18v3" />
        </svg>
    );
}

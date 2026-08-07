import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { BrandMark, BrandWordmark } from '@/Components/Brand';
import { ClinicalFooter } from '@/Components/ClinicalFooter';
import { ThemeToggle } from '@/Components/ThemeToggle';

/**
 * A casca — Spec 008 §D6.
 *
 * ⚠️ **Dona única da largura.** Até 07/08/2026 `Dashboard` e `Meals` usavam
 * `max-w-4xl` e `Evaluation`, `Import` e `Comparison` usavam `max-w-3xl`: o
 * conteúdo PULAVA de largura ao navegar. Ninguém decidiu isso — foram fases
 * diferentes escrevendo a mesma linha de formas diferentes.
 *
 * ⚠️ **Dona única do rodapé clínico.** Ele é a camada 5 do Artigo VI. Repetido
 * em seis páginas, some de uma sem ninguém notar.
 *
 * ⚠️ **Ela é MOLDURA, não gabarito.** O cabeçalho de cada tela continua dentro
 * da própria página. Uniformizá-los agora exigiria reescrever seis arquivos
 * densos em decisão — e foi assim que um `Meals.tsx` reconstruído de memória
 * quase apagou o aviso de amostra pequena. As telas por dentro são T704–T709.
 *
 * ⚠️ **Nada aqui vem do servidor** (§D1). O item ativo sai de `usePage().url`,
 * que é estado do cliente. Uma prop nova — ainda que só para acender um item —
 * reprovaria os três testes de identidade de payload em cascata.
 */
export default function AppShell({ children }: { children: ReactNode }) {
    const { url } = usePage();

    return (
        <div className="flex min-h-full flex-col">
            {/* ⚠️ Atalho para o conteúdo (T711.1). Sem ele, quem navega por teclado
                passa pelos seis links do menu a cada troca de tela — em toda tela,
                para sempre. Fica invisível até receber foco. */}
            <a
                href="#conteudo"
                className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-30 focus:rounded-md focus:bg-brand-700 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white"
            >
                Pular para o conteúdo
            </a>

            <AppHeader />

            <main id="conteudo" className="flex-1">
                {/* ⚠️ `key={url}` faz a animação rodar a cada navegação; sem ela
                    o React reaproveita o nó e a entrada só acontece uma vez, na
                    primeira carga. E a classe fica no CONTAINER: o conteúdo entra
                    como um bloco só, nunca escalonado (§D4). */}
                <div
                    key={url}
                    className="picogli-enter mx-auto w-full max-w-4xl px-4 py-8 sm:px-6 sm:py-10"
                >
                    {children}

                    <ClinicalFooter />
                </div>
            </main>
        </div>
    );
}

const LINKS = [
    { href: '/dashboard', label: 'Painel' },
    { href: '/avaliacao', label: 'Avaliação' },
    { href: '/refeicoes', label: 'Refeições' },
    { href: '/comparar', label: 'Comparar' },
    { href: '/conversar', label: 'Conversar' },
    { href: '/importar', label: 'Importar' },
] as const;

/**
 * ⚠️ Duas linhas no celular, uma no desktop — e **nenhum menu escondido**.
 *
 * Um menu-sanduíche resolveria o espaço, mas esconderia destino: a pessoa
 * deixaria de saber que existe uma tela de comparação. Em vez disso, os links
 * rolam na horizontal e continuam todos visíveis (T701.5).
 */
function AppHeader() {
    const { url } = usePage();

    return (
        <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/85 backdrop-blur-sm dark:border-slate-800 dark:bg-slate-950/85">
            <div className="mx-auto w-full max-w-4xl px-4 sm:px-6">
                <div className="flex h-14 items-center justify-between gap-3">
                    <Link
                        href="/dashboard"
                        className="flex items-center gap-2 rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                    >
                        <BrandMark />
                        <BrandWordmark />
                    </Link>

                    <nav className="hidden items-center gap-0.5 md:flex" aria-label="Telas">
                        {LINKS.map((link) => (
                            <NavLink key={link.href} href={link.href} label={link.label} url={url} />
                        ))}
                    </nav>

                    <div className="flex items-center gap-2">
                        <ThemeToggle />

                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="rounded-md px-2.5 py-1.5 text-sm text-slate-500 transition-colors duration-150 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 dark:text-slate-400 dark:hover:bg-slate-900 dark:hover:text-slate-100"
                        >
                            Sair
                        </Link>
                    </div>
                </div>

                {/* Abaixo de md os links viram faixa rolável — visíveis, não escondidos. */}
                <nav
                    className="-mx-4 flex gap-0.5 overflow-x-auto px-4 pb-2 md:hidden [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                    aria-label="Telas"
                >
                    {LINKS.map((link) => (
                        <NavLink key={link.href} href={link.href} label={link.label} url={url} />
                    ))}
                </nav>
            </div>
        </header>
    );
}

function NavLink({ href, label, url }: { href: string; label: string; url: string }) {
    const active = url === href || url.startsWith(`${href}/`);

    return (
        <Link
            href={href}
            aria-current={active ? 'page' : undefined}
            className={
                'shrink-0 rounded-md px-2.5 py-1.5 text-sm whitespace-nowrap transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 ' +
                (active
                    ? 'bg-brand-100 font-medium text-brand-700 dark:bg-brand-500/25 dark:text-brand-100'
                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-900 dark:hover:text-slate-100')
            }
        >
            {label}
        </Link>
    );
}

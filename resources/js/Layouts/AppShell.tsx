import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { BrandMark, BrandWordmark } from '@/Components/Brand';
import { ClinicalFooter } from '@/Components/ClinicalFooter';
import { ThemeToggle } from '@/Components/ThemeToggle';

/**
 * A casca — Spec 008 §D6 e §V1/§V2 do `design.md`.
 *
 * ⚠️⚠️ **A coluna centrada era o defeito.** Até 07/08/2026 o conteúdo vivia num
 * `max-w-4xl` no meio da tela: num monitor de 1440 px o produto usava 62% da
 * largura e deixava duas faixas cinzas. É o que fazia parecer documentação, não
 * produto.
 *
 * Agora: menu lateral fixo de 240 px a partir de `lg`, e o conteúdo ocupa o
 * resto até 1440 px, numa grade de 12 colunas que cada tela distribui.
 *
 * ⚠️ **Texto corrido continua estreito** (§V1/T713.5). Narrativa e chat pedem
 * `max-w-prose` DENTRO da grade — linha de 1400 px é ilegível. A grade é para
 * dado, não para prosa.
 *
 * ⚠️ **Nada aqui vem do servidor** (§D1). O item ativo sai de `usePage().url`.
 * Uma prop nova — ainda que só para acender um item — reprovaria os três testes
 * de identidade de payload em cascata.
 */
const LINKS = [
    { href: '/dashboard', label: 'Painel', icon: 'M3 12h4l3 8 4-16 3 8h4' },
    { href: '/avaliacao', label: 'Avaliação', icon: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11' },
    { href: '/refeicoes', label: 'Refeições', icon: 'M3 2v7c0 1.1.9 2 2 2h1a2 2 0 0 0 2-2V2M6 11v11M18 2v20M18 2a3 3 0 0 1 3 3v6a3 3 0 0 1-3 3' },
    { href: '/comparar', label: 'Comparar', icon: 'M3 6h18M3 12h12M3 18h6' },
    { href: '/conversar', label: 'Conversar', icon: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z' },
    { href: '/importar', label: 'Importar', icon: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3' },
] as const;

export default function AppShell({ children }: { children: ReactNode }) {
    const { url } = usePage();

    return (
        <div className="min-h-full bg-slate-50 lg:flex dark:bg-slate-950">
            <a
                href="#conteudo"
                className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-40 focus:rounded-md focus:bg-brand-700 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white"
            >
                Pular para o conteúdo
            </a>

            <Sidebar url={url} />
            <TopBar url={url} />

            <div className="min-w-0 flex-1">
                <main id="conteudo">
                    {/* ⚠️ `key={url}`: sem ela o React reaproveita o nó e a entrada
                        só acontece na primeira carga. E a classe fica no CONTAINER
                        — o conteúdo entra como um bloco só, nunca escalonado (§D4). */}
                    <div
                        key={url}
                        className="picogli-enter mx-auto w-full max-w-[1440px] px-4 py-8 sm:px-6 lg:px-10 lg:py-12"
                    >
                        {children}

                        <ClinicalFooter />
                    </div>
                </main>
            </div>
        </div>
    );
}

/** Menu lateral — a partir de `lg`. Fixo, sempre visível, sem esconder destino. */
function Sidebar({ url }: { url: string }) {
    return (
        <aside className="sticky top-0 hidden h-screen w-60 shrink-0 flex-col border-r border-slate-200 bg-white px-4 py-6 lg:flex dark:border-slate-800 dark:bg-slate-900/50">
            <Link
                href="/dashboard"
                className="flex items-center gap-2.5 rounded-lg px-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
            >
                <BrandMark size={30} />
                <BrandWordmark />
            </Link>

            <nav className="mt-8 flex flex-1 flex-col gap-1" aria-label="Telas">
                {LINKS.map((link) => (
                    <SideLink key={link.href} {...link} url={url} />
                ))}
            </nav>

            <div className="flex items-center justify-between gap-2 border-t border-slate-200 pt-4 dark:border-slate-800">
                <ThemeToggle />

                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    className="rounded-lg px-2.5 py-1.5 text-sm text-slate-500 transition-colors duration-150 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100"
                >
                    Sair
                </Link>
            </div>
        </aside>
    );
}

function SideLink({
    href,
    label,
    icon,
    url,
}: {
    href: string;
    label: string;
    icon: string;
    url: string;
}) {
    const active = url === href || url.startsWith(`${href}/`);

    return (
        <Link
            href={href}
            aria-current={active ? 'page' : undefined}
            className={
                'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 ' +
                (active
                    ? 'bg-brand-100 font-medium text-brand-700 dark:bg-brand-500/20 dark:text-brand-100'
                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100')
            }
        >
            <svg
                width="17"
                height="17"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
                className="shrink-0"
            >
                <path d={icon} />
            </svg>
            {label}
        </Link>
    );
}

/**
 * Barra superior — abaixo de `lg`.
 *
 * ⚠️ **Nenhum menu-sanduíche.** Ele resolveria o espaço e esconderia destino: a
 * pessoa deixaria de saber que existe uma tela de comparação. Os links rolam na
 * horizontal e continuam todos visíveis (T701.5).
 */
function TopBar({ url }: { url: string }) {
    return (
        <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur-sm lg:hidden dark:border-slate-800 dark:bg-slate-950/90">
            <div className="flex h-14 items-center justify-between gap-3 px-4">
                <Link
                    href="/dashboard"
                    className="flex items-center gap-2 rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                >
                    <BrandMark />
                    <BrandWordmark />
                </Link>

                <div className="flex items-center gap-2">
                    <ThemeToggle />
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        className="rounded-md px-2 py-1.5 text-sm text-slate-500 dark:text-slate-400"
                    >
                        Sair
                    </Link>
                </div>
            </div>

            <nav
                className="flex gap-1 overflow-x-auto px-4 pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                aria-label="Telas"
            >
                {LINKS.map((link) => {
                    const active = url === link.href || url.startsWith(`${link.href}/`);

                    return (
                        <Link
                            key={link.href}
                            href={link.href}
                            aria-current={active ? 'page' : undefined}
                            className={
                                'shrink-0 rounded-lg px-3 py-1.5 text-sm whitespace-nowrap transition-colors duration-150 ' +
                                (active
                                    ? 'bg-brand-100 font-medium text-brand-700 dark:bg-brand-500/20 dark:text-brand-100'
                                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800')
                            }
                        >
                            {link.label}
                        </Link>
                    );
                })}
            </nav>
        </header>
    );
}

/**
 * Grade de 12 colunas (§V2).
 *
 * ⚠️ Não é "espalhar para preencher": a hierarquia do §D8 continua valendo. O
 * bloco herói ocupa mais colunas porque é o que se lê primeiro, não porque
 * sobrou espaço.
 */
export function Grid({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <div className={`grid grid-cols-1 gap-4 lg:grid-cols-12 ${className ?? ''}`}>
            {children}
        </div>
    );
}

/** Cabeçalho de tela — título, contexto e ação, na mesma linha quando cabe. */
export function PageHeader({
    title,
    subtitle,
    aside,
}: {
    title: string;
    subtitle?: ReactNode;
    aside?: ReactNode;
}) {
    return (
        <header className="mb-8 flex flex-wrap items-end justify-between gap-x-8 gap-y-3">
            <div className="min-w-0">
                <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">{title}</h1>
                {subtitle !== undefined && (
                    <div className="mt-2 text-sm text-slate-600 dark:text-slate-400">{subtitle}</div>
                )}
            </div>
            {aside !== undefined && <div className="shrink-0">{aside}</div>}
        </header>
    );
}

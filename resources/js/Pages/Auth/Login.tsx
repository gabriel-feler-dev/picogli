import { Head, useForm } from '@inertiajs/react';

import { BrandLockup } from '@/Components/Brand';
import { Button } from '@/Components/ui/Button';
import { ThemeToggle } from '@/Components/ThemeToggle';

/**
 * Login (FR-208). Sem link de cadastro — não existe registro público (§D5).
 *
 * ⚠️ A troca de tema fica disponível **antes** de entrar (Spec 008, T710.2).
 * Sem isso, quem prefere tema claro num sistema escuro leva a tela escura na
 * cara e só consegue trocar depois de autenticar.
 */
export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    return (
        <>
            <Head title="Entrar" />

            <div className="flex min-h-full flex-col">
                <div className="flex justify-end p-4">
                    <ThemeToggle />
                </div>

                <main className="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center px-6 pb-20">
                    <BrandLockup />

                <form
                    className="mt-8 space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        post('/login');
                    }}
                >
                    <div>
                        <label htmlFor="email" className="block text-sm font-medium">
                            E-mail
                        </label>
                        <input
                            id="email"
                            type="email"
                            autoComplete="username"
                            value={data.email}
                            onChange={(event) => setData('email', event.target.value)}
                            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 dark:border-slate-700 dark:bg-slate-900"
                            required
                        />
                        {errors.email !== undefined && (
                            <p className="mt-1 text-sm text-red-600 dark:text-red-400">{errors.email}</p>
                        )}
                    </div>

                    <div>
                        <label htmlFor="password" className="block text-sm font-medium">
                            Senha
                        </label>
                        <input
                            id="password"
                            type="password"
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 dark:border-slate-700 dark:bg-slate-900"
                            required
                        />
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={data.remember}
                            onChange={(event) => setData('remember', event.target.checked)}
                            className="rounded border-slate-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 dark:border-slate-700"
                        />
                        Manter conectado
                    </label>

                    <Button type="submit" disabled={processing} className="w-full">
                        {processing ? 'Entrando…' : 'Entrar'}
                    </Button>
                </form>

                    {/* Artigo VI, camada 5 — o limite clínico aparece antes mesmo
                        de entrar. Quem chega aqui por um link precisa saber o que
                        este produto é e o que ele não é. */}
                    <p className="mt-10 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                        O PicoGli é uma ferramenta de leitura dos seus próprios dados.{' '}
                        <strong className="font-medium">
                            Não substitui avaliação médica e não recomenda mudanças de tratamento.
                        </strong>
                    </p>
                </main>
            </div>
        </>
    );
}

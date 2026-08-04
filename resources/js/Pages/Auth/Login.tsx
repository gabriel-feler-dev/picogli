import { Head, useForm } from '@inertiajs/react';

/**
 * Login (FR-208). Sem link de cadastro — não existe registro público (§D5).
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

            <main className="mx-auto flex min-h-full max-w-sm flex-col justify-center px-6 py-16">
                <h1 className="text-xl font-semibold tracking-tight">PicoGli</h1>
                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Leitura dos seus próprios dados de glicemia.
                </p>

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
                            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-900"
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
                            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-900"
                            required
                        />
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={data.remember}
                            onChange={(event) => setData('remember', event.target.checked)}
                            className="rounded border-slate-300 dark:border-slate-700"
                        />
                        Manter conectado
                    </label>

                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full rounded-md bg-sky-600 px-3 py-2 font-medium text-white hover:bg-sky-700 disabled:opacity-50"
                    >
                        {processing ? 'Entrando…' : 'Entrar'}
                    </button>
                </form>
            </main>
        </>
    );
}

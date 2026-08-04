import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

import '../css/app.css';

const appName = 'PicoGli';

/**
 * Páginas resolvidas sob demanda, não com `eager: true`.
 *
 * O glob preguiçoso mantém o code splitting: cada página vira um chunk próprio
 * e só é baixada quando visitada. Com `eager`, tudo entraria no bundle
 * principal — e num dashboard com gráficos isso cresce rápido.
 *
 * ⚠️ O tipo do glob é declarado explicitamente. Sem ele, o TypeScript infere um
 * `Promise` aninhado que não casa com o `ComponentResolver` do Inertia, e o erro
 * aparece como "nenhum overload corresponde" — apontando para o `setup`, que
 * não tem nada a ver com o problema.
 */
const pages = import.meta.glob<{ default: ResolvedComponent }>('./Pages/**/*.tsx');

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),

    resolve: async (name) => {
        const importPage = pages[`./Pages/${name}.tsx`];

        if (importPage === undefined) {
            // Erro nomeado em vez de `undefined` silencioso quebrando dentro do
            // React sem dizer qual página faltou.
            throw new Error(`Página Inertia não encontrada: ${name}`);
        }

        return (await importPage()).default;
    },

    setup({ el, App, props }) {
        // `el` é garantidamente não-nulo neste overload (renderização no
        // cliente). No overload de SSR ele é `null` — e é por isso que o
        // TypeScript exige que a escolha seja explícita.
        createRoot(el).render(<App {...props} />);
    },

    progress: {
        // Cobre a navegação entre páginas. O progresso da importação de CSV é
        // outro assunto: o job roda em fila, e a tela dele é o T206.
        color: '#0ea5e9',
    },
});

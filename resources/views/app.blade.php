<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- `inertia` no title deixa o Inertia atualizar o título entre páginas
         sem recarregar o documento. --}}
    <title inertia>{{ config('app.name', 'PicoGli') }}</title>

    {{-- Tema decidido antes da primeira pintura (Spec 008 §D5).

         ⚠️ INLINE E SÍNCRONO, de propósito. Um `useEffect` do React roda depois
         da primeira pintura: quem abre o app de madrugada para conferir uma hipo
         levaria um flash de tela branca na cara.

         ⚠️ Grava em `data-theme`, não numa classe. Até 07/08/2026 este script
         adicionava `.dark` e o Tailwind 4 ignorava — a escolha da pessoa não
         tinha efeito nenhum, e o tema só seguia o sistema. Ver `app.css`.

         Três preferências: 'light', 'dark', 'system' (padrão). O que fica no
         `localStorage` é a PREFERÊNCIA; `data-theme` recebe o resultado dela. --}}
    <script>
        (function () {
            var pref = localStorage.getItem('theme') || 'system';
            var dark = pref === 'dark' || (pref === 'system' &&
                window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        })();
    </script>

    {{-- Sem Ziggy de propósito: as rotas desta fase são poucas e fixas, e
         `@routes` exigiria uma dependência que a spec não pediu. Se um dia a
         navegação crescer, aí vale reavaliar. --}}
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="h-full bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    @inertia
</body>
</html>

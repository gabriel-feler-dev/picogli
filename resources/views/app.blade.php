<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- `inertia` no title deixa o Inertia atualizar o título entre páginas
         sem recarregar o documento. --}}
    <title inertia>{{ config('app.name', 'PicoGli') }}</title>

    {{-- Tema claro/escuro decidido antes da primeira pintura, para não piscar
         branco em quem usa tema escuro. --}}
    <script>
        if (localStorage.getItem('theme') === 'dark' ||
            (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
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

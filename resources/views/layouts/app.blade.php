<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="robots" content="noindex, nofollow">

        <title>{{ config('app.name', 'Investigação Pública') }}</title>

        @include('partials.favicon')

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-ink-950 text-mist-100 antialiased">
        <div class="relative min-h-screen">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-72 aurora opacity-50" aria-hidden="true"></div>

            @include('layouts.navigation')

            @isset($header)
                <header class="relative border-b border-line/70 bg-ink-900/40 backdrop-blur">
                    <div class="mx-auto max-w-7xl px-4 py-7 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <main class="relative">
                {{ $slot }}
            </main>

            <footer class="relative mt-10 border-t border-line/70 py-6">
                <p class="mx-auto max-w-7xl px-4 text-[0.6875rem] text-mist-500 sm:px-6 lg:px-8">
                    {{ __('Fontes públicas apenas. Uma correspondência possível não é identidade confirmada.') }}
                    <a class="underline transition hover:text-mist-300" href="{{ route('legal.privacy') }}">{{ __('Privacidade') }}</a>
                </p>
            </footer>
        </div>

        @stack('scripts')
    </body>
</html>

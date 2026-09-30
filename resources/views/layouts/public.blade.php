<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="robots" content="noindex, nofollow">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen bg-gray-100">
            <header class="border-b border-gray-200 bg-white">
                <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
                    <a href="/" class="flex items-center gap-3">
                        <x-application-logo class="h-9 w-9 fill-current text-gray-500" />
                        <span class="text-sm font-semibold text-gray-700">{{ config('app.name', 'Laravel') }}</span>
                    </a>
                    <a href="{{ route('login') }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Entrar') }}</a>
                </div>
            </header>

            <div class="mx-auto max-w-4xl px-4 pt-8 sm:px-6 lg:px-8">
                @isset($header)
                    {{ $header }}
                @endisset
            </div>

            <main class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
                {{ $slot }}
            </main>

            <footer class="mx-auto max-w-4xl px-4 pb-10 text-xs text-gray-500 sm:px-6 lg:px-8">
                <p>{{ __('Correspondências são sinais públicos compatíveis e não constituem confirmação de identidade.') }}</p>
                <p class="mt-1">
                    <a href="{{ route('legal.privacy') }}" class="underline">{{ __('Política de privacidade') }}</a>
                    · <a href="{{ route('legal.terms') }}" class="underline">{{ __('Termos de uso') }}</a>
                </p>
            </footer>
        </div>

        @stack('scripts')
    </body>
</html>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="robots" content="noindex, nofollow">

        <title>{{ config('app.name', 'Investigação Pública') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-ink-950 text-mist-100 antialiased">
        <div class="relative flex min-h-screen flex-col items-center justify-center overflow-hidden px-4 py-10">
            <div class="pointer-events-none absolute inset-0 aurora opacity-60" aria-hidden="true"></div>
            <div class="pointer-events-none absolute inset-0 hud-grid opacity-40" aria-hidden="true"></div>

            <a href="{{ route('home') }}" class="relative flex items-center gap-3" aria-label="{{ config('app.name') }}">
                <x-application-logo class="h-10 w-10 text-mist-300" />
                <span class="text-base font-semibold tracking-tight text-mist-50">{{ config('app.name', 'Investigação Pública') }}</span>
            </a>

            <div class="relative mt-8 w-full overflow-hidden surface sm:max-w-md">
                <div class="p-6 sm:p-8">
                    {{ $slot }}
                </div>
            </div>

            <p class="relative mt-6 max-w-md text-center text-xs leading-relaxed text-mist-500">
                {{ __('Investigação em fontes públicas. Uma correspondência possível não é identidade confirmada.') }}
            </p>
        </div>
    </body>
</html>

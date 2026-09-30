<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Investigação Pública') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-gray-100 text-gray-900 min-h-screen flex flex-col">
        <header class="w-full bg-white shadow-sm">
            <nav class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
                <span class="text-lg font-semibold">{{ config('app.name', 'Investigação Pública') }}</span>

                <div class="flex items-center gap-4 text-sm font-medium">
                    @auth
                        <a href="{{ url('/dashboard') }}" class="rounded-md bg-gray-800 px-4 py-2 text-white hover:bg-gray-700">
                            {{ __('Painel') }}
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="text-gray-600 hover:text-gray-900">
                            {{ __('Entrar') }}
                        </a>

                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="rounded-md bg-gray-800 px-4 py-2 text-white hover:bg-gray-700">
                                {{ __('Criar conta') }}
                            </a>
                        @endif
                    @endauth
                </div>
            </nav>
        </header>

        <main class="flex-1">
            <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                <h1 class="text-3xl font-bold sm:text-4xl">{{ __('Investigação de presença digital pública') }}</h1>
                <p class="mt-4 max-w-3xl text-gray-600">
                    {{ __('Pesquise sinais públicos em fontes abertas, reunidos em um relatório com evidências e nível de confiança.') }}
                </p>

                <div class="mt-8 flex flex-wrap gap-3">
                    @auth
                        <a href="{{ route('investigations.create') }}" class="rounded-md bg-gray-800 px-5 py-2.5 text-sm font-semibold text-white hover:bg-gray-700">
                            {{ __('Nova investigação') }}
                        </a>
                    @else
                        <a href="{{ route('register') }}" class="rounded-md bg-gray-800 px-5 py-2.5 text-sm font-semibold text-white hover:bg-gray-700">
                            {{ __('Criar conta') }}
                        </a>
                        <a href="{{ route('login') }}" class="rounded-md bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50">
                            {{ __('Entrar') }}
                        </a>
                    @endauth
                </div>
            </section>

            <section class="mx-auto max-w-7xl px-4 pb-16 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
                    <div class="rounded-lg bg-white p-6 shadow-sm">
                        <h2 class="font-semibold">{{ __('Fontes públicas') }}</h2>
                        <p class="mt-2 text-sm text-gray-600">
                            {{ __('Módulos de pesquisa em fontes abertas, sem burlar autenticação, CAPTCHA ou paywall.') }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-white p-6 shadow-sm">
                        <h2 class="font-semibold">{{ __('Evidências rastreáveis') }}</h2>
                        <p class="mt-2 text-sm text-gray-600">
                            {{ __('Cada resultado aponta para a fonte e o momento em que foi observado.') }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-white p-6 shadow-sm">
                        <h2 class="font-semibold">{{ __('Créditos por uso') }}</h2>
                        <p class="mt-2 text-sm text-gray-600">
                            {{ __('Compre pacotes de créditos e use apenas os módulos que precisar.') }}
                        </p>
                    </div>
                </div>

                <p class="mt-8 max-w-3xl text-sm text-gray-500">
                    {{ __('Este serviço apresenta sinais públicos compatíveis e não constitui confirmação de identidade. Uma correspondência possível não é identidade confirmada.') }}
                </p>
            </section>
        </main>

        <footer class="border-t border-gray-200 bg-white">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-6 text-sm text-gray-500 sm:px-6 lg:px-8">
                <span>{{ config('app.name', 'Investigação Pública') }}</span>
                <div class="flex gap-4">
                    <a href="{{ route('legal.privacy') }}" class="hover:text-gray-900">{{ __('Política de Privacidade') }}</a>
                    <a href="{{ route('legal.terms') }}" class="hover:text-gray-900">{{ __('Termos de Uso') }}</a>
                </div>
            </div>
        </footer>
    </body>
</html>

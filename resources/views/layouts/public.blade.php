<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="robots" content="{{ $attributes->get('robots', 'noindex, nofollow') }}">

        <title>{{ $attributes->get('title', config('app.name', 'Investigação Pública')) }}</title>

        {{-- Head adicional da página (SEO/OG). Use @push('meta') na view chamadora;
             não passe HTML por atributo de componente: ele é escapado pelo
             compilador Blade e apareceria como texto puro na tela. --}}
        @stack('meta')

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-ink-950 text-mist-100 antialiased">
        <a href="#conteudo" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-signal-400 focus:px-4 focus:py-2 focus:text-ink-950">
            {{ __('Ir para o conteúdo') }}
        </a>

        <header class="sticky top-0 z-40 border-b border-line/80 bg-ink-950/85 backdrop-blur">
            <nav class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8" aria-label="{{ __('Navegação principal') }}">
                <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2.5">
                    <x-application-logo class="h-8 w-8 text-mist-300" />
                    <span class="text-sm font-semibold tracking-tight text-mist-50">{{ config('app.name', 'Investigação Pública') }}</span>
                </a>

                <div class="hidden items-center gap-6 text-sm text-mist-300 lg:flex">
                    <a href="{{ url('/#como-funciona') }}" class="transition hover:text-mist-50">{{ __('Como funciona') }}</a>
                    <a href="{{ url('/#o-que-investigamos') }}" class="transition hover:text-mist-50">{{ __('O que o Olho procura') }}</a>
                    <a href="{{ url('/#preco') }}" class="transition hover:text-mist-50">{{ __('Preço') }}</a>
                    <a href="{{ url('/#perguntas') }}" class="transition hover:text-mist-50">{{ __('Dúvidas') }}</a>
                </div>

                <div class="flex items-center gap-2 sm:gap-3">
                    @auth
                        <x-btn href="{{ route('dashboard') }}" variant="ghost" size="sm">{{ __('Painel') }}</x-btn>
                    @else
                        <x-btn href="{{ route('login') }}" variant="ghost" size="sm">{{ __('Entrar') }}</x-btn>
                    @endauth

                    <x-btn href="{{ route('guest.investigations.create') }}" variant="primary" size="sm">
                        {{ __('Investigar agora') }}
                    </x-btn>
                </div>
            </nav>
        </header>

        @isset($full)
            {{ $full }}
        @endisset

        @isset($header)
            <div class="border-b border-line/70 bg-ink-900/40">
                <div class="mx-auto w-full max-w-6xl px-4 py-6 text-mist-100 sm:px-6 lg:px-8">{{ $header }}</div>
            </div>
        @endisset

        <main id="conteudo" class="mx-auto w-full max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
            @if (session('status'))
                <x-alert tone="success" class="mb-6">{{ session('status') }}</x-alert>
            @endif

            @if ($errors->any())
                <x-alert tone="danger" class="mb-6">
                    <ul class="list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            @endif

            {{ $slot }}
        </main>

        <footer class="mt-8 border-t border-line/80 bg-ink-900/60">
            <div class="mx-auto grid max-w-6xl gap-8 px-4 py-10 sm:px-6 lg:grid-cols-4 lg:px-8">
                <div class="lg:col-span-2">
                    <div class="flex items-center gap-2.5">
                        <x-application-logo class="h-8 w-8 text-mist-400" />
                        <span class="text-sm font-semibold text-mist-50">{{ config('app.name', 'Olho') }}</span>
                    </div>
                    <p class="mt-3 max-w-md text-xs font-medium uppercase tracking-[0.18em] text-mist-300">
                        {{ __('Você dá a pista. O Olho procura.') }}
                    </p>
                    <p class="mt-2 max-w-md text-xs leading-relaxed text-mist-400">
                        {{ __('O Olho trabalha com informações públicas disponíveis nas fontes consultadas. Os resultados dependem da disponibilidade, visibilidade e condições de acesso de cada fonte e nunca constituem confirmação de identidade.') }}
                    </p>
                </div>

                <div>
                    <p class="eyebrow">{{ __('Plataforma') }}</p>
                    <ul class="mt-3 space-y-2 text-sm text-mist-300">
                        <li><a class="transition hover:text-mist-50" href="{{ url('/#como-funciona') }}">{{ __('Como funciona') }}</a></li>
                        <li><a class="transition hover:text-mist-50" href="{{ url('/#o-que-investigamos') }}">{{ __('O que o Olho procura') }}</a></li>
                        <li><a class="transition hover:text-mist-50" href="{{ url('/#preco') }}">{{ __('Preço') }}</a></li>
                        <li><a class="transition hover:text-mist-50" href="{{ route('guest.investigations.create') }}">{{ __('Investigar agora') }}</a></li>
                    </ul>
                </div>

                <div>
                    <p class="eyebrow">{{ __('Legal') }}</p>
                    <ul class="mt-3 space-y-2 text-sm text-mist-300">
                        <li><a class="transition hover:text-mist-50" href="{{ route('legal.privacy') }}">{{ __('Privacidade') }}</a></li>
                        <li><a class="transition hover:text-mist-50" href="{{ route('legal.terms') }}">{{ __('Termos de uso') }}</a></li>
                        <li><a class="transition hover:text-mist-50" href="{{ route('login') }}">{{ __('Entrar') }}</a></li>
                    </ul>
                </div>
            </div>

            <div class="border-t border-line/60 py-4">
                <p class="mx-auto max-w-6xl px-4 text-[0.6875rem] text-mist-500 sm:px-6 lg:px-8">
                    © {{ now()->year }} {{ config('app.name', 'Investigação Pública') }} ·
                    {{ __('Fontes públicas apenas: não burlamos autenticação, CAPTCHA ou paywall e não acessamos conteúdo privado.') }}
                </p>
            </div>
        </footer>

        @stack('scripts')
    </body>
</html>

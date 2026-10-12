<nav x-data="{ open: false }" class="relative z-40 border-b border-line/70 bg-ink-950/85 backdrop-blur">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between gap-4">
            <div class="flex items-center gap-8">
                <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-2.5">
                    <x-application-logo class="h-8 w-8 text-mist-300" />
                    <span class="text-sm font-semibold tracking-tight text-mist-50">{{ config('app.name', 'Investigação Pública') }}</span>
                </a>

                <div class="hidden items-center gap-6 lg:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">{{ __('Painel') }}</x-nav-link>
                    <x-nav-link :href="route('investigations.index')" :active="request()->routeIs('investigations.*')">{{ __('Investigações') }}</x-nav-link>
                    <x-nav-link :href="route('investigations.create')" :active="request()->routeIs('investigations.create')">{{ __('Nova investigação') }}</x-nav-link>
                    <x-nav-link :href="route('payments.index')" :active="request()->routeIs('payments.*')">{{ __('Créditos') }}</x-nav-link>
                    <x-nav-link :href="route('profile.edit')" :active="request()->routeIs('profile.*')">{{ __('Conta') }}</x-nav-link>
                </div>
            </div>

            <div class="hidden items-center gap-3 lg:flex">
                <x-btn href="{{ route('investigations.create') }}" variant="primary" size="sm">{{ __('Investigar') }}</x-btn>

                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button type="button" class="flex items-center gap-2 rounded-lg border border-line bg-ink-900/70 px-3 py-2 text-sm font-medium text-mist-200 transition hover:border-signal-500/50 hover:text-mist-50">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-signal-600/30 font-mono text-[0.625rem] font-semibold text-signal-300">
                                {{ mb_strtoupper(mb_substr(Auth::user()->name, 0, 1)) }}
                            </span>
                            <span class="max-w-[10rem] truncate">{{ Auth::user()->name }}</span>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">{{ __('Perfil e segurança') }}</x-dropdown-link>
                        <x-dropdown-link :href="route('account.privacy')">{{ __('Privacidade e dados') }}</x-dropdown-link>
                        <x-dropdown-link :href="route('account.export')">{{ __('Exportar meus dados') }}</x-dropdown-link>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                                {{ __('Sair') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <div class="-me-2 flex items-center gap-2 lg:hidden">
                <x-btn href="{{ route('investigations.create') }}" variant="primary" size="sm">{{ __('Investigar') }}</x-btn>

                <button @click="open = ! open" type="button"
                    class="inline-flex items-center justify-center rounded-lg border border-line p-2 text-mist-300 transition hover:text-mist-50"
                    :aria-expanded="open ? 'true' : 'false'" aria-controls="menu-mobile">
                    <span class="sr-only">{{ __('Abrir menu') }}</span>
                    <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <path :class="{ 'hidden': open, 'inline-flex': ! open }" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{ 'hidden': ! open, 'inline-flex': open }" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div id="menu-mobile" :class="{ 'block': open, 'hidden': ! open }" class="hidden border-t border-line/70 bg-ink-900/95 lg:hidden">
        <div class="space-y-1 px-4 py-3">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">{{ __('Painel') }}</x-responsive-nav-link>
            <x-responsive-nav-link :href="route('investigations.index')" :active="request()->routeIs('investigations.*')">{{ __('Investigações') }}</x-responsive-nav-link>
            <x-responsive-nav-link :href="route('payments.index')" :active="request()->routeIs('payments.*')">{{ __('Créditos') }}</x-responsive-nav-link>
            <x-responsive-nav-link :href="route('profile.edit')" :active="request()->routeIs('profile.*')">{{ __('Conta') }}</x-responsive-nav-link>
        </div>

        <div class="border-t border-line/70 px-4 py-4">
            <p class="text-sm font-medium text-mist-100">{{ Auth::user()->name }}</p>
            <p class="text-xs text-mist-500">{{ Auth::user()->email }}</p>

            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <x-btn type="submit" variant="outline" size="sm" class="w-full">{{ __('Sair') }}</x-btn>
            </form>
        </div>
    </div>
</nav>

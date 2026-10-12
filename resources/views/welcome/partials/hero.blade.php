@php
    /*
     * HERO — foco em investigação de redes sociais / pegada digital.
     *
     * Título de impacto + formulário de busca rápida (username, nome, e-mail,
     * telefone) que aponta para a rota existente de disparo da avulsa
     * (`guest.investigations.store`). Nenhuma rota de backend é alterada aqui.
     * A assinatura de marca "VOCÊ DÁ A PISTA. / O OLHO PROCURA." é preservada.
     */
    $socialIcons = ['instagram', 'twitter', 'reddit', 'tiktok', 'linkedin', 'spotify'];
@endphp

<section class="relative overflow-hidden border-b border-line/70">
    <div class="pointer-events-none absolute inset-0 aurora" aria-hidden="true"></div>
    <div class="pointer-events-none absolute inset-0 hud-grid opacity-50" aria-hidden="true"></div>

    <div class="relative mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24 lg:px-8">
        <div class="grid items-center gap-12 lg:grid-cols-[1.05fr_0.95fr]">
            <div class="animate-rise">
                <p class="eyebrow">{{ __('OLHO · investigação de presença digital') }}</p>

                <h1 class="mt-4 text-4xl font-semibold leading-[1.05] tracking-tight text-mist-50 sm:text-5xl lg:text-6xl">
                    {{ __('Descubra a pegada digital e os perfis em') }}
                    <span class="text-gradient">{{ __('redes sociais') }}</span>
                    {{ __('em segundos.') }}
                </h1>

                <p class="mt-3 font-mono text-sm uppercase tracking-[0.18em] text-signal-300">
                    {{ __('VOCÊ DÁ A PISTA.') }} <span class="text-mist-400">{{ __('O OLHO PROCURA.') }}</span>
                </p>

                <p class="mt-5 max-w-xl text-base leading-relaxed text-mist-300 sm:text-lg">
                    {{ __('Um username, um nome, um e-mail. O Olho vasculha dezenas de redes e serviços públicos — Instagram, X, Reddit, TikTok, LinkedIn, Spotify e mais — e devolve os perfis, bios e conexões encontrados, com origem e evidências.') }}
                </p>

                {{-- Formulário de busca rápida: conecta na rota existente da avulsa. --}}
                <form method="POST" action="{{ route('guest.investigations.store') }}"
                      class="surface mt-7 p-4 sm:p-5">
                    @csrf

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="hero-username" class="field-label">{{ __('Username ou @') }}</label>
                            <input id="hero-username" name="username" type="text" value="{{ old('username') }}" autocomplete="off"
                                   class="field" placeholder="ex.: joao.silva">
                            @error('username')
                                <x-input-error :messages="$errors->get('username')" />
                            @enderror
                        </div>

                        <div>
                            <label for="hero-name" class="field-label">{{ __('Nome') }}</label>
                            <input id="hero-name" name="name" type="text" value="{{ old('name') }}" autocomplete="off"
                                   class="field" placeholder="ex.: João da Silva">
                        </div>

                        <div>
                            <label for="hero-email" class="field-label">{{ __('E-mail') }}</label>
                            <input id="hero-email" name="email" type="email" value="{{ old('email') }}" autocomplete="off"
                                   class="field" placeholder="ex.: contato@exemplo.com">
                        </div>

                        <div class="sm:col-span-2">
                            <label for="hero-phone" class="field-label">{{ __('Telefone') }}</label>
                            <input id="hero-phone" name="phone" type="text" value="{{ old('phone') }}" autocomplete="off"
                                   class="field" placeholder="ex.: +55 11 90000-0000">
                            @error('payment')
                                <x-input-error :messages="$errors->get('payment')" />
                            @enderror
                        </div>
                    </div>

                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3 text-mist-400">
                            @foreach ($socialIcons as $icon)
                                <span class="transition hover:text-signal-300" title="{{ __($icon) }}">
                                    <x-social-icon :name="$icon" />
                                </span>
                            @endforeach
                            <span class="ml-1 text-xs text-mist-500">{{ __('e dezenas de outras fontes') }}</span>
                        </div>

                        <x-btn type="submit" variant="primary" size="lg" class="w-full sm:w-auto">
                            {{ __('INVESTIGAR AGORA') }}
                        </x-btn>
                    </div>

                    <p class="mt-3 text-xs leading-relaxed text-mist-500">
                        {{ __('Informe ao menos um identificador. Você só paga após revisar — a consulta roda após a confirmação.') }}
                    </p>
                </form>

                <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
                    @auth
                        <x-btn href="{{ route('dashboard') }}" variant="outline" class="w-full sm:w-auto">{{ __('Ir para o painel') }}</x-btn>
                    @else
                        <x-btn href="{{ route('login') }}" variant="outline" class="w-full sm:w-auto">{{ __('Já possui conta? Entrar') }}</x-btn>
                        <x-btn href="{{ route('register') }}" variant="ghost" class="w-full sm:w-auto">{{ __('Criar conta') }}</x-btn>
                    @endauth
                </div>

                @if ($guestPrice !== null)
                    <p class="mt-4 text-sm text-mist-400">
                        {{ __('Investigação avulsa por') }}
                        <span class="font-semibold text-mist-100">{{ __('R$') }} {{ number_format($guestPrice / 100, 2, ',', '.') }}</span>
                        {{ __('· pagamento único · sem cadastro obrigatório') }}
                    </p>
                @endif

                <ul class="mt-6 flex flex-wrap gap-x-6 gap-y-2 text-xs text-mist-400">
                    <li class="flex items-center gap-2"><span class="text-signal-300" aria-hidden="true">◆</span>{{ __('Pagamento único, sem assinatura') }}</li>
                    <li class="flex items-center gap-2"><span class="text-signal-300" aria-hidden="true">◆</span>{{ __('Investigação em fontes públicas') }}</li>
                    <li class="flex items-center gap-2"><span class="text-signal-300" aria-hidden="true">◆</span>{{ __('Resultado protegido por acesso exclusivo') }}</li>
                </ul>
            </div>

            {{-- Preview mockado do relatório enriquecido (demonstração fictícia). --}}
            @include('welcome.partials.demo-report')
        </div>
    </div>
</section>


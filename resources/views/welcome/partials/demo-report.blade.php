@php
    /*
     * DEMONSTRAÇÃO VISUAL — preview de como fica o relatório enriquecido.
     *
     * 100% fictício: avatares SVG gerados localmente (nenhuma pessoa real),
     * handles e bios de exemplo. O objetivo é tangibilizar a promessa de valor,
     * não afirmar uma descoberta. Um selo "exemplo" deixa isso explícito.
     */
    $networks = [
        'instagram' => 'Instagram',
        'twitter' => 'X / Twitter',
        'reddit' => 'Reddit',
        'tiktok' => 'TikTok',
        'linkedin' => 'LinkedIn',
        'spotify' => 'Spotify',
    ];

    $demoProfiles = [
        [
            'seed' => 'demo-ana',
            'handle' => 'ana.vermelho',
            'name' => 'Ana Vermelho',
            'location' => 'São Paulo, BR',
            'bio' => 'Engenheira de Software • Entusiasta de Fotografia',
            'found' => ['instagram', 'twitter', 'reddit', 'spotify'],
            'confidence' => 0.86,
        ],
        [
            'seed' => 'demo-marco',
            'handle' => 'marco_santos',
            'name' => 'Marco Santos',
            'location' => 'Lisboa, PT',
            'bio' => 'Designer de produto • Corredor amador ☕',
            'found' => ['instagram', 'linkedin', 'tiktok'],
            'confidence' => 0.74,
        ],
    ];

    $trustBadges = [
        __('Enriquecimento de perfil em tempo real'),
        __('Coleta somente de fontes públicas'),
        __('Relatório unificado'),
    ];
@endphp

<div class="relative min-w-0 animate-rise" style="animation-delay: 120ms">
    <div class="surface relative overflow-hidden p-5 sm:p-6">
        <div class="flex items-center justify-between gap-3">
            <p class="eyebrow">{{ __('Exemplo de relatório') }}</p>
            <x-badge tone="violet">{{ __('demonstração') }}</x-badge>
        </div>

        <p class="mt-2 text-sm leading-relaxed text-mist-400">
            {{ __('Assim fica um perfil depois que o Olho conecta os sinais entre as redes.') }}
        </p>

        <div class="mt-5 flex flex-wrap gap-2">
            @foreach ($trustBadges as $badge)
                {{-- `whitespace-normal`: a classe `.badge` é nowrap; em telas
                     estreitas o texto precisa quebrar em vez de alargar o card. --}}
                <x-badge tone="signal" class="whitespace-normal">
                    <span class="inline-flex items-center gap-1.5">
                        <span aria-hidden="true">✓</span>{{ $badge }}
                    </span>
                </x-badge>
            @endforeach
        </div>

        {{-- Cartões de perfil enriquecido (fictícios). --}}
        <div class="mt-5 space-y-4">
            @foreach ($demoProfiles as $profile)
                <div class="surface-2 p-4">
                    <div class="flex items-start gap-4">
                        <x-demo-avatar :seed="$profile['seed']" :name="$profile['name']" class="h-16 w-16 shrink-0 rounded-xl" />

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="truncate font-medium text-mist-100">{{ $profile['name'] }}</p>
                                <span class="font-mono text-[0.6875rem] text-mist-500">
                                    {{ __('confiança') }} {{ number_format($profile['confidence'], 2, ',', '.') }}
                                </span>
                            </div>
                            <p class="font-mono text-xs text-signal-300">&#64;{{ $profile['handle'] }}</p>
                            <p class="mt-0.5 text-xs text-mist-500">{{ $profile['location'] }}</p>
                            <p class="mt-2 text-sm leading-relaxed text-mist-300">{{ $profile['bio'] }}</p>

                            {{-- Badges das redes onde o perfil foi encontrado. --}}
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($profile['found'] as $network)
                                    <span class="inline-flex items-center gap-1.5 rounded-full border border-line bg-ink-800/70 px-2.5 py-1 text-[0.6875rem] font-medium text-mist-300">
                                        <x-social-icon :name="$network" class="h-3.5 w-3.5 text-signal-300" />
                                        {{ $networks[$network] ?? $network }}
                                        <span class="text-signal-300" aria-hidden="true">✓</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Amostra de evidência + sinal de correlação. --}}
        <div class="mt-5 rounded-xl border border-line bg-ink-950/60 p-4">
            {{-- Linha com quebra: o badge longo era o principal responsável por
                 estourar a largura do grid no mobile (`.badge` é nowrap). --}}
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-[0.6875rem] font-semibold uppercase tracking-wider text-mist-500">{{ __('Sinal de correlação') }}</p>
                <x-badge tone="success" class="whitespace-normal">{{ __('correspondência forte por sinais públicos') }}</x-badge>
            </div>

            <ul class="mt-3 space-y-2 text-xs text-mist-400">
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 text-signal-300" aria-hidden="true">▸</span>
                    <span>{{ __('Username idêntico confirmado em') }} <strong class="text-mist-200">4</strong> {{ __('redes independentes') }}</span>
                </li>
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 text-signal-300" aria-hidden="true">▸</span>
                    <span>{{ __('Bio e nome exibido coerentes entre Instagram e LinkedIn') }}</span>
                </li>
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 text-signal-300" aria-hidden="true">▸</span>
                    <span>{{ __('Avatar público registrado como evidência visual') }}</span>
                </li>
            </ul>

            <div class="mt-4 h-px w-full bg-gradient-to-r from-transparent via-signal-500/50 to-transparent"></div>

            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 font-mono text-[0.625rem] text-mist-500">
                <span>{{ __('evidência') }}: username_match</span>
                <span>{{ __('fonte') }}: instagram · reddit · x</span>
                <span>{{ __('observado em') }} {{ now()->format('d/m/Y H:i') }}</span>
            </div>
        </div>

        <p class="mt-4 text-[0.6875rem] leading-relaxed text-mist-500">
            {{ __('Conteúdo ilustrativo e fictício. Uma correspondência possível não é identidade confirmada: o relatório real apresenta apenas sinais públicos observados, com origem e limitações.') }}
        </p>

        <x-btn href="{{ route('guest.investigations.create') }}" variant="outline" size="sm" class="mt-4">
            {{ __('VER EXEMPLO DE RELATÓRIO') }}
        </x-btn>
    </div>
</div>


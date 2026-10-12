@php
    /**
     * Cartão de um resultado individual de uma fonte.
     *
     * Tratamento visual consistente para qualquer fonte e um tratamento
     * diferenciado para perfis enriquecidos (Instagram/instagrapi): foto pública
     * (evidência `avatar` em HTTPS), nome e bio. A ausência de dados é dita com
     * clareza — nunca preenchemos com informação fictícia.
     *
     * @var \App\Models\SourceResult $result
     * @var string $sourceSlug
     */
    $avatar = $result->evidence->first(
        fn ($evidence) => $evidence->type === 'avatar' && str_starts_with(strtolower((string) $evidence->value), 'https://')
    );
    $chips = $result->evidence->reject(fn ($evidence) => $avatar !== null && $avatar->is($evidence));
    $isEnriched = $sourceSlug === 'instagram';
    $confidence = $result->confidence !== null ? (float) $result->confidence : null;
@endphp

<article class="surface-2 p-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            @if ($avatar)
                <a href="{{ $avatar->value }}" target="_blank" rel="noopener noreferrer"
                   title="{{ __('Abrir imagem em nova aba') }}" class="shrink-0">
                    <img src="{{ $avatar->value }}"
                         alt="{{ __('Imagem pública do perfil') }} {{ $result->value }}"
                         loading="lazy" referrerpolicy="no-referrer"
                         class="h-14 w-14 rounded-lg border border-line/40 bg-ink-700/40 object-cover" />
                </a>
            @elseif ($isEnriched)
                <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg border border-dashed border-line text-mist-500"
                      title="{{ __('Imagem pública não encontrada') }}" aria-hidden="true">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.25a7.5 7.5 0 0 1 15 0" />
                    </svg>
                </span>
            @endif

            <div class="min-w-0">
                <p class="truncate font-medium text-mist-100">
                    @if ($isEnriched)
                        {{ $result->title ?: $result->value }}
                    @else
                        {{ $result->title ?: $result->value }}
                    @endif
                </p>
                <p class="mt-0.5 font-mono text-[0.6875rem] text-mist-500">
                    {{ $result->metadata['platform'] ?? $sourceSlug }}
                    @if ($confidence !== null)
                        · {{ __('confiança') }} {{ number_format($confidence, 2, ',', '.') }}
                    @endif
                </p>
            </div>
        </div>

        <x-badge :tone="$confidence !== null && $confidence >= 0.7 ? 'success' : 'signal'">
            {{ $isEnriched ? __('perfil enriquecido') : __('correspondência') }}
        </x-badge>
    </div>

    @if ($result->description)
        <p class="mt-3 text-sm leading-relaxed text-mist-300">{{ $result->description }}</p>
    @elseif ($isEnriched)
        <p class="mt-3 text-sm text-mist-500">{{ __('Bio pública não disponível.') }}</p>
    @endif

    @if ($result->url)
        <a href="{{ $result->url }}" target="_blank" rel="noopener noreferrer nofollow"
           class="mt-3 block break-all text-sm text-signal-300 underline decoration-signal-500/40 hover:text-signal-300">
            {{ $result->url }}
        </a>
    @endif

    @if ($chips->isNotEmpty())
        <div class="mt-3 flex flex-wrap gap-1.5">
            @foreach ($chips as $evidence)
                <span class="rounded-md bg-ink-700/60 px-2 py-0.5 font-mono text-[0.6875rem] text-mist-300">
                    {{ $evidence->type }}: {{ $evidence->value }}
                </span>
            @endforeach
        </div>
    @endif

    <p class="mt-3 font-mono text-[0.625rem] text-mist-500">
        {{ __('observado em') }} {{ $result->observed_at?->format('d/m/Y H:i') ?? '—' }}
    </p>
</article>

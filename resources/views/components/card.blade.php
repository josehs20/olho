@props([
    'title' => null,
    'subtitle' => null,
    'eyebrow' => null,
    'padding' => 'p-6',
    'lift' => false,
])

<section {{ $attributes->merge(['class' => trim('surface '.$padding.($lift ? ' lift' : ''))]) }}>
    @if ($title || $eyebrow || isset($actions))
        <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                @if ($eyebrow)
                    <p class="eyebrow">{{ $eyebrow }}</p>
                @endif

                @if ($title)
                    <h3 class="mt-1 text-lg font-semibold text-mist-50">{{ $title }}</h3>
                @endif

                @if ($subtitle)
                    <p class="mt-1 text-sm text-mist-400">{{ $subtitle }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    {{ $slot }}
</section>

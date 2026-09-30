@props(['tone' => 'info', 'title' => null])

@php
    $toneClass = match ($tone) {
        'success' => 'border-emerald-800/60 bg-emerald-950/40 text-emerald-100',
        'warning' => 'border-amber-800/60 bg-amber-950/30 text-amber-100',
        'danger' => 'border-rose-900/60 bg-rose-950/40 text-rose-100',
        default => 'border-line bg-ink-850/70 text-mist-200',
    };
@endphp

<div role="status" {{ $attributes->merge(['class' => 'rounded-xl border px-4 py-3 text-sm '.$toneClass]) }}>
    @if ($title)
        <p class="font-semibold">{{ $title }}</p>
    @endif

    <div class="leading-relaxed">{{ $slot }}</div>
</div>

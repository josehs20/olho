@props(['label', 'value', 'hint' => null, 'accent' => false])

<div {{ $attributes->merge(['class' => 'surface-2 p-5']) }}>
    <p class="text-[0.6875rem] font-semibold uppercase tracking-wider text-mist-500">{{ $label }}</p>
    <p class="mt-2 text-3xl font-semibold tabular-nums {{ $accent ? 'text-signal-300' : 'text-mist-50' }}">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-mist-400">{{ $hint }}</p>
    @endif
</div>

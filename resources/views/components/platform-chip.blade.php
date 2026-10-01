@props(['name', 'covered' => true, 'note' => null, 'hook' => null])

<li {{ $attributes->merge(['class' => 'flex items-center justify-between gap-3 rounded-xl border border-line bg-ink-900/60 px-4 py-3']) }}>
    <span>
        <span class="text-sm font-medium text-mist-100">{{ $name }}</span>
        @if ($hook !== null)
            <span class="mt-0.5 block text-xs leading-relaxed text-mist-500">{{ $hook }}</span>
        @endif
    </span>

    @if ($covered)
        <x-badge tone="signal" :title="$note">{{ __('coberta') }}</x-badge>
    @else
        <x-badge :title="$note">{{ __('fora do escopo') }}</x-badge>
    @endif
</li>

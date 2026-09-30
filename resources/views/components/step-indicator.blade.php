@props(['steps' => [], 'current' => 0])

<ol {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-3 gap-y-2']) }} aria-label="{{ __('Etapas') }}">
    @foreach ($steps as $index => $label)
        @php
            $state = $index < $current ? 'done' : ($index === $current ? 'active' : 'todo');
        @endphp

        <li class="flex items-center gap-2">
            <span @class([
                'flex h-6 w-6 items-center justify-center rounded-full border font-mono text-[0.625rem] font-semibold',
                'border-signal-500/60 bg-signal-600/25 text-signal-300' => $state === 'done',
                'animate-pulse-soft border-signal-400 bg-signal-400 text-ink-950' => $state === 'active',
                'border-line text-mist-500' => $state === 'todo',
            ]) aria-hidden="true">
                {{ $state === 'done' ? '✓' : $index + 1 }}
            </span>

            <span @class([
                'text-xs font-medium',
                'text-mist-50' => $state === 'active',
                'text-mist-300' => $state === 'done',
                'text-mist-500' => $state === 'todo',
            ])>{{ $label }}</span>

            @unless ($loop->last)
                <span class="hidden h-px w-6 bg-line sm:block" aria-hidden="true"></span>
            @endunless
        </li>
    @endforeach
</ol>

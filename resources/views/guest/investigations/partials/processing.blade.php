<x-card eyebrow="{{ __('Investigação') }}" :title="$processingTitle">
    <ul class="space-y-3">
        @foreach ($stages as $index => $stage)
            @php
                $state = $stage['done'] ? 'done' : ($firstPending === $index ? 'active' : 'todo');
                $glyph = $state === 'done' ? '✓' : ($state === 'active' ? '◉' : '◌');
            @endphp

            <li class="flex items-center gap-3 text-sm">
                <span class="flex h-6 w-6 items-center justify-center rounded-full border font-mono text-[0.625rem] {{ $state === 'done' ? 'border-signal-500/60 bg-signal-600/25 text-signal-300' : ($state === 'active' ? 'animate-pulse-soft border-signal-400 bg-signal-400 text-ink-950' : 'border-line text-mist-500') }}" aria-hidden="true">{{ $glyph }}</span>
                <span class="text-xs {{ $state === 'active' ? 'font-medium text-mist-50' : ($state === 'done' ? 'text-mist-300' : 'text-mist-500') }}">{{ $stage['label'] }}</span>
            </li>
        @endforeach
    </ul>

    @if ($runError !== null)
        <x-alert tone="warning" class="mt-5">{{ $runError }}</x-alert>
    @endif

    @if ($run === null)
        <p class="mt-5 text-xs leading-relaxed text-mist-500">{{ __('As etapas refletem o estado real registrado pela plataforma e começam após a confirmação do pagamento.') }}</p>
    @else
        <p class="mt-5 font-mono text-[0.6875rem] text-mist-500">{{ __('run') }} #{{ $run['id'] }} · {{ __((string) $run['status']) }}</p>
    @endif
</x-card>

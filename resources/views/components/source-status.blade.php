@props(['run'])

@php
    $label = $run->sourceDefinition?->name ?? $run->source ?? '—';

    $tone = match ($run->status) {
        'completed' => 'success',
        'failed', 'timeout' => 'danger',
        'pending', 'running' => 'signal',
        default => 'neutral',
    };

    $results = (int) ($run->result_count ?? 0);
@endphp

<div {{ $attributes->merge(['class' => 'surface-2 flex flex-wrap items-center justify-between gap-3 p-4']) }}>
    <div class="min-w-0">
        <p class="truncate font-medium text-mist-100">{{ $label }}</p>
        <p class="mt-0.5 font-mono text-[0.6875rem] text-mist-500">
            @if ($run->tool_version)
                v{{ $run->tool_version }} ·
            @endif
            {{ trans_choice(':count tentativa|:count tentativas', (int) $run->attempts, ['count' => (int) $run->attempts]) }}
            @if ($run->duration_ms !== null)
                · {{ number_format($run->duration_ms / 1000, 2, ',', '.') }} s
            @endif
        </p>
    </div>

    <div class="flex items-center gap-2">
        <span class="font-mono text-xs text-mist-400">{{ $results }} {{ __('resultado(s)') }}</span>
        <x-badge :tone="$tone">
            {{ method_exists($run, 'displayStatus') ? $run->displayStatus() : __((string) $run->status) }}
        </x-badge>
    </div>
</div>

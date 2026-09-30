<ul class="grid gap-3 sm:grid-cols-2">
    @foreach ($discoveries as $index => $discovery)
        <li class="surface-2 p-4" style="animation: rise 0.5s cubic-bezier(0.22, 1, 0.36, 1) {{ $index * 60 }}ms both">
            <div class="flex items-center justify-between gap-2">
                <x-badge tone="signal">{{ $discovery['platform'] ?? $discovery['source'] }}</x-badge>

                @if ($discovery['confidence'] !== null)
                    <span class="font-mono text-[0.625rem] text-mist-500">{{ __('confiança') }} {{ number_format((float) $discovery['confidence'], 2, ',', '.') }}</span>
                @endif
            </div>

            <p class="mt-3 truncate font-medium text-mist-100">{{ $discovery['title'] ?: $discovery['value'] }}</p>

            @if ($discovery['url'])
                <a href="{{ $discovery['url'] }}" target="_blank" rel="noopener noreferrer nofollow" class="mt-2 block truncate text-xs text-signal-300 underline decoration-signal-500/40">{{ $discovery['url'] }}</a>
            @endif

            @if ($discovery['observed_at'])
                <p class="mt-2 font-mono text-[0.625rem] text-mist-500">{{ __('observado em') }} {{ \Illuminate\Support\Carbon::parse($discovery['observed_at'])->format('d/m/Y H:i') }}</p>
            @endif
        </li>
    @endforeach
</ul>

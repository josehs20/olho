<x-card padding="p-5">
    <p class="eyebrow">{{ __('O que está incluído') }}</p>
    <ul class="mt-3 space-y-2 text-xs text-mist-300">
        @foreach ([
            __('Todas as fontes aplicáveis ao identificador'),
            __('Evidências e origem de cada descoberta'),
            __('Limitações, falhas e timeouts visíveis'),
            __('Aviso de que correspondência não é identidade'),
        ] as $item)
            <li class="flex items-start gap-2">
                <span class="mt-0.5 text-signal-300" aria-hidden="true">◆</span>
                <span>{{ $item }}</span>
            </li>
        @endforeach
    </ul>
</x-card>

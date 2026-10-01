<x-card eyebrow="{{ __('Descobertas') }}" :title="$discoveriesTitle" subtitle="{{ __('Somente dados retornados pelas fontes. Nada é simulado ou preenchido artificialmente.') }}">
    @if ($visibleDiscoveries === [])
        @if ($reportAvailable)
            <x-empty-state
                title="{{ __('O Olho não encontrou ocorrências nas fontes consultadas.') }}"
                description="{{ __('Isso não significa ausência da pessoa ou da conta: depende do que existe publicamente para este identificador. O relatório detalha cada fonte consultada e suas limitações.') }}" />
        @else
            <x-empty-state
                title="{{ __('A busca continua...') }}"
                description="{{ __('Assim que uma fonte pública retornar um perfil, site ou username, ele aparece aqui com a origem e o momento da observação.') }}" />
        @endif
    @else
        @include('guest.investigations.partials.discovery-list', ['discoveries' => $visibleDiscoveries])

        @if ($hiddenCount > 0)
            <p class="mt-3 text-xs text-mist-500">{{ __(':shown de :total descobertas exibidas — o restante está no relatório.', ['shown' => count($visibleDiscoveries), 'total' => $totalDiscoveries]) }}</p>
        @endif
    @endif

    @if ($reportAvailable)
        <div class="mt-6">
            <x-btn href="{{ $reportUrl }}" variant="primary">{{ __('Abrir relatório completo') }}</x-btn>
        </div>
    @endif
</x-card>

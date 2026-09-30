<x-card eyebrow="{{ __('Fontes') }}" title="{{ __('Estado por fonte') }}">
    @if ($sourceRuns->isEmpty())
        <x-skeleton :lines="3" />
        <p class="mt-3 text-xs text-mist-500">{{ __('As fontes aparecem aqui assim que a execução começar (após o pagamento confirmado).') }}</p>
    @else
        <div class="space-y-3">
            @foreach ($sourceRuns as $sourceRun)
                <x-source-status :run="$sourceRun" />
            @endforeach
        </div>
    @endif
</x-card>

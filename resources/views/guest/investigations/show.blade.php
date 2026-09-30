@php
    $paymentStatus = $payment?->status;
    $isPaid = $paymentStatus === 'paid';
    $isExpired = $investigation->status === 'expired';

    $step = match (true) {
        (bool) $progress['report_available'] => 4,
        $isPaid => 3,
        default => 2,
    };

    $stages = [
        ['label' => __('Preparando fontes'), 'done' => $progress['run'] !== null],
        ['label' => __('Consultando usernames'), 'done' => $progress['sources'] !== []],
        ['label' => __('Verificando plataformas'), 'done' => $progress['completed_sources'] > 0],
        ['label' => __('Consolidando descobertas'), 'done' => $progress['discoveries_total'] > 0],
        ['label' => __('Correlacionando evidências'), 'done' => (bool) $progress['correlated']],
        ['label' => __('Gerando relatório'), 'done' => (bool) $progress['report_available']],
    ];

    $firstPending = null;

    foreach ($stages as $index => $stage) {
        if (! $stage['done']) {
            $firstPending = $index;
            break;
        }
    }

    $run = $progress['run'];
    $runError = $run['error_summary'] ?? null;
    $processingTitle = $run === null ? __('Aguardando liberação') : __('INVESTIGAÇÃO EM ANDAMENTO');

    $sourceRuns = $investigation->currentRun?->sourceRuns ?? collect();

    $discoveries = $progress['discoveries'];
    $visibleDiscoveries = array_slice($discoveries, 0, 12);
    $totalDiscoveries = count($discoveries);
    $hiddenCount = max(0, $totalDiscoveries - count($visibleDiscoveries));

    $discoveriesTitle = $progress['discoveries_total'] > 0
        ? trans_choice(':count descoberta real|:count descobertas reais', $progress['discoveries_total'], ['count' => $progress['discoveries_total']])
        : __('Ainda sem descobertas');

    $reportAvailable = (bool) $progress['report_available'];
    $accessUrl = request()->url();
@endphp

<x-public-layout :title="__('Acompanhamento da investigação') . ' — ' . $investigation->title">
    <x-slot name="header">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="eyebrow">{{ __('Investigação avulsa') }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-mist-50 sm:text-3xl">{{ $investigation->title }}</h1>
                <p class="mt-2 text-sm text-mist-400">{{ __('Guarde este endereço: ele é a credencial de acesso a este resultado.') }}</p>
            </div>

            <div class="flex flex-col items-start gap-2 sm:items-end">
                <x-status-badge :status="$investigation->status" />
                <p class="font-mono text-[0.6875rem] text-mist-500">{{ __('criada em') }} {{ $investigation->created_at?->format('d/m/Y H:i') }}</p>
            </div>
        </div>

        <x-step-indicator class="mt-6" :steps="[__('Identificador'), __('Revisão'), __('Pagamento'), __('Investigação'), __('Resultado')]" :current="$step" />
    </x-slot>

    @if (session('status'))
        <x-alert tone="success" class="mb-6">{{ session('status') }}</x-alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-[1.35fr_0.65fr]">
        <div class="space-y-6">
            @include('guest.investigations.partials.payment')
            @include('guest.investigations.partials.processing')
            @include('guest.investigations.partials.sources')
            @include('guest.investigations.partials.discoveries')
        </div>

        <div class="space-y-4">
            @include('guest.investigations.partials.access')
            @include('guest.investigations.partials.included')
        </div>
    </div>

    @include('guest.investigations.partials.scripts')
</x-public-layout>

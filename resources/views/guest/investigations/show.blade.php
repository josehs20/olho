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
        ['label' => __('O Olho está iniciando a busca...'), 'done' => $progress['run'] !== null],
        ['label' => __('Verificando usernames...'), 'done' => $progress['sources'] !== []],
        ['label' => __('Consultando fontes públicas...'), 'done' => $progress['completed_sources'] > 0],
        ['label' => __('Organizando sinais encontrados...'), 'done' => $progress['discoveries_total'] > 0],
        ['label' => __('Conectando pistas entre fontes...'), 'done' => (bool) $progress['correlated']],
        ['label' => __('Montando sua investigação...'), 'done' => (bool) $progress['report_available']],
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

    // Título do processamento derivado do estado real (prompt 44): nunca
    // anuncia descoberta que ainda não existe nem finge execução não iniciada.
    $processingTitle = match (true) {
        (bool) $progress['report_available'] => $progress['discoveries_total'] > 0
            ? __('O QUE O OLHO ENCONTROU')
            : __('INVESTIGAÇÃO CONCLUÍDA'),
        ! $isPaid || $run === null => __('O Olho está esperando a sua pista'),
        default => __('O OLHO ESTÁ PROCURANDO...'),
    };

    $sourceRuns = $investigation->currentRun?->sourceRuns ?? collect();

    $discoveries = $progress['discoveries'];
    $visibleDiscoveries = array_slice($discoveries, 0, 12);
    $totalDiscoveries = count($discoveries);
    $hiddenCount = max(0, $totalDiscoveries - count($visibleDiscoveries));

    // Revelação condicionada ao estado real: só anuncia descoberta existente.
    $discoveriesTitle = match (true) {
        $progress['discoveries_total'] > 0 => trans_choice(
            'O Olho encontrou :count descoberta|O Olho encontrou :count descobertas',
            $progress['discoveries_total'],
            ['count' => $progress['discoveries_total']],
        ),
        (bool) $progress['report_available'] => __('O Olho não encontrou ocorrências'),
        default => __('O Olho ainda está procurando'),
    };

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

    @if (session('warning'))
        <x-alert tone="warning" class="mb-6">{{ session('warning') }}</x-alert>
    @endif

    @if (session('error'))
        <x-alert tone="danger" class="mb-6">{{ session('error') }}</x-alert>
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

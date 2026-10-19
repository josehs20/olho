@props(['status' => null, 'kind' => 'investigation'])

@php
    $value = is_object($status) ? (string) ($status->value ?? $status->status ?? '') : (string) $status;

    /* Rótulos derivados do estado REAL persistido (nenhum estado inventado). */
    $map = [
        'draft' => ['warning', 'aguardando pagamento'],
        'queued' => ['violet', 'na fila'],
        'running' => ['signal', 'em andamento'],
        'pending' => ['violet', 'aguardando'],
        'partial' => ['warning', 'concluída parcialmente'],
        'completed' => ['success', 'concluída'],
        'failed' => ['danger', 'falhou'],
        'cancelled' => ['danger', 'cancelada'],
        'expired' => ['danger', 'expirada'],
        'skipped' => ['neutral', 'não pesquisado'],
        'timeout' => ['danger', 'tempo esgotado'],
        'awaiting_payment' => ['warning', 'aguardando pagamento'],
        'awaiting_manual_confirmation' => ['warning', 'em conferência'],
        'paid' => ['success', 'pago'],
        'refunded' => ['violet', 'estornado'],
    ];

    [$tone, $label] = $map[$value] ?? ['neutral', $value !== '' ? $value : '—'];
@endphp

<x-badge :tone="$tone" {{ $attributes }}>{{ $label }}</x-badge>

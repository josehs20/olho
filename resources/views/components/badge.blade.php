@props(['tone' => 'neutral'])

@php
    $toneClass = match ($tone) {
        'signal' => 'badge-signal',
        'success', 'green' => 'badge-success',
        'warning', 'amber' => 'badge-warning',
        'danger', 'red' => 'badge-danger',
        'violet', 'info' => 'badge-violet',
        default => '',
    };
@endphp

<span {{ $attributes->merge(['class' => trim('badge '.$toneClass)]) }}>{{ $slot }}</span>

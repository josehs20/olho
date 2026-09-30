@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
])

@php
    $variantClass = match ($variant) {
        'primary', 'signal' => 'btn-primary',
        'outline', 'secondary' => 'btn-outline',
        'ghost' => 'btn-ghost',
        'danger' => 'btn-danger',
        default => 'btn-primary',
    };

    $sizeClass = match ($size) {
        'sm' => 'btn-sm',
        'lg' => 'btn-lg',
        default => '',
    };

    $classes = trim('btn '.$variantClass.' '.$sizeClass.' '.($attributes->get('class') ?? ''));
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->except('class')->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->except('class')->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif

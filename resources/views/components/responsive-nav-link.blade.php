@props(['active'])

@php
    $classes = ($active ?? false)
        ? 'block w-full border-l-4 border-signal-400 bg-ink-800/70 py-2 pe-4 ps-3 text-start text-base font-medium text-mist-50 transition duration-150 ease-in-out focus:outline-none'
        : 'block w-full border-l-4 border-transparent py-2 pe-4 ps-3 text-start text-base font-medium text-mist-400 transition duration-150 ease-in-out hover:border-line hover:bg-ink-800/60 hover:text-mist-100 focus:outline-none';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>

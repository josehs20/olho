@props(['active'])

@php
    $classes = ($active ?? false)
        ? 'inline-flex items-center border-b-2 border-signal-400 px-1 pt-1 text-sm font-medium leading-5 text-mist-50 transition duration-150 ease-in-out focus:outline-none'
        : 'inline-flex items-center border-b-2 border-transparent px-1 pt-1 text-sm font-medium leading-5 text-mist-400 transition duration-150 ease-in-out hover:border-line hover:text-mist-100 focus:outline-none focus:text-mist-100';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>

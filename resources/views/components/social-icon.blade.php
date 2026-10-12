@props(['name'])

@php
    /*
     * Ícone inline de uma rede/serviço (SVG, sem request de rede e sem pessoa
     * real). Usado na hero e nos cartões de demonstração. `currentColor` segue a
     * cor do contexto. Nomes desconhecidos não renderizam nada.
     */
    $paths = [
        // Instagram — corpo arredondado + lente + flash.
        'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor"/>',
        // X / Twitter — o "X" estilizado.
        'twitter' => '<path fill="currentColor" d="M17.53 3H20l-5.4 6.17L21 21h-5.9l-3.35-4.38L7.9 21H5.42l5.78-6.6L4.5 3h5.9l3.13 4.14L17.53 3Zm-1.04 16.2h1.64L8.6 4.7H6.86l9.63 14.5Z"/>',
        // Reddit — cabeça do mascote + antena + olhos.
        'reddit' => '<path fill="currentColor" d="M12 3.2c1.2 0 2.2 1 2.2 2.2v1.6c1.9.3 3.4 1.9 3.6 3.9.7-.2 1.4-.1 2 .3.9.6 1.3 1.7 1 2.7-.2.7-.7 1.2-1.3 1.5-.3 2-2 3.7-4.2 4.2l-.8 1.6c-.2.4-.6.6-1 .6h-3c-.4 0-.8-.2-1-.6l-.8-1.6c-2.2-.5-3.9-2.2-4.2-4.2-.6-.3-1.1-.8-1.3-1.5-.3-1 .1-2.1 1-2.7.6-.4 1.3-.5 2-.3.2-2 1.7-3.6 3.6-3.9V5.4c0-1.2 1-2.2 2.2-2.2Zm-3 8.3a1.1 1.1 0 1 0 0 2.2 1.1 1.1 0 0 0 0-2.2Zm6 0a1.1 1.1 0 1 0 0 2.2 1.1 1.1 0 0 0 0-2.2Zm-3.1 3.2c-.9 0-1.7.5-2.1 1.2-.2.4 0 .8.4.9.9.3 1.9.3 2.8 0 .4-.1.6-.5.4-.9-.4-.7-1.1-1.2-2-1.2h.5Z"/>',
        // TikTok — nota musical estilizada.
        'tiktok' => '<path fill="currentColor" d="M16.5 3h-2.7v11.2a2.3 2.3 0 1 1-2.3-2.3c.3 0 .5 0 .8.1V9.2a5 5 0 1 0 4.2 4.9V8.4c.9.7 2 1.1 3.2 1.2V7a4.3 4.3 0 0 1-3.2-1.5V3Z"/>',
        // LinkedIn — o "in".
        'linkedin' => '<path fill="currentColor" d="M6.94 8.5h-2v10h2v-10ZM5.94 4.2a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4ZM9.2 8.5h1.9v1.4c.5-.9 1.6-1.6 2.9-1.6 2.2 0 3.1 1.4 3.1 3.7v6.5h-2v-5.9c0-1.3-.5-2-1.6-2-1 0-1.7.7-1.9 1.5v6.4h-2v-10Z"/>',
        // Spotify — três arcos.
        'spotify' => '<path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm4.6 14.4a.9.9 0 0 1-1.2.3c-2.4-1.5-5.5-1.8-9-1a.9.9 0 1 1-.4-1.7c3.9-.9 7.4-.5 10.2 1.2.4.2.5.8.4 1.2Zm1.2-2.9a1.1 1.1 0 0 1-1.5.4c-2.7-1.7-6.9-2.2-10-1.2a1.1 1.1 0 1 1-.6-2.1c3.6-1.1 8.2-.6 11.3 1.4.5.3.7 1 .8 1.5Zm.1-3a1.3 1.3 0 0 1-1.8.5C13 8.9 8.2 8.7 5.2 9.7a1.3 1.3 0 1 1-.7-2.5c3.5-1.1 8.8-.9 12.3 1.2.6.4.9 1.1.8 1.9l-.1.2Z"/>',
    ];
    $svg = $paths[$name] ?? null;
@endphp

@if ($svg)
    <svg {{ $attributes->merge(['class' => 'h-5 w-5']) }} viewBox="0 0 24 24" fill="none" aria-hidden="true">{!! $svg !!}</svg>
@endif

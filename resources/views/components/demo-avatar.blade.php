@props(['seed' => 'olho', 'name' => null, 'class' => 'h-16 w-16'])

@php
    /*
     * Avatar de demonstração, 100% fictício e gerado localmente (SVG inline):
     * nenhuma imagem de pessoa real, nenhum request de rede, nenhum dado de
     * terceiro. O gradiente e as iniciais são derivados de um `seed` estável,
     * então o mesmo perfil de exemplo sempre mostra o mesmo "rosto" abstrato.
     */
    $palettes = [
        ['#14b49c', '#6d5efc'],
        ['#6ff2da', '#2fdcc0'],
        ['#6d5efc', '#b16dff'],
        ['#f5c05e', '#ff8a5c'],
        ['#2fdcc0', '#3b82f6'],
        ['#ff8a5c', '#e5484d'],
    ];
    $index = abs(crc32($seed)) % count($palettes);
    [$from, $to] = $palettes[$index];
    $gradientId = 'avatar-'.abs(crc32($seed));

    $initials = '';
    if ($name !== null && trim($name) !== '') {
        $parts = preg_split('/\s+/', trim($name));
        $initials = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[count($parts) - 1] ?? '', 0, 1));
    }
@endphp

<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 64 64" role="img"
     aria-label="{{ $name !== null ? $name : __('Avatar de demonstração') }}">
    <defs>
        <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="{{ $from }}" />
            <stop offset="100%" stop-color="{{ $to }}" />
        </linearGradient>
        <clipPath id="{{ $gradientId }}-clip"><circle cx="32" cy="32" r="32" /></clipPath>
    </defs>

    <g clip-path="url(#{{ $gradientId }}-clip)">
        <rect width="64" height="64" fill="url(#{{ $gradientId }})" />
        {{-- Silhueta abstrata (cabeça + ombros): legível como "perfil", sem rosto real. --}}
        <circle cx="32" cy="26" r="11" fill="rgba(5, 8, 15, 0.28)" />
        <path d="M8 64c0-13.3 10.7-22 24-22s24 8.7 24 22v0H8Z" fill="rgba(5, 8, 15, 0.28)" />
        @if ($initials !== '')
            <text x="32" y="58" text-anchor="middle" font-family="ui-sans-serif, system-ui, sans-serif"
                  font-size="15" font-weight="700" fill="rgba(5, 8, 15, 0.55)">{{ $initials }}</text>
        @endif
    </g>
    <circle cx="32" cy="32" r="31" fill="none" stroke="rgba(255,255,255,0.14)" stroke-width="2" />
</svg>

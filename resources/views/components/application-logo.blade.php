{{-- Marca do produto "Olho": símbolo de investigação (lente/olho que observa). --}}
<svg {{ $attributes->merge(['viewBox' => '0 0 48 48', 'fill' => 'none', 'aria-hidden' => 'true', 'role' => 'img']) }}>
    <defs>
        <linearGradient id="brand-signal" x1="4" y1="4" x2="44" y2="44" gradientUnits="userSpaceOnUse">
            <stop stop-color="#6ff2da" />
            <stop offset="1" stop-color="#14b49c" />
        </linearGradient>
    </defs>
    <circle cx="24" cy="24" r="19.5" stroke="url(#brand-signal)" stroke-width="2.2" opacity="0.6" />
    <circle cx="24" cy="24" r="12" stroke="currentColor" stroke-width="1.8" opacity="0.9" />
    <circle cx="24" cy="24" r="4.4" fill="url(#brand-signal)" />
    <path d="M24 1.8V7.4M24 40.6v5.6M1.8 24h5.6M40.6 24h5.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" opacity="0.75" />
</svg>

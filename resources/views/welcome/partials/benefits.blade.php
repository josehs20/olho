@php
    /*
     * ARGUMENTOS DE CONVERSÃO — três blocos que traduzem a promessa em
     * benefício concreto: mapeamento multiplataforma, enriquecimento visual e
     * relatórios prontos para auditoria.
     */
    $benefits = [
        [
            'icon' => 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z',
            'title' => __('Mapeamento multiplataforma'),
            'text' => __('Busca simultânea em dezenas de redes sociais e serviços a partir de um único identificador. Um username pode existir em muitos lugares — o Olho verifica todos de uma vez.'),
        ],
        [
            'icon' => 'M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z',
            'title' => __('Enriquecimento visual'),
            'text' => __('Coleta de avatares públicos, nomes exibidos, bios e links em um só lugar — transforma uma lista de URLs em perfis que você reconhece.'),
        ],
        [
            'icon' => 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z',
            'title' => __('Relatórios prontos para auditoria'),
            'text' => __('Organização automática de evidências com timestamps, fontes e limitações. Cada descoberta tem origem rastreável e o que não foi encontrado também fica explícito.'),
        ],
    ];
@endphp

<section class="mt-20">
    <x-section-heading
        eyebrow="{{ __('Por que o Olho') }}"
        title="{{ __('O que você recebe') }}"
        description="{{ __('Não é uma lista solta de links: é um mapa organizado da presença digital, com evidência em cada ponto.') }}" />

    <div class="mt-6 grid gap-4 md:grid-cols-3">
        @foreach ($benefits as $benefit)
            <x-card padding="p-6" class="h-full lift">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl border border-signal-500/40 bg-signal-600/15 text-signal-300">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $benefit['icon'] }}" />
                    </svg>
                </span>
                <h3 class="mt-4 font-semibold text-mist-50">{{ $benefit['title'] }}</h3>
                <p class="mt-2 text-sm leading-relaxed text-mist-400">{{ $benefit['text'] }}</p>
            </x-card>
        @endforeach
    </div>
</section>

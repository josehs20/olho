@php
    $guestPrice = $guestPackage?->price_cents;
    $coveredCount = collect($platforms)->where('covered', true)->count();

    $metaTags = '';
    $metaTags .= '<meta name="description" content="'.e('Investigação de presença digital em fontes públicas: usernames, e-mails, sites e perfis. Investigue sem cadastro, com pagamento único via PIX e acesso por token.').'">';
    $metaTags .= '<meta property="og:title" content="'.e(config('app.name', 'Investigação Pública')).'">';
    $metaTags .= '<meta property="og:description" content="'.e('Descubra onde uma identidade digital aparece em fontes públicas. Resultados com evidências e limitações, sem acesso a conteúdo privado.').'">';
    $metaTags .= '<meta property="og:type" content="website">';
@endphp

<x-public-layout robots="index, follow" :meta="$metaTags" :title="config('app.name', 'Investigação Pública').' — investigação de presença digital'">
    <x-slot name="full">
        <section class="relative overflow-hidden border-b border-line/70">
            <div class="pointer-events-none absolute inset-0 aurora" aria-hidden="true"></div>
            <div class="pointer-events-none absolute inset-0 hud-grid opacity-50" aria-hidden="true"></div>

            <div class="relative mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24 lg:px-8">
                <div class="grid items-center gap-12 lg:grid-cols-[1.1fr_0.9fr]">
                    <div class="animate-rise">
                        <p class="eyebrow">{{ __('Inteligência digital · fontes públicas') }}</p>

                        <h1 class="mt-4 text-4xl font-semibold leading-[1.05] tracking-tight text-mist-50 sm:text-5xl lg:text-6xl">
                            {{ __('INVESTIGUE UMA') }}<br />
                            <span class="text-gradient">{{ __('PRESENÇA DIGITAL') }}</span>
                        </h1>

                        <p class="mt-6 max-w-xl text-base leading-relaxed text-mist-300 sm:text-lg">
                            {{ __('Descubra onde uma identidade digital aparece: cruzamos fontes públicas para reunir perfis, usernames, sites e menções relacionadas ao identificador pesquisado — com evidências, origem e limitações explícitas.') }}
                        </p>

                        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
                            <x-btn href="{{ route('guest.investigations.create') }}" variant="primary" size="lg" class="w-full sm:w-auto">
                                {{ __('INICIAR INVESTIGAÇÃO') }}
                            </x-btn>

                            @auth
                                <x-btn href="{{ route('dashboard') }}" variant="outline" size="lg" class="w-full sm:w-auto">{{ __('Ir para o painel') }}</x-btn>
                            @else
                                <x-btn href="{{ route('login') }}" variant="outline" size="lg" class="w-full sm:w-auto">{{ __('Já possui conta? Entrar') }}</x-btn>
                            @endauth
                        </div>

                        @if ($guestPrice !== null)
                            <p class="mt-4 text-sm text-mist-400">
                                {{ __('Investigação avulsa por') }}
                                <span class="font-semibold text-mist-100">{{ __('R$') }} {{ number_format($guestPrice / 100, 2, ',', '.') }}</span>
                                {{ __('· pagamento único · sem cadastro obrigatório') }}
                            </p>
                        @endif

                        <ul class="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-xs text-mist-400">
                            <li class="flex items-center gap-2"><span class="text-signal-300" aria-hidden="true">◆</span>{{ __('Pagamento único, sem assinatura') }}</li>
                            <li class="flex items-center gap-2"><span class="text-signal-300" aria-hidden="true">◆</span>{{ __('Investigação em fontes públicas') }}</li>
                            <li class="flex items-center gap-2"><span class="text-signal-300" aria-hidden="true">◆</span>{{ __('Resultado protegido por acesso exclusivo') }}</li>
                        </ul>
                    </div>

                    <div class="relative animate-rise" style="animation-delay: 120ms">
                        <div class="surface relative overflow-hidden p-6">
                            <div class="flex items-center justify-between">
                                <p class="eyebrow">{{ __('Execução de investigação') }}</p>
                                <x-badge tone="signal">{{ __('fontes públicas') }}</x-badge>
                            </div>

                            <div class="mt-5 space-y-3">
                                @foreach ([
                                    [__('Preparando fontes'), 100],
                                    [__('Consultando usernames'), 180],
                                    [__('Verificando plataformas'), 260],
                                    [__('Consolidando descobertas'), 340],
                                    [__('Correlacionando evidências'), 420],
                                    [__('Gerando relatório'), 500],
                                ] as [$step, $delay])
                                    <div class="flex items-center gap-3 text-sm text-mist-300" style="animation: rise 0.6s cubic-bezier(0.22, 1, 0.36, 1) {{ $delay }}ms both">
                                        <span class="h-1.5 w-1.5 rounded-full bg-signal-400 animate-pulse-soft" aria-hidden="true"></span>
                                        {{ $step }}
                                    </div>
                                @endforeach
                            </div>

                            <div class="mt-6 h-px w-full bg-gradient-to-r from-transparent via-signal-500/60 to-transparent"></div>

                            <p class="mt-4 text-xs leading-relaxed text-mist-500">
                                {{ __('Ilustração do acompanhamento: durante a execução, cada etapa e cada fonte exibem o estado real registrado pela plataforma.') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </x-slot>

    <section id="o-que-investigamos" class="scroll-mt-24">
        <x-section-heading
            eyebrow="{{ __('Categorias populares') }}"
            title="{{ __('O QUE PODE APARECER NA INVESTIGAÇÃO?') }}"
            description="{{ __('As fontes ativas consultam catálogos públicos de plataformas. Ter uma plataforma coberta não significa que ela será encontrada: depende do que existir publicamente para o identificador pesquisado e do escopo configurado de cada fonte.') }}" />

        <div class="mt-6 grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
            <ul class="grid gap-3 sm:grid-cols-2">
                @foreach ($platforms as $platform)
                    <x-platform-chip
                        :name="$platform['name']"
                        :covered="(bool) ($platform['covered'] ?? false)"
                        :note="$platform['note'] ?? null" />
                @endforeach
            </ul>

            <x-card eyebrow="{{ __('Descoberta') }}" title="{{ __('Enquanto a investigação roda') }}" class="h-full">
                <ul class="space-y-3 text-sm text-mist-300">
                    @foreach ([
                        __('Buscando presença digital...'),
                        __('Encontrando possíveis perfis...'),
                        __('Comparando fontes...'),
                        __('Consolidando descobertas...'),
                    ] as $index => $phrase)
                        <li class="flex items-center gap-3" style="animation: pulse-soft 2.4s ease-in-out {{ $index * 220 }}ms infinite">
                            <span class="font-mono text-xs text-signal-300" aria-hidden="true">{{ sprintf('%02d', $index + 1) }}</span>
                            {{ $phrase }}
                        </li>
                    @endforeach
                </ul>

                <p class="mt-5 rounded-xl border border-line bg-ink-950/60 p-3 text-xs leading-relaxed text-mist-500">
                    {{ __('Apenas dados reais retornados pelas fontes aparecem no resultado. Nada é simulado, inventado ou apresentado como descoberta quando não ocorreu.') }}
                </p>
            </x-card>
        </div>

        <p class="mt-4 text-xs text-mist-500">
            {{ trans_choice(
                '{0}Nenhuma plataforma catalogada.|:count plataforma(s) e categorias cobertas pelas fontes ativas.',
                $coveredCount,
                ['count' => $coveredCount],
            ) }}
            {{ __('WhatsApp aparece como fora do escopo porque mensagens privadas nunca são acessadas.') }}
        </p>
    </section>

    <section id="como-funciona" class="mt-20 scroll-mt-24">
        <x-section-heading
            eyebrow="{{ __('Fluxo') }}"
            title="{{ __('Como funciona') }}"
            description="{{ __('Quatro passos, sem burocracia: informe o identificador, pague uma vez e acompanhe a investigação pelo próprio link de acesso.') }}" />

        <ol class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['1', __('Identificador'), __('Informe username, e-mail, telefone, nome ou cidade — apenas o que for público.')],
                ['2', __('Pagamento único'), __('Preço fixo via PIX. Sem assinatura, sem créditos obrigatórios e sem cadastro.')],
                ['3', __('Investigação'), __('As fontes públicas são consultadas em paralelo e cada etapa fica visível em tempo real.')],
                ['4', __('Relatório'), __('Descobertas com origem, data da observação, evidências e limitações — acessíveis pelo seu link.')],
            ] as [$number, $title, $text])
                <li class="surface lift p-5">
                    <span class="font-mono text-xs text-signal-300">{{ $number }}</span>
                    <h3 class="mt-2 font-semibold text-mist-50">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-mist-400">{{ $text }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    <section id="preco" class="mt-20 scroll-mt-24">
        <x-section-heading
            eyebrow="{{ __('Preço') }}"
            title="{{ __('INVESTIGAÇÃO AVULSA') }}"
            description="{{ __('Preço fixo, definido pela plataforma e cobrado uma única vez. Sem assinatura, sem cadastro obrigatório e sem consumo de créditos na avulsa.') }}" />

        <div class="mt-6 grid gap-4 lg:grid-cols-[0.9fr_1.1fr]">
            <x-card class="border-signal-500/40" padding="p-7">
                <div class="flex items-center justify-between">
                    <p class="eyebrow">{{ __('Consulta avulsa') }}</p>
                    <x-badge tone="signal">{{ __('sem cadastro') }}</x-badge>
                </div>

                <p class="mt-4 text-4xl font-semibold tabular-nums text-mist-50">
                    @if ($guestPrice !== null)
                        {{ __('R$') }} {{ number_format($guestPrice / 100, 2, ',', '.') }}
                    @else
                        —
                    @endif
                </p>

                <p class="mt-1 text-sm text-mist-400">{{ __('Pagamento único via PIX') }}</p>

                <ul class="mt-6 space-y-3 text-sm text-mist-300">
                    @foreach ([
                        __('Sem assinatura e sem mensalidade'),
                        __('Sem cadastro obrigatório'),
                        __('Acesso ao resultado por link exclusivo'),
                        __('Todas as fontes aplicáveis incluídas'),
                        __('Limitações e falhas exibidas com transparência'),
                    ] as $item)
                        <li class="flex items-start gap-2.5">
                            <span class="mt-0.5 text-signal-300" aria-hidden="true">✓</span>
                            <span>{{ $item }}</span>
                        </li>
                    @endforeach
                </ul>

                <x-btn href="{{ route('guest.investigations.create') }}" variant="primary" size="lg" class="mt-7 w-full">
                    {{ __('INVESTIGAR AGORA') }}
                </x-btn>

                <p class="mt-3 text-center text-xs text-mist-500">{{ __('O pagamento é confirmado antes de qualquer consulta ser executada.') }}</p>
            </x-card>

            <x-card eyebrow="{{ __('Para quem investiga com frequência') }}" title="{{ __('Pacotes de créditos') }}" subtitle="{{ __('Com uma conta você mantém histórico, reexecuta investigações e compra créditos por pacote.') }}">
                @if ($packages->isEmpty())
                    <x-empty-state title="{{ __('Nenhum pacote ativo no momento') }}" description="{{ __('A consulta avulsa continua disponível.') }}" />
                @else
                    <ul class="divide-y divide-line/70">
                        @foreach ($packages as $package)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-4">
                                <div>
                                    <p class="font-medium text-mist-100">{{ $package->name }}</p>
                                    <p class="mt-0.5 font-mono text-xs text-mist-500">{{ $package->credits }} {{ __('créditos') }}</p>
                                </div>
                                <p class="font-semibold tabular-nums text-mist-100">
                                    {{ __('R$') }} {{ number_format($package->price_cents / 100, 2, ',', '.') }}
                                </p>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-5 flex flex-wrap gap-3">
                        @auth
                            <x-btn href="{{ route('payments.index') }}" variant="outline">{{ __('Comprar créditos') }}</x-btn>
                        @else
                            <x-btn href="{{ route('register') }}" variant="outline">{{ __('Criar conta') }}</x-btn>
                            <x-btn href="{{ route('login') }}" variant="ghost">{{ __('Entrar') }}</x-btn>
                        @endauth
                    </div>
                @endif
            </x-card>
        </div>
    </section>

    <section class="mt-20">
        <x-section-heading
            eyebrow="{{ __('Resultado') }}"
            title="{{ __('O que vem no relatório') }}"
            description="{{ __('A estrutura abaixo é fixa; os números, fontes e descobertas vêm sempre da execução real da sua investigação.') }}" />

        <div class="mt-6 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                [__('Resumo da execução'), __('Fontes consultadas, descobertas, perfis e websites — apenas contagens reais.')],
                [__('Fontes e versões'), __('Cada fonte com status, versão da ferramenta, tentativas e duração.')],
                [__('Descobertas com origem'), __('Plataforma, identificador, URL, data da observação e evidência associada.')],
                [__('Limitações visíveis'), __('Falhas, timeouts e fontes sem resultado aparecem explicitamente, sem maquiagem.')],
            ] as [$title, $text])
                <x-card padding="p-5" :title="$title" class="h-full">
                    <p class="text-sm leading-relaxed text-mist-400">{{ $text }}</p>
                </x-card>
            @endforeach
        </div>

        <x-alert tone="info" class="mt-4">
            {{ __('Uma correspondência possível não é identidade confirmada: o relatório apresenta sinais públicos observados e a correlação é um indicador interno, nunca uma prova de que a mesma pessoa controla as contas.') }}
        </x-alert>
    </section>

    <section id="perguntas" class="mt-20 scroll-mt-24">
        <x-section-heading eyebrow="{{ __('Dúvidas') }}" title="{{ __('Perguntas frequentes') }}" />

        <div class="mt-6 space-y-3">
            @foreach ([
                [__('Preciso criar conta?'), __('Não. A investigação avulsa é feita sem cadastro: você informa o identificador, paga e acompanha pelo link exclusivo de acesso.')],
                [__('Como recebo o resultado?'), __('A investigação roda após a confirmação do pagamento e o acompanhamento fica disponível no link de acesso, com o relatório completo ao final. Guarde o link: ele é a credencial do resultado.')],
                [__('E se o pagamento não for confirmado?'), __('Nenhuma consulta é executada sem pagamento confirmado. O pedido expira e a investigação não é realizada.')],
                [__('Os resultados provam algo sobre alguém?'), __('Não. A plataforma reúne sinais públicos compatíveis e indica as limitações de cada fonte. Correspondência possível não é confirmação de identidade.')],
                [__('A plataforma acessa conteúdo privado?'), __('Não. Consultamos apenas fontes públicas e não burlamos autenticação, CAPTCHA ou paywall. Mensagens privadas nunca são acessadas.')],
                [__('E quando uma fonte falha?'), __('A investigação continua com as demais e o relatório mostra exatamente quais fontes concluíram, quais não retornaram resultados e quais falharam ou deram timeout.')],
            ] as [$question, $answer])
                <details class="surface group p-5">
                    <summary class="flex cursor-pointer items-center justify-between gap-4 text-sm font-medium text-mist-100 marker:content-none">
                        {{ $question }}
                        <span class="text-signal-300 transition group-open:rotate-45" aria-hidden="true">+</span>
                    </summary>
                    <p class="mt-3 text-sm leading-relaxed text-mist-400">{{ $answer }}</p>
                </details>
            @endforeach
        </div>
    </section>

    <section class="mt-20">
        <div class="surface relative overflow-hidden p-8 text-center sm:p-12">
            <div class="pointer-events-none absolute inset-0 aurora opacity-70" aria-hidden="true"></div>

            <div class="relative">
                <p class="eyebrow">{{ __('Comece agora') }}</p>
                <h2 class="mt-3 text-2xl font-semibold tracking-tight text-mist-50 sm:text-3xl">
                    {{ __('Descubra onde uma presença digital aparece') }}
                </h2>
                <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-mist-300">
                    {{ __('Investigue em fontes públicas, acompanhe cada etapa e receba um relatório com evidências, origem e limitações — sem cadastro e com pagamento único.') }}
                </p>

                <div class="mt-7 flex flex-col justify-center gap-3 sm:flex-row">
                    <x-btn href="{{ route('guest.investigations.create') }}" variant="primary" size="lg">{{ __('INICIAR INVESTIGAÇÃO') }}</x-btn>
                    <x-btn href="{{ route('login') }}" variant="outline" size="lg">{{ __('Entrar na minha conta') }}</x-btn>
                </div>
            </div>
        </div>
    </section>
</x-public-layout>

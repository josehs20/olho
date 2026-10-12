<x-public-layout :title="__('Investigação avulsa — sem cadastro')">
    <x-slot name="header">
        <p class="eyebrow">{{ __('Nova investigação') }}</p>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-mist-50 sm:text-3xl">
            {{ __('O que você quer investigar?') }}
        </h1>
        <p class="mt-2 max-w-2xl text-sm text-mist-400">
            {{ __('O Olho vai procurar sinais públicos relacionados ao que você informar. Informe apenas dados públicos e acompanhe tudo pelo link de acesso.') }}
        </p>

        <x-step-indicator class="mt-6" :steps="[__('Identificador'), __('Revisão'), __('Pagamento'), __('Investigação'), __('Resultado')]" :current="0" />
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-[1.35fr_0.65fr]">
        <x-card padding="p-6 sm:p-7">
            <form method="POST" action="{{ route('guest.investigations.store') }}" class="space-y-6">
                @csrf

                <fieldset class="space-y-4">
                    <legend class="text-sm font-semibold text-mist-100">{{ __('Identificador') }}</legend>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="username" class="field-label">{{ __('Username') }}</label>
                            <input id="username" name="username" type="text" value="{{ old('username') }}" autocomplete="off"
                                class="field" placeholder="ex.: joao.silva">
                            @error('username') <p class="mt-2 text-sm text-rose-300">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="email" class="field-label">{{ __('E-mail') }}</label>
                            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="off"
                                class="field" placeholder="ex.: contato@exemplo.com">
                            @error('email') <p class="mt-2 text-sm text-rose-300">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="phone" class="field-label">{{ __('Telefone') }}</label>
                            <input id="phone" name="phone" type="text" value="{{ old('phone') }}" autocomplete="off"
                                class="field" placeholder="ex.: +55 11 90000-0000">
                            @error('phone') <p class="mt-2 text-sm text-rose-300">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="name" class="field-label">{{ __('Nome completo') }}</label>
                            <input id="name" name="name" type="text" value="{{ old('name') }}" autocomplete="off"
                                class="field" placeholder="ex.: João da Silva">
                            @error('name') <p class="mt-2 text-sm text-rose-300">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="city" class="field-label">{{ __('Cidade') }}</label>
                            <input id="city" name="city" type="text" value="{{ old('city') }}" autocomplete="off" class="field">
                            @error('city') <p class="mt-2 text-sm text-rose-300">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="state" class="field-label">{{ __('UF') }}</label>
                            <input id="state" name="state" type="text" maxlength="2" value="{{ old('state') }}" autocomplete="off" class="field">
                            @error('state') <p class="mt-2 text-sm text-rose-300">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <p class="text-xs leading-relaxed text-mist-500">
                        {{ __('Informe pelo menos um identificador (username, e-mail ou telefone). Um nome pode levar a muitos lugares — os demais campos ajudam a filtrar correspondências.') }}
                    </p>
                </fieldset>

                <div class="rounded-xl border border-line bg-ink-950/60 p-4">
                    <p class="text-sm font-medium text-mist-100">{{ __('O que será consultado') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-mist-400">
                        {{ __('Todas as fontes públicas aplicáveis ao identificador informado. Fontes não aplicáveis aparecem no relatório como não pesquisadas — nunca como resultado negativo.') }}
                    </p>
                </div>

                {{-- O erro do provedor aparece **acima** do botão: quando o PagBank
                     recusa a cobrança, a página volta para cá e a mensagem precisa
                     ser vista sem rolar — senão o clique parece não fazer nada. --}}
                @error('payment')
                    <x-alert tone="danger" class="mt-6">{{ $message }}</x-alert>
                @enderror

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-mist-500">
                        {{ __('Ao continuar você será levado ao pagamento. A consulta só é executada após a confirmação.') }}
                    </p>

                    <x-btn type="submit" variant="primary" size="lg" class="w-full sm:w-auto">
                        {{ __('Revisar e pagar') }} {{ __('R$') }} {{ number_format($price->price_cents / 100, 2, ',', '.') }}
                    </x-btn>
                </div>
            </form>
        </x-card>

        <div class="space-y-4">
            <x-card eyebrow="{{ __('Investigação avulsa') }}" padding="p-5">
                <p class="text-3xl font-semibold tabular-nums text-mist-50">
                    {{ __('R$') }} {{ number_format($price->price_cents / 100, 2, ',', '.') }}
                </p>
                <p class="mt-1 text-xs text-mist-400">{{ __('Pagamento único · sem assinatura · sem cadastro') }}</p>
                <p class="mt-3 text-xs leading-relaxed text-mist-500">{{ __('O Olho está esperando a sua pista.') }}</p>

                <ul class="mt-5 space-y-2.5 text-sm text-mist-300">
                @foreach ([
                        __('Sem cadastro obrigatório'),
                        __('Sem assinatura'),
                        __('Acesso ao resultado por link exclusivo'),
                        __('Limitações sempre visíveis'),
                    ] as $item)
                        <li class="flex items-start gap-2">
                            <span class="mt-0.5 text-signal-300" aria-hidden="true">✓</span>
                            <span>{{ $item }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-card>

            <x-card padding="p-5">
                <p class="eyebrow">{{ __('Transparência') }}</p>
                <p class="mt-2 text-xs leading-relaxed text-mist-400">
                    {{ __('O Olho trabalha com informações públicas disponíveis nas fontes consultadas. Não acessamos conteúdo privado, não burlamos autenticação, CAPTCHA ou paywall e não afirmamos identidade: correspondência possível não é confirmação.') }}
                </p>
                <p class="mt-2 text-xs leading-relaxed text-mist-500">
                    {{ __('Resultados dependem da disponibilidade, visibilidade e condições de acesso de cada fonte.') }}
                </p>
                <p class="mt-3 text-xs text-mist-500">
                    <a href="{{ route('legal.privacy') }}" class="underline transition hover:text-mist-300">{{ __('Política de privacidade') }}</a>
                </p>
            </x-card>
        </div>
    </div>
</x-public-layout>

@php
    // @see App\Payments\PaymentSettings — objeto com a configuração vigente.
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-mist-100">
            {{ __('Pagamentos') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
            @include('admin.partials.nav')

            @if (session('status'))
                <div class="mb-4 rounded-lg border border-signal-500/40 bg-signal-600/10 px-4 py-3 text-sm text-signal-300">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 rounded-lg border border-rose-500/40 bg-rose-600/10 px-4 py-3 text-sm text-rose-300">
                    {{ session('error') }}
                </div>
            @endif

            {{-- Status da integração --}}
            <div class="surface mb-6 p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-lg font-semibold text-mist-100">PagBank</h3>
                    <span class="badge {{ $lastTestOk ? 'badge-signal' : 'badge-warning' }}">
                        {{ $lastTestOk ? __('● Conectado') : __('○ Não verificado') }}
                    </span>
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-2 sm:block">
                        <dt class="text-mist-400">Status</dt>
                        <dd class="text-mist-100">{{ $settings->enabled() ? __('Ativo') : __('Desativado') }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 sm:block">
                        <dt class="text-mist-400">Ambiente</dt>
                        <dd class="text-mist-100">{{ $settings->isProduction() ? __('Produção') : __('Sandbox') }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 sm:block">
                        <dt class="text-mist-400">PIX</dt>
                        <dd class="text-mist-100">{{ $settings->pixEnabled() ? __('✓ Ativo') : __('— Inativo') }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 sm:block">
                        <dt class="text-mist-400">Débito</dt>
                        <dd class="text-mist-100">{{ $settings->debitEnabled() ? __('✓ Ativo') : __('— Inativo') }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 sm:block">
                        <dt class="text-mist-400">Crédito</dt>
                        <dd class="text-mist-100">{{ $settings->creditEnabled() ? __('✓ Ativo') : __('— Inativo') }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 sm:block">
                        <dt class="text-mist-400">Parcelamento</dt>
                        <dd class="text-mist-100">{{ __('Até :n x', ['n' => $settings->maxInstallments()]) }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 sm:block sm:col-span-2">
                        <dt class="text-mist-400">Último teste</dt>
                        <dd class="text-mist-100">{{ $lastTestAt?->format('d/m/Y H:i') ?? __('Nunca testado') }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 sm:block sm:col-span-2">
                        <dt class="text-mist-400">Token em uso</dt>
                        <dd class="text-mist-100 font-mono text-xs">{{ $tokenPreview ?? __('— nenhum token salvo') }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 sm:block sm:col-span-2">
                        <dt class="text-mist-400">Endpoint testado</dt>
                        <dd class="text-mist-100 font-mono text-xs break-all">{{ $testEndpoint }}</dd>
                    </div>
                </dl>

                @if (! $lastTestOk && $lastTestError)
                    <p class="mt-4 rounded-lg border border-rose-500/30 bg-rose-600/10 px-3 py-2 text-xs text-rose-200">
                        {{ $lastTestError }}
                    </p>
                @endif

                {{-- Diagnóstico da última cobrança recusada de verdade (não do
                     teste de conexão): é aqui que aparece o parâmetro que o
                     PagBank recusou no `400`. --}}
                @if ($lastCheckoutError)
                    <p class="mt-4 rounded-lg border border-rose-500/30 bg-rose-600/10 px-3 py-2 text-xs text-rose-200">
                        <span class="font-semibold">{{ __('Última falha ao criar cobrança:') }}</span>
                        <span class="break-words">{{ $lastCheckoutError }}</span>
                    </p>
                @endif

                {{-- Sem HTTPS público o PagBank não alcança o webhook e **recusa** as
                     URLs de retorno locais (`400 invalid_value`): as três saem do
                     payload e a confirmação só chega pela reconciliação. --}}
                @if ($settings->provider() === 'pagbank' && ! $webhookIsPublic)
                    <p class="mt-4 rounded-lg border border-warn-soft/40 bg-warn-soft/10 px-3 py-2 text-xs text-warn-soft">
                        {{ __('O OLHO está sem endereço público (APP_URL sem HTTPS): o PagBank recusa URLs locais, então notification_urls, redirect_url e return_url ficam de fora do checkout — o comprador paga, mas a confirmação só é liberada quando o OLHO reconsulta o provedor. Publique o OLHO em HTTPS antes de vender.') }}
                    </p>
                @endif

                @if ($settings->provider() === 'pagbank' && ! $settings->hasUsablePagBank())
                    <p class="mt-4 rounded-lg border border-warn-soft/40 bg-warn-soft/10 px-3 py-2 text-xs text-warn-soft">
                        {{ __('Provedor PagBank selecionado, mas o OLHO ainda não consegue cobrar: verifique se a integração está ativa, se há token salvo e se ao menos uma forma de pagamento está marcada.') }}
                    </p>
                @endif

                {{-- O provedor Fake ignora as credenciais salvas: com ele só existe PIX
                     simulada (nenhum checkout hospedado => nenhum cartão para o cliente).
                     Sem este aviso, um token já testado com sucesso passa a impressão de
                     que o cartão está no ar. --}}
                @if ($settings->provider() !== 'pagbank' && $hasToken)
                    <p class="mt-4 rounded-lg border border-warn-soft/40 bg-warn-soft/10 px-3 py-2 text-xs text-warn-soft">
                        {{ __('Existe um token do PagBank salvo, mas o provedor ativo é o Fake (simulado): o cliente vê apenas PIX e o cartão não é oferecido. Selecione PagBank no campo Provedor acima e salve para cobrar de verdade.') }}
                    </p>
                @endif

                <form method="POST" action="{{ route('admin.settings.payments.test') }}" class="mt-5">
                    @csrf
                    <x-btn type="submit" variant="outline" size="sm">{{ __('Testar conexão') }}</x-btn>
                    <span class="ms-3 text-xs text-mist-500">{{ __('Não gera cobrança nem consome créditos.') }}</span>
                </form>
            </div>

            <form method="POST" action="{{ route('admin.settings.payments.update') }}" class="space-y-6">
                @csrf
                @method('PUT')

                {{-- Geral --}}
                <div class="surface p-6">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-mist-300">{{ __('Geral') }}</h3>
                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="provider" class="field-label">{{ __('Provedor') }}</label>
                            <select id="provider" name="provider" class="field">
                                <option value="fake" @selected(old('provider', $settings->provider()) === 'fake')>{{ __('Fake (desenvolvimento)') }}</option>
                                <option value="pagbank" @selected(old('provider', $settings->provider()) === 'pagbank')>PagBank</option>
                            </select>
                        </div>
                        <div>
                            <label for="environment" class="field-label">{{ __('Ambiente') }}</label>
                            <select id="environment" name="environment" class="field">
                                <option value="sandbox" @selected(old('environment', $settings->environment()) === 'sandbox')>Sandbox</option>
                                <option value="production" @selected(old('environment', $settings->environment()) === 'production')>Produção</option>
                            </select>
                        </div>
                    </div>

                    <label class="mt-4 flex items-center gap-2 text-sm text-mist-300">
                        <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings->enabled()))>
                        {{ __('Integração ativa') }}
                    </label>

                    <p class="mt-3 rounded-lg border border-warn-soft/40 bg-warn-soft/10 px-3 py-2 text-xs text-warn-soft">
                        {{ __('Ambiente PRODUÇÃO: os pagamentos neste ambiente possuem valor financeiro real.') }}
                    </p>
                </div>

                {{-- PagBank --}}
                <div class="surface p-6">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-mist-300">PagBank</h3>

                    <div class="mt-4">
                        <label for="token" class="field-label">{{ __('API Token') }}</label>
                        <input id="token" name="token" type="password" autocomplete="off" class="field"
                               placeholder="{{ $hasToken ? '••••••••••••••••••••••••' : __('Informe o token de integração') }}">
                        <p class="field-hint">
                            {{ __('Nunca exibimos o token salvo. Deixe em branco para manter o atual.') }}
                        </p>
                        <p class="field-hint">
                            {{ __('Cole apenas o valor do token — sem "Bearer", aspas ou quebra de linha. O cliente HTTP já envia o prefixo "Bearer".') }}
                        </p>
                        <p class="field-hint">
                            {{ __('Sandbox: Portal do Desenvolvedor → Tokens. Produção: Vendas → Integrações → Gerar Token (o produto Checkout exige homologação prévia).') }}
                        </p>
                        @if ($hasToken)
                            <label class="mt-2 flex items-center gap-2 text-xs text-mist-400">
                                <input type="checkbox" name="clear_token" value="1">
                                {{ __('Remover o token salvo') }}
                            </label>
                        @endif
                        @error('token') <p class="mt-1 text-sm text-rose-300">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Formas de pagamento --}}
                <div class="surface p-6">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-mist-300">{{ __('Formas de pagamento') }}</h3>
                    <div class="mt-4 space-y-3 text-sm text-mist-300">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="method_pix" value="1" @checked(old('method_pix', $settings->pixEnabled()))>
                            {{ __('PIX') }}
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="method_debit" value="1" @checked(old('method_debit', $settings->debitEnabled()))>
                            {{ __('Cartão de débito') }}
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="method_credit" value="1" @checked(old('method_credit', $settings->creditEnabled()))>
                            {{ __('Cartão de crédito') }}
                        </label>
                    </div>
                    <p class="field-hint mt-4">
                        {{ __('Com o provedor PagBank, ao menos uma forma precisa estar marcada: o provedor recusa um checkout sem método de pagamento.') }}
                    </p>
                    @error('methods') <p class="mt-2 text-sm text-rose-300">{{ $message }}</p> @enderror
                </div>

                {{-- Prazo do checkout --}}
                <div class="surface p-6">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-mist-300">{{ __('Prazo do checkout') }}</h3>
                    <div class="mt-4 max-w-xs">
                        <label for="pix_expiration_minutes" class="field-label">{{ __('Expiração (minutos)') }}</label>
                        <input id="pix_expiration_minutes" name="pix_expiration_minutes" type="number" min="1" max="1440"
                               value="{{ old('pix_expiration_minutes', $settings->pixExpirationMinutes()) }}" class="field">
                        <p class="field-hint">
                            {{ __('Vale para o checkout inteiro (PIX, débito e crédito): passado o prazo, o PagBank não aceita mais o pagamento deste pedido.') }}
                        </p>
                    </div>
                </div>

                {{-- Cartão de crédito --}}
                <div class="surface p-6">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-mist-300">{{ __('Cartão de crédito') }}</h3>
                    <div class="mt-4 max-w-xs">
                        <label for="credit_max_installments" class="field-label">{{ __('Máximo de parcelas') }}</label>
                        <select id="credit_max_installments" name="credit_max_installments" class="field">
                            @foreach ($installments as $n)
                                <option value="{{ $n }}" @selected((int) old('credit_max_installments', $settings->maxInstallments()) === (int) $n)>{{ $n }}x</option>
                            @endforeach
                        </select>
                        <p class="field-hint">{{ __('O cartão de débito não possui parcelamento.') }}</p>
                    </div>
                </div>

                {{-- Webhooks --}}
                <div class="surface p-6">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-mist-300">Webhooks</h3>
                    <div class="mt-4">
                        <label class="field-label">{{ __('URL de notificação (PagBank)') }}</label>
                        <input type="text" readonly value="{{ $webhookUrl }}" class="field font-mono text-xs" onclick="this.select()">
                        <p class="field-hint">
                            {{ __('O OLHO envia esta URL em cada checkout criado (notification_urls) — não há nada a cadastrar no painel do PagBank. A confirmação sempre consulta o estado real no provedor antes de liberar.') }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3">
                    <x-btn type="submit" variant="primary">{{ __('Salvar configurações') }}</x-btn>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>

<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">
  {{ __('Comprar créditos') }}
  </h2>
 </x-slot>

 {{-- O canal é escolha declarada do comprador e os dois caminhos terminam no
      pagamento: com checkout hospedado (PagBank) o redirecionamento para a página
      segura é imediato; com o provedor que emite o PIX aqui, o código e o
      acompanhamento ficam na página do pedido. Sem modal e sem JavaScript: um
      POST, um destino. --}}
 <div class="py-12">
  <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
  @if (session('error'))
   <div class="mb-4 rounded-lg border border-rose-500/40 bg-rose-600/10 px-4 py-3 text-sm text-rose-300">
   {{ session('error') }}
   </div>
  @endif

  @if ($errors->any())
   {{-- Erros do canal escolhido (formulário clássico, sem JavaScript). --}}
   <div class="mb-4 rounded-lg border border-rose-500/40 bg-rose-600/10 px-4 py-3 text-sm text-rose-300">
   <p class="font-medium">{{ __('Não foi possível iniciar o pagamento:') }}</p>
   <ul class="mt-1 list-disc space-y-1 pl-5">
    @foreach ($errors->all() as $error)
    <li>{{ $error }}</li>
    @endforeach
   </ul>
   </div>
  @endif

  @if (! ($available ?? true))
   <div class="mb-4 rounded-lg border border-warn-soft/40 bg-warn-soft/10 px-4 py-3 text-sm text-warn-soft">
   {{ __('A compra de créditos está temporariamente indisponível: a integração de pagamento ainda não está configurada. Tente novamente mais tarde.') }}
   </div>
  @endif

  {{-- O pagamento acontece fora do OLHO e em outra aba: a tela de créditos fica
       à mão para o comprador voltar e conferir a carteira. O saldo é lido do
       ledger a cada carregamento, nunca somado no navegador. --}}
  <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-ink-950/60 px-4 py-3">
   <p class="text-xs leading-relaxed text-mist-500">
   {{ __('O pagamento abre em outra aba — esta página continua aberta. Recarregue depois de pagar para ver o saldo.') }}
   </p>
   <p class="whitespace-nowrap text-sm text-mist-300">
   {{ __('Saldo de créditos') }}:
   <span class="font-semibold tabular-nums text-mist-50">{{ number_format($balance ?? 0) }}</span>
   </p>
  </div>

  @if ($packages->isEmpty())
   <div class="overflow-hidden surface">
    <div class="p-6 text-mist-100">
    {{ __('Nenhum pacote de créditos disponível no momento.') }}
    </div>
   </div>
  @else
   <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
    @foreach ($packages as $package)
    @php
     $pixChannel = in_array(\App\Payments\PaymentChannel::Pix, $channels ?? [], true);
     $cardChannel = in_array(\App\Payments\PaymentChannel::Card, $channels ?? [], true);
    @endphp
    <div class="overflow-hidden surface">
     <div class="p-6">
      <h3 class="text-lg font-semibold text-mist-100">{{ $package->name }}</h3>
      <p class="mt-2 text-3xl font-bold text-mist-100">
      {{ number_format($package->credits) }}
      <span class="text-sm font-normal text-mist-400">{{ __('créditos') }}</span>
      </p>
      <p class="mt-1 text-sm text-mist-400">
      {{ __('R$') }} {{ number_format($package->price_cents / 100, 2, ',', '.') }}
      </p>

      <div class="mt-6 space-y-2">
      @if ($pixChannel)
       {{-- PIX: mesmo caminho do cartão — o comprador paga na página do provedor.
            O destino abre em **outra aba** para esta tela continuar à mão (o
            comprador volta, recarrega e vê o saldo). Com o PIX emitido aqui
            (mock), a página do pedido mostra o copia e cola e acompanha a
            confirmação sem sair do OLHO. --}}
       <form method="POST" action="{{ route('payments.store', $package) }}" target="_blank">
        @csrf
        <input type="hidden" name="channel" value="{{ \App\Payments\PaymentChannel::Pix->value }}">

        <button type="submit" class="btn btn-primary w-full" @disabled(! ($available ?? true))>
        {{ __('Comprar com PIX') }}
        </button>
       </form>
      @endif

      @if ($cardChannel)
       {{-- Cartão: sem tratamento local — o comprador paga na tela do PagBank
            (em outra aba) e informa ali os próprios dados: o OLHO não coleta nem
            envia nada. --}}
       <form method="POST" action="{{ route('payments.store', $package) }}" target="_blank">
        @csrf
        <input type="hidden" name="channel" value="{{ \App\Payments\PaymentChannel::Card->value }}">

        <button type="submit" class="btn btn-outline w-full" @disabled(! ($available ?? true))>
        {{ __('Comprar com cartão') }}
        </button>
       </form>
      @endif

      @if (! $pixChannel && ! $cardChannel)
       <button type="button" class="btn btn-primary w-full" disabled>
       {{ __('Comprar') }}
       </button>
      @endif
      </div>

      @if ($cardChannel)
      <p class="mt-3 text-center text-[0.6875rem] leading-relaxed text-mist-500">
      {{ __('O cartão é concluído no ambiente seguro do PagBank. O Olho nunca recebe dados de cartão.') }}
      </p>
      @elseif ($pixChannel)
      <p class="mt-3 text-center text-[0.6875rem] leading-relaxed text-mist-500">
      {{ __('O PIX é emitido pelo provedor de pagamento e a confirmação é acompanhada pelo OLHO — nada de dados de cartão.') }}
      </p>
      @endif
     </div>
    </div>
    @endforeach
   </div>
  @endif
  </div>
 </div>
</x-app-layout>


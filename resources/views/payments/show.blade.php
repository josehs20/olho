<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">
  {{ __('Pagamento') }}
  </h2>
 </x-slot>

 @php
  // Estado real persistido (nada é inferido no HTML) + dados do PIX emitidos.
  $isPaid = $payment->status === \App\Payments\PaymentStatus::Paid->value;
  $pixPayload = (string) data_get($payment->metadata, 'pix_payload', '');
 @endphp

 <div class="py-12">
  <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
  <div class="overflow-hidden surface">
   <div class="p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
    <h3 class="text-lg font-semibold text-mist-100">{{ __('Pedido') }} #{{ $payment->id }}</h3>
    <x-status-badge :status="$payment->status" />
    </div>

    <p class="mt-2 text-sm text-mist-400">
    {{ __('Valor') }}: {{ __('R$') }} {{ number_format($payment->amount_cents / 100, 2, ',', '.') }}
    </p>

    @if ($isPaid)
    <div class="mt-6 rounded-xl border border-emerald-600/40 bg-emerald-600/10 p-4">
     <p class="text-sm leading-relaxed text-emerald-300">{{ __('Pagamento confirmado. Créditos adicionados à sua carteira.') }}</p>
     <x-btn href="{{ route('investigations.create') }}" variant="primary" class="mt-4 w-full">{{ __('Usar meus créditos') }}</x-btn>
    </div>
    @elseif ($payment->checkout_url && ! $isFake)
    {{-- Cartão/checkout: o comprador conclui no ambiente do PagBank. O link abre
         em **outra aba**, então esta página do pedido continua acompanhando o
         pagamento — é aqui que a confirmação aparece. --}}
    <div class="mt-6 rounded-xl border border-line bg-ink-950/60 p-4">
     <p class="eyebrow">{{ __('Pagamento via PagBank') }}</p>
     <p class="mt-3 text-sm leading-relaxed text-mist-300">{{ __('O pagamento é concluído no ambiente seguro do PagBank, com os métodos habilitados no OLHO. O PagBank abre em outra aba: esta página continua acompanhando o pedido.') }}</p>
     <x-btn href="{{ $payment->checkout_url }}" target="_blank" rel="noopener noreferrer" variant="primary" class="mt-4 w-full">{{ __('PAGAR COM PAGBANK') }}</x-btn>
     <p class="mt-2 text-center text-[0.6875rem] text-mist-500">{{ __('Os créditos são liberados quando o PagBank confirma o pagamento.') }}</p>
    </div>
    @else
    <div class="mt-6 rounded-xl border border-line bg-ink-950/60 p-4">
     <p class="eyebrow">{{ __('PIX copia e cola') }}</p>

     @if ($pixPayload !== '')
     <p id="pix-payload" class="mt-3 max-h-40 overflow-y-auto break-all font-mono text-xs leading-relaxed text-mist-200">{{ $pixPayload }}</p>

     <div class="mt-3 flex flex-wrap gap-2">
      <x-btn type="button" variant="outline" size="sm" id="copy-pix">{{ __('Copiar código PIX') }}</x-btn>
     </div>
     @else
     <p class="mt-3 text-sm leading-relaxed text-mist-300">{{ __('A cobrança não tem um código para copiar. Recarregue a página ou volte aos pacotes para gerar um novo pedido.') }}</p>
     @endif

     <div class="mt-5 flex flex-wrap items-center gap-3 rounded-xl border border-line bg-ink-950/40 px-3 py-3">
      <span class="badge badge-warning animate-pulse">{{ __('Aguardando') }}</span>
      <span class="text-sm text-mist-300">{{ __('Aguardando o pagamento...') }}</span>
     </div>

     @if ($payment->expires_at)
     <p class="mt-3 text-xs text-mist-500">{{ __('Código válido até') }} {{ $payment->expires_at->format('d/m/Y H:i') }}.</p>
     @endif

     @if ($isFake)
     <form method="POST" action="{{ route('payments.simulate', $payment) }}" class="mt-4">
      @csrf
      <x-btn type="submit" variant="primary" class="w-full">{{ __('Simular pagamento (provider fake)') }}</x-btn>
     </form>
     @endif
    </div>
    @endif

    <a href="{{ route('payments.index') }}" class="mt-6 inline-block text-sm text-mist-300 hover:text-mist-100">
    &larr; {{ __('Voltar aos pacotes') }}
    </a>
   </div>
  </div>
  </div>
 </div>

 @push('scripts')
 <script>
  (function () {
   var copyButton = document.getElementById('copy-pix');
   var payload = document.getElementById('pix-payload');

   if (copyButton && payload) {
    copyButton.addEventListener('click', function () {
     var text = payload.textContent.trim();
     var fallback = function () {
      var range = document.createRange();
      range.selectNodeContents(payload);
      var selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
     };

     if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () {
       copyButton.textContent = @json(__('Código PIX copiado'));
      }).catch(fallback);
     } else {
      fallback();
     }
    });
   }

   // A página acompanha o pedido até a confirmação: consulta o servidor (que
   // reconsulta o provedor, de forma idempotente) e recarrega quando o pagamento
   // entra. Nada é confirmado pelo navegador.
   var statusUrl = @json($statusUrl);
   var attempts = 0;

   if (@json($isPaid) || ! statusUrl) {
    return;
   }

   var timer = window.setInterval(function () {
    attempts++;

    fetch(statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
     .then(function (response) { return response.ok ? response.json() : null; })
     .then(function (data) {
      if (data && data.payment && data.payment.paid) {
       window.clearInterval(timer);
       window.location.reload();
      }
     })
     .catch(function () {});

    if (attempts >= 240) {
     window.clearInterval(timer);
    }
   }, 5000);
  })();
 </script>
 @endpush
</x-app-layout>


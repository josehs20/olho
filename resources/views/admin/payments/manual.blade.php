<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">{{ __('PIX já pago') }}</h2>
 </x-slot>

 <div class="py-12">
  <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
  @include('admin.partials.nav')

  @if (session('status'))
   <x-alert tone="success" class="mb-4">{{ session('status') }}</x-alert>
  @endif

  @if (session('error'))
   <x-alert tone="danger" class="mb-4">{{ session('error') }}</x-alert>
  @endif

  @if ($errors->any())
   <x-alert tone="danger" class="mb-4">{{ $errors->first() }}</x-alert>
  @endif

  {{-- O clique do comprador só enfileira: nada é liberado sem o operador
       conferir o recebimento no extrato do banco. --}}
  <div class="mb-6 surface p-6">
   <div class="flex flex-wrap items-center justify-between gap-3">
    <h3 class="text-lg font-semibold text-mist-100">{{ __('Conferência manual') }}</h3>
    <span class="badge {{ $awaitingManual > 0 ? 'badge-warning' : 'badge-signal' }}">
     {{ trans_choice('{0} Nenhum aviso pendente|{1} :count aviso pendente|[2,*] :count avisos pendentes', $awaitingManual, ['count' => $awaitingManual]) }}
    </span>
   </div>
   <p class="mt-2 text-sm leading-relaxed text-mist-400">
    {{ __('Pedidos do provedor “Link do banco”. O comprador paga por PIX no site do banco e clica em “Já paguei”; aqui você confere o valor no extrato e confirma para liberar os créditos (ou executar a investigação avulsa). Confirmar sem ter recebido concede crédito indevido — confira sempre o extrato antes.') }}
   </p>
   <p class="mt-2 text-xs text-mist-500">
    {{ __('Confirmar é idempotente: dois cliques não creditam duas vezes. “Retirar aviso” devolve o pedido para aguardando pagamento, sem alterar valores.') }}
   </p>
  </div>

  @if ($payments->isEmpty())
   <div class="overflow-hidden surface">
    <div class="p-6 text-mist-100">
     {{ __('Nenhum pedido por link do banco aguardando conferência.') }}
    </div>
   </div>
  @else
   <div class="overflow-hidden surface">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
     <thead class="bg-ink-850/60">
      <tr>
       <th class="px-4 py-2 text-left text-mist-400">{{ __('Pedido') }}</th>
       <th class="px-4 py-2 text-left text-mist-400">{{ __('Comprador') }}</th>
       <th class="px-4 py-2 text-left text-mist-400">{{ __('Item') }}</th>
       <th class="px-4 py-2 text-left text-mist-400">{{ __('Valor') }}</th>
       <th class="px-4 py-2 text-left text-mist-400">{{ __('Status') }}</th>
       <th class="px-4 py-2 text-left text-mist-400">{{ __('Aviso') }}</th>
       <th class="px-4 py-2 text-left text-mist-400">{{ __('Link') }}</th>
       <th class="px-4 py-2 text-left text-mist-400">{{ __('Ações') }}</th>
      </tr>
     </thead>
     <tbody class="divide-y divide-gray-200">
      @foreach ($payments as $payment)
       <tr>
        <td class="px-4 py-3 font-mono text-xs">#{{ $payment->id }}</td>
        <td class="px-4 py-3">
         @if ($payment->isGuest())
          <span class="text-mist-300">{{ __('Sem conta (avulsa)') }}</span>
         @else
          {{ $payment->user?->email ?? '—' }}
         @endif
        </td>
        <td class="px-4 py-3">
         @if ($payment->guestInvestigation)
          <a href="{{ route('admin.investigations.show', $payment->guestInvestigation) }}" class="text-signal-300 underline decoration-signal-500/40 hover:text-signal-300">
           {{ __('Investigação') }} #{{ $payment->guestInvestigation->id }}
          </a>
         @else
          {{ $payment->creditPackage?->name ?? '—' }}
         @endif
        </td>
        <td class="px-4 py-3 whitespace-nowrap">{{ __('R$') }} {{ number_format($payment->amount_cents / 100, 2, ',', '.') }}</td>
        <td class="px-4 py-3">
         <x-status-badge :status="$payment->status" />
        </td>
        <td class="px-4 py-3 whitespace-nowrap text-xs text-mist-400">
         {{ data_get($payment->metadata, 'manual_requested_at')
            ? \Illuminate\Support\Carbon::parse(data_get($payment->metadata, 'manual_requested_at'))->format('d/m/Y H:i')
            : '—' }}
        </td>
        <td class="px-4 py-3">
         @if ($payment->checkout_url)
          <a href="{{ $payment->checkout_url }}" target="_blank" rel="noopener noreferrer" class="text-signal-300 underline decoration-signal-500/40 hover:text-signal-300">{{ __('Abrir') }}</a>
         @else
          <span class="text-warn-soft text-xs">{{ __('ausente') }}</span>
         @endif
        </td>
        <td class="px-4 py-3">
         <div class="flex flex-wrap items-center gap-2">
          <form method="POST" action="{{ route('admin.payments.manual-confirm', $payment) }}">
           @csrf
           <button type="submit"
                   class="rounded-md bg-signal-500 px-3 py-1.5 text-xs font-semibold text-ink-950 hover:bg-signal-400"
                   onclick="return confirm(@json(__('Confirma que o valor foi recebido no extrato do banco? Os créditos serão liberados agora.')));">
            {{ __('Confirmar recebimento') }}
           </button>
          </form>

          @if ($payment->status === \App\Payments\PaymentStatus::AwaitingManualConfirmation->value)
           <form method="POST" action="{{ route('admin.payments.manual-dismiss', $payment) }}">
            @csrf
            <button type="submit"
                    class="rounded-md bg-ink-700 px-3 py-1.5 text-xs text-white hover:bg-ink-600"
                    title="{{ __('Retira o aviso da fila sem liberar nada. Use quando o comprador avisou por engano ou o valor não aparece no extrato.') }}">
             {{ __('Retirar aviso') }}
            </button>
           </form>
          @endif
         </div>
        </td>
       </tr>
      @endforeach
     </tbody>
    </table>
   </div>

   <div class="mt-4">{{ $payments->links() }}</div>
  @endif
  </div>
 </div>
</x-app-layout>

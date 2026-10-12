<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">{{ __('Pagamentos') }}</h2>
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

  <div class="mb-4 flex flex-wrap items-center gap-3">
   <form method="POST" action="{{ route('admin.payments.sync-pending') }}">
    @csrf
    <button type="submit"
            class="rounded-md bg-signal-500 px-3 py-1.5 text-sm font-semibold text-ink-950 hover:bg-signal-400"
            title="{{ __('Consulta no PagBank todos os pedidos ainda aguardando confirmação e libera os que já constarem como aprovados — mesma sincronização idempotente do webhook. Use quando a notificação não chegar (ambiente local).') }}">
     {{ __('Sincronizar pendentes') }}
    </button>
   </form>
   <form method="POST" action="{{ route('admin.payments.revalidate-pending') }}">
    @csrf
    <button type="submit"
            class="rounded-md bg-ink-700 px-3 py-1.5 text-sm text-white hover:bg-ink-600"
            title="{{ __('Agenda no worker a verificação dos mesmos pendentes: a página responde na hora e cada pedido é conferido no PagBank pelo job (com retentativas), liberando os que já estiverem pagos. Use quando o webhook falhou ou a consulta imediata estiver indisponível.') }}">
     {{ __('Revalidar pendentes') }}
    </button>
   </form>
   <p class="text-xs text-mist-400">
    {{ __('Confere no PagBank todos os pedidos com status') }} <code>awaiting_payment</code> {{ __('e libera (créditos ou investigação avulsa) os que já estiverem pagos.') }} <strong>{{ __('Sincronizar') }}</strong> {{ __('consulta agora;') }} <strong>{{ __('Revalidar') }}</strong> {{ __('agenda a verificação no worker. Equivale a') }} <code>php artisan pagbank:sync-payment --pending</code>.
   </p>
  </div>

  <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
   <div>
    <label for="status" class="field-label">{{ __('Status') }}</label>
    <select id="status" name="status" class="field w-40">
     <option value="">{{ __('Todos') }}</option>
     @foreach ($statuses as $status)
      <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->value }}</option>
     @endforeach
    </select>
   </div>
   <div>
    <label for="method" class="field-label">{{ __('Método') }}</label>
    <select id="method" name="method" class="field w-44">
     <option value="">{{ __('Todos') }}</option>
     @foreach ($methods as $method)
      <option value="{{ $method->value }}" @selected(request('method') === $method->value)>{{ $method->label() }}</option>
     @endforeach
    </select>
   </div>
   <div>
    <label for="provider" class="field-label">{{ __('Provedor') }}</label>
    <input id="provider" name="provider" type="text" value="{{ request('provider') }}" class="field w-32">
   </div>
   <div>
    <label for="from" class="field-label">{{ __('De') }}</label>
    <input id="from" name="from" type="date" value="{{ request('from') }}" class="field w-40">
   </div>
   <div>
    <label for="to" class="field-label">{{ __('Até') }}</label>
    <input id="to" name="to" type="date" value="{{ request('to') }}" class="field w-40">
   </div>
   <button type="submit" class="rounded-md bg-ink-700 px-3 py-1.5 text-sm text-white">{{ __('Filtrar') }}</button>
  </form>

  <div class="overflow-hidden surface">
   <table class="min-w-full divide-y divide-gray-200 text-sm">
    <thead class="bg-ink-850/60">
    <tr>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Tipo') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Usuário') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Item') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Valor') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Método') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Provedor') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Status') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('ID PagBank') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Ambiente') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Data') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Ações') }}</th>
    </tr>
    </thead>
    <tbody class="divide-y divide-gray-200">
    @foreach ($payments as $payment)
     <tr>
      <td class="px-4 py-3">
      <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $payment->isGuest() ? 'bg-indigo-100 text-indigo-800' : 'bg-ink-800/70 text-mist-300' }}">
       {{ $payment->isGuest() ? __('Avulsa') : __('Créditos') }}
      </span>
      </td>
      <td class="px-4 py-3">{{ $payment->user?->email ?? __('Sem conta') }}</td>
      <td class="px-4 py-3">
      @if ($payment->guestInvestigation)
       <a href="{{ route('admin.investigations.show', $payment->guestInvestigation) }}" class="text-signal-300 underline decoration-signal-500/40 hover:text-signal-300">
        {{ $payment->guestInvestigation->title ?? '#'.$payment->guestInvestigation->id }}
       </a>
      @else
       {{ $payment->creditPackage?->name ?? '—' }}
      @endif
      </td>
      <td class="px-4 py-3">{{ __('R$') }} {{ number_format($payment->amount_cents / 100, 2, ',', '.') }}</td>
      <td class="px-4 py-3" title="{{ $payment->method === null ? __('O comprador escolhe o método na tela do PagBank; o OLHO grava o método quando o provedor confirma o pagamento.') : '' }}">
      {{ $payment->method?->label() ?? '—' }}
     </td>
      <td class="px-4 py-3">{{ $payment->provider }}</td>
      <td class="px-4 py-3">{{ __((string) $payment->status) }}</td>
      <td class="px-4 py-3 font-mono text-xs">{{ $payment->provider_checkout_id ?? $payment->external_id ?? '—' }}</td>
      <td class="px-4 py-3">{{ $payment->environment ?? '—' }}</td>
      <td class="px-4 py-3">{{ $payment->created_at->format('d/m/Y H:i') }}</td>
      <td class="px-4 py-3">
       @if ($payment->provider === 'pagbank')
       <span class="inline-flex flex-wrap gap-1">
        <form method="POST" action="{{ route('admin.payments.sync', $payment) }}" class="inline">
         @csrf
         <button type="submit"
                 class="rounded-md bg-ink-700 px-2 py-1 text-xs text-white hover:bg-ink-600"
                 title="{{ __('Força a sincronização do estado com o PagBank (Sandbox). Consulta o provedor agora e confirma o pagamento se ele já constar como aprovado — mesma liberação idempotente do webhook.') }}">
          {{ __('Sincronizar') }}
         </button>
        </form>
        @if ($payment->status !== \App\Payments\PaymentStatus::Paid->value && $payment->external_id !== null)
        <form method="POST" action="{{ route('admin.payments.revalidate', $payment) }}" class="inline">
         @csrf
         <button type="submit"
                 class="rounded-md bg-ink-800/70 px-2 py-1 text-xs text-mist-200 hover:bg-ink-600"
                 title="{{ __('Agenda a verificação no worker: o PagBank é consultado pela fila (com retentativas) e o pedido é liberado se já estiver pago. Use quando o webhook falhou ou a consulta imediata estiver indisponível.') }}">
          {{ __('Revalidar') }}
         </button>
        </form>
        @endif
       </span>
       @else
       —
       @endif
      </td>
     </tr>
    @endforeach
    </tbody>
   </table>
  </div>

  <div class="mt-4">{{ $payments->links() }}</div>
  </div>
 </div>
</x-app-layout>

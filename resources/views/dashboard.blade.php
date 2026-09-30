<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">
  {{ __('Dashboard') }}
  </h2>
 </x-slot>

 <div class="py-12">
  <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
  <div class="mb-6 flex flex-wrap gap-4">
   <a href="{{ route('investigations.create') }}"
    class="inline-flex items-center rounded-md bg-ink-700 px-4 py-2 text-sm font-semibold text-white hover:border-signal-500/50">
    {{ __('Nova investigação') }}
   </a>
   <a href="{{ route('payments.index') }}"
    class="inline-flex items-center rounded-md bg-ink-900/70 px-4 py-2 text-sm font-semibold text-mist-300 ring-1 ring-line hover:bg-ink-850/60">
    {{ __('Comprar créditos') }}
   </a>
  </div>

  <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
   <div class="overflow-hidden surface">
    <div class="p-6">
    <p class="text-sm font-medium text-mist-400">{{ __('Saldo de créditos') }}</p>
    <p class="mt-1 text-3xl font-bold text-mist-100">{{ number_format($balance) }}</p>
    </div>
   </div>

   <div class="overflow-hidden surface">
    <div class="p-6">
    <p class="text-sm font-medium text-mist-400">{{ __('Créditos gastos') }}</p>
    <p class="mt-1 text-3xl font-bold text-mist-100">{{ number_format($creditsSpent) }}</p>
    </div>
   </div>
  </div>

  <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
   <div class="overflow-hidden surface">
    <div class="p-6">
    <h3 class="text-lg font-semibold text-mist-100">{{ __('Investigações recentes') }}</h3>
    @if ($recentInvestigations->isEmpty())
     <p class="mt-3 text-sm text-mist-400">{{ __('Você ainda não tem investigações.') }}</p>
    @else
     <ul class="mt-3 divide-y divide-gray-200">
      @foreach ($recentInvestigations as $investigation)
      <li class="flex items-center justify-between py-3">
       <a href="{{ route('investigations.show', $investigation) }}" class="font-medium text-mist-100 underline decoration-signal-500/40 hover:text-signal-300">
        {{ $investigation->title }}
       </a>
       <span class="rounded-full px-2 py-0.5 text-xs font-semibold bg-ink-800/70 text-mist-300">
        {{ __((string) $investigation->status) }}
       </span>
      </li>
      @endforeach
     </ul>
    @endif
    </div>
   </div>

   <div class="overflow-hidden surface">
    <div class="p-6">
    <h3 class="text-lg font-semibold text-mist-100">{{ __('Pagamentos recentes') }}</h3>
    @if ($recentPayments->isEmpty())
     <p class="mt-3 text-sm text-mist-400">{{ __('Nenhum pagamento ainda.') }}</p>
    @else
     <ul class="mt-3 divide-y divide-gray-200">
      @foreach ($recentPayments as $payment)
      <li class="flex items-center justify-between py-3">
       <div>
        <p class="text-sm font-medium text-mist-100">
        {{ __('R$') }} {{ number_format($payment->amount_cents / 100, 2, ',', '.') }}
        </p>
        <p class="text-xs text-mist-400">{{ $payment->created_at->format('d/m/Y H:i') }}</p>
       </div>
       <span class="rounded-full px-2 py-0.5 text-xs font-semibold bg-ink-800/70 text-mist-300">
        {{ __((string) $payment->status) }}
       </span>
      </li>
      @endforeach
     </ul>
    @endif
    </div>
   </div>
  </div>
  </div>
 </div>
</x-app-layout>


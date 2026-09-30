<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">
  {{ __('Comprar créditos') }}
  </h2>
 </x-slot>

 <div class="py-12">
  <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
  @if ($packages->isEmpty())
   <div class="overflow-hidden surface">
    <div class="p-6 text-mist-100">
    {{ __('Nenhum pacote de créditos disponível no momento.') }}
    </div>
   </div>
  @else
   <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
    @foreach ($packages as $package)
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

      <form method="POST" action="{{ route('payments.store', $package) }}" class="mt-6">
      @csrf
      <button type="submit"
       class="inline-flex w-full items-center justify-center rounded-md bg-ink-700 px-4 py-2 text-sm font-semibold text-white hover:border-signal-500/50">
       {{ __('Comprar') }}
      </button>
      </form>
     </div>
    </div>
    @endforeach
   </div>
  @endif
  </div>
 </div>
</x-app-layout>

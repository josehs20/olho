<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">
  {{ __('Administração') }}
  </h2>
 </x-slot>

 <div class="py-12">
  <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
  @include('admin.partials.nav')

  <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
   <div class="overflow-hidden surface">
    <div class="p-6">
    <p class="text-sm font-medium text-mist-400">{{ __('Usuários') }}</p>
    <p class="mt-1 text-3xl font-bold text-mist-100">{{ $usersCount }}</p>
    </div>
   </div>
   <div class="overflow-hidden surface">
    <div class="p-6">
    <p class="text-sm font-medium text-mist-400">{{ __('Investigações') }}</p>
    <p class="mt-1 text-3xl font-bold text-mist-100">{{ $investigationsCount }}</p>
    </div>
   </div>
   <div class="overflow-hidden surface">
    <div class="p-6">
    <p class="text-sm font-medium text-mist-400">{{ __('Pagamentos') }}</p>
    <p class="mt-1 text-3xl font-bold text-mist-100">{{ $paymentsCount }}</p>
    </div>
   </div>
   <div class="overflow-hidden surface">
    <div class="p-6">
    <p class="text-sm font-medium text-mist-400">{{ __('Créditos vendidos') }}</p>
    <p class="mt-1 text-3xl font-bold text-mist-100">{{ number_format($creditsSold) }}</p>
    </div>
   </div>
   <div class="overflow-hidden surface">
    <div class="p-6">
    <p class="text-sm font-medium text-mist-400">{{ __('Fontes com erro') }}</p>
    <p class="mt-1 text-3xl font-bold text-mist-100">{{ $sourceErrors }}</p>
    </div>
   </div>

   <div class="sm:col-span-2 lg:col-span-3">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-mist-300">{{ __('Visitas ao site') }}</h3>
   </div>
   <div class="overflow-hidden surface">
    <div class="p-6">
    <p class="text-sm font-medium text-mist-400">{{ __('Visitas hoje') }}</p>
    <p class="mt-1 text-3xl font-bold text-mist-100">{{ number_format($visits['today_views']) }}</p>
    </div>
   </div>
   <div class="overflow-hidden surface">
    <div class="p-6">
    <p class="text-sm font-medium text-mist-400">{{ __('Visitantes únicos hoje') }}</p>
    <p class="mt-1 text-3xl font-bold text-mist-100">{{ number_format($visits['today_unique']) }}</p>
    </div>
   </div>
   <div class="overflow-hidden surface">
    <div class="p-6">
    <p class="text-sm font-medium text-mist-400">{{ __('Visitas (total)') }}</p>
    <p class="mt-1 text-3xl font-bold text-mist-100">{{ number_format($visits['page_views']) }}</p>
    </div>
   </div>
   <div class="overflow-hidden surface">
    <div class="p-6">
    <p class="text-sm font-medium text-mist-400">{{ __('Visitantes únicos (total)') }}</p>
    <p class="mt-1 text-3xl font-bold text-mist-100">{{ number_format($visits['unique_visitors']) }}</p>
    </div>
   </div>
  </div>
  </div>
 </div>
</x-app-layout>


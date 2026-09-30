<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">
  {{ __('Privacidade') }}
  </h2>
 </x-slot>

 <div class="py-12">
  <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
  <div class="overflow-hidden surface">
   <div class="p-6">
    <h3 class="text-lg font-semibold text-mist-100">{{ __('Seus dados') }}</h3>
    <p class="mt-2 text-sm text-mist-300">
    {{ __('Você pode exportar os dados associados à sua conta ou solicitar a exclusão.') }}
    </p>

    <div class="mt-4 flex flex-wrap gap-3">
    <a href="{{ route('account.export') }}"
     class="inline-flex items-center rounded-md bg-ink-700 px-4 py-2 text-sm font-semibold text-white hover:border-signal-500/50">
     {{ __('Exportar meus dados') }}
    </a>
    <a href="{{ route('profile.edit') }}"
     class="inline-flex items-center rounded-md bg-ink-900/70 px-4 py-2 text-sm font-semibold text-mist-300 ring-1 ring-line hover:bg-ink-850/60">
     {{ __('Gerenciar conta') }}
    </a>
    </div>

    <h3 class="mt-8 text-lg font-semibold text-mist-100">{{ __('Contato e solicitações') }}</h3>
    <p class="mt-2 text-sm text-mist-300">
    {{ __('Para dúvidas sobre o tratamento de dados, entre em contato:') }}
    <a href="mailto:{{ config('mail.from.address') }}" class="text-signal-300 underline decoration-signal-500/40 hover:text-signal-300">{{ config('mail.from.address') }}</a>.
    </p>

    <h3 class="mt-8 text-lg font-semibold text-mist-100">{{ __('Documentos') }}</h3>
    <ul class="mt-2 space-y-1 text-sm">
    <li><a href="{{ route('legal.privacy') }}" class="text-signal-300 underline decoration-signal-500/40 hover:text-signal-300">{{ __('Política de Privacidade') }}</a></li>
    <li><a href="{{ route('legal.terms') }}" class="text-signal-300 underline decoration-signal-500/40 hover:text-signal-300">{{ __('Termos de Uso') }}</a></li>
    </ul>
   </div>
  </div>
  </div>
 </div>
</x-app-layout>

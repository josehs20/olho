<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            {{ __('Privacidade') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
            <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('Seus dados') }}</h3>
                    <p class="mt-2 text-sm text-gray-600">
                        {{ __('Você pode exportar os dados associados à sua conta ou solicitar a exclusão.') }}
                    </p>

                    <div class="mt-4 flex flex-wrap gap-3">
                        <a href="{{ route('account.export') }}"
                            class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-700">
                            {{ __('Exportar meus dados') }}
                        </a>
                        <a href="{{ route('profile.edit') }}"
                            class="inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50">
                            {{ __('Gerenciar conta') }}
                        </a>
                    </div>

                    <h3 class="mt-8 text-lg font-semibold text-gray-900">{{ __('Contato e solicitações') }}</h3>
                    <p class="mt-2 text-sm text-gray-600">
                        {{ __('Para dúvidas sobre o tratamento de dados, entre em contato:') }}
                        <a href="mailto:{{ config('mail.from.address') }}" class="text-indigo-600 hover:underline">{{ config('mail.from.address') }}</a>.
                    </p>

                    <h3 class="mt-8 text-lg font-semibold text-gray-900">{{ __('Documentos') }}</h3>
                    <ul class="mt-2 space-y-1 text-sm">
                        <li><a href="{{ route('legal.privacy') }}" class="text-indigo-600 hover:underline">{{ __('Política de Privacidade') }}</a></li>
                        <li><a href="{{ route('legal.terms') }}" class="text-indigo-600 hover:underline">{{ __('Termos de Uso') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

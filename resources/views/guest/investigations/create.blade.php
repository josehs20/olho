<x-public-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Investigação avulsa') }}</h1>
        <p class="mt-1 text-sm text-gray-500">
            {{ __('Sem cadastro: você paga uma vez e acompanha tudo pelo mesmo link de acesso.') }}
        </p>
    </x-slot>

    <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
        <div class="p-6">
            <div class="mb-6 rounded-md bg-gray-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Consulta avulsa — preço fixo') }}</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">
                    {{ __('R$') }} {{ number_format($price->price_cents / 100, 2, ',', '.') }}
                </p>
                <p class="mt-1 text-xs text-gray-500">
                    {{ __('Pagamento único via PIX. Não consome créditos e não exige conta. A execução começa após a confirmação do pagamento.') }}
                </p>
            </div>

            <form method="POST" action="{{ route('guest.investigations.store') }}" class="space-y-6">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">{{ __('Nome') }}</label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700">{{ __('Username') }}</label>
                        <input id="username" name="username" type="text" value="{{ old('username') }}"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        @error('username') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">{{ __('E-mail') }}</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700">{{ __('Telefone') }}</label>
                        <input id="phone" name="phone" type="text" value="{{ old('phone') }}"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        @error('phone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="city" class="block text-sm font-medium text-gray-700">{{ __('Cidade') }}</label>
                        <input id="city" name="city" type="text" value="{{ old('city') }}"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        @error('city') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="state" class="block text-sm font-medium text-gray-700">{{ __('UF') }}</label>
                        <input id="state" name="state" type="text" maxlength="2" value="{{ old('state') }}"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        @error('state') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <p class="text-xs text-gray-500">
                    {{ __('Informe apenas dados públicos. A plataforma consulta fontes abertas e registra as limitações de cada uma.') }}
                    <a href="{{ route('legal.privacy') }}" class="underline">{{ __('Política de privacidade') }}</a>.
                </p>

                <button type="submit"
                    class="inline-flex w-full items-center justify-center rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-700">
                    {{ __('Gerar PIX e iniciar investigação') }}
                </button>
            </form>
        </div>
    </div>
</x-public-layout>

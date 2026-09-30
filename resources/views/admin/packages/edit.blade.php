<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            {{ $package->isGuestKind() ? __('Editar preço da investigação avulsa') : __('Editar pacote') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-lg sm:px-6 lg:px-8">
            @include('admin.partials.nav')

            @if ($package->isGuestKind())
                <p class="mb-4 text-sm text-gray-500">
                    {{ __('Consulta avulsa, sem cadastro: preço fixo pago via PIX, sem consumo de créditos. O novo valor vale para as próximas investigações — as já criadas mantêm o preço registrado.') }}
                </p>
            @endif

            <form method="POST" action="{{ route('admin.packages.update', $package) }}" class="space-y-4 rounded-lg bg-white p-6 shadow-sm">
                @csrf
                @method('PUT')
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Nome') }}</label>
                    <input type="text" name="name" value="{{ old('name', $package->name) }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                @unless ($package->isGuestKind())
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Créditos') }}</label>
                        <input type="number" name="credits" value="{{ old('credits', $package->credits) }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                        @error('credits') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endunless

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Preço em centavos') }}</label>
                    <input type="number" name="price_cents" value="{{ old('price_cents', $package->price_cents) }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                    @error('price_cents') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="active" value="1" @checked(old('active', $package->active)) class="rounded border-gray-300">
                    {{ __('Ativo') }}
                </label>

                <button type="submit" class="rounded-md bg-gray-800 px-4 py-2 text-sm text-white">{{ __('Salvar') }}</button>
            </form>
        </div>
    </div>
</x-app-layout>

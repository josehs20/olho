<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Pacotes') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            @include('admin.partials.nav')

            @if (session('status'))
                <p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>
            @endif

            @if ($errors->any())
                <p class="mb-4 text-sm text-red-600">{{ $errors->first() }}</p>
            @endif

            <div class="mb-8 rounded-lg bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-gray-900">{{ __('Investigação avulsa (sem cadastro)') }}</h3>
                <p class="mt-1 text-sm text-gray-500">
                    {{ __('Preço fixo da consulta avulsa. Não concede créditos: o visitante paga e a investigação é executada uma vez.') }}
                </p>

                @if ($guestPackage === null)
                    <p class="mt-4 text-sm text-amber-700">
                        {{ __('Nenhum preço configurado. Rode o seeder de pacotes — sem ele a jornada pública fica indisponível.') }}
                    </p>
                @else
                    <table class="mt-4 min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-gray-500">{{ __('Nome') }}</th>
                                <th class="px-4 py-2 text-left text-gray-500">{{ __('Preço (R$)') }}</th>
                                <th class="px-4 py-2 text-left text-gray-500">{{ __('Ativo') }}</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr>
                                <td class="px-4 py-3">{{ $guestPackage->name }}</td>
                                <td class="px-4 py-3">{{ number_format($guestPackage->price_cents / 100, 2, ',', '.') }}</td>
                                <td class="px-4 py-3">{{ $guestPackage->active ? __('Sim') : __('Não') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('admin.packages.edit', $guestPackage) }}" class="text-indigo-600 hover:underline">{{ __('Editar preço') }}</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                @endif
            </div>

            <h3 class="text-lg font-semibold text-gray-900">{{ __('Pacotes de créditos') }}</h3>

            <a href="{{ route('admin.packages.create') }}" class="mb-4 mt-2 inline-block rounded-md bg-gray-800 px-3 py-1.5 text-sm text-white">{{ __('Novo pacote') }}</a>

            <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Nome') }}</th>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Créditos') }}</th>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Preço (R$)') }}</th>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Ativo') }}</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($packages as $package)
                            <tr>
                                <td class="px-4 py-3">{{ $package->name }}</td>
                                <td class="px-4 py-3">{{ $package->credits }}</td>
                                <td class="px-4 py-3">{{ number_format($package->price_cents / 100, 2, ',', '.') }}</td>
                                <td class="px-4 py-3">{{ $package->active ? __('Sim') : __('Não') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('admin.packages.edit', $package) }}" class="text-indigo-600 hover:underline">{{ __('Editar') }}</a>
                                    <form method="POST" action="{{ route('admin.packages.destroy', $package) }}" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="ml-2 text-red-600 hover:underline">{{ __('Remover') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>

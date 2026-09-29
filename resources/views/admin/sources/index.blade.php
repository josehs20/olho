<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Fontes') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
            @include('admin.partials.nav')

            @if (session('status'))
                <p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Nome') }}</th>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Slug') }}</th>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Tipo') }}</th>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Custo') }}</th>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Ativo') }}</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($sources as $source)
                            <tr>
                                <td class="px-4 py-3">{{ $source->name }}</td>
                                <td class="px-4 py-3">{{ $source->slug }}</td>
                                <td class="px-4 py-3">{{ $source->type }}</td>
                                <td class="px-4 py-3">{{ $source->cost_credits }}</td>
                                <td class="px-4 py-3">{{ $source->active ? __('Sim') : __('Não') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="{{ route('admin.sources.toggle', $source) }}">
                                        @csrf
                                        <button type="submit" class="text-indigo-600 hover:underline">
                                            {{ $source->active ? __('Desativar') : __('Ativar') }}
                                        </button>
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

<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Investigações') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            @include('admin.partials.nav')

            <div class="mb-4 flex flex-wrap gap-2 text-sm">
                <a href="{{ route('admin.investigations.index') }}"
                    class="rounded-md px-3 py-1.5 {{ $origin === '' ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">{{ __('Todas') }}</a>
                <a href="{{ route('admin.investigations.index', ['origin' => 'account']) }}"
                    class="rounded-md px-3 py-1.5 {{ $origin === 'account' ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">{{ __('Contas') }}</a>
                <a href="{{ route('admin.investigations.index', ['origin' => 'guest']) }}"
                    class="rounded-md px-3 py-1.5 {{ $origin === 'guest' ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">{{ __('Avulsas (sem conta)') }}</a>
            </div>

            <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Origem') }}</th>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Usuário') }}</th>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Título') }}</th>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Preço avulso') }}</th>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Pagamento') }}</th>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Status') }}</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($investigations as $investigation)
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $investigation->isGuest() ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-700' }}">
                                        {{ $investigation->isGuest() ? __('Avulsa') : __('Conta') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ $investigation->user?->email ?? __('Sem conta') }}</td>
                                <td class="px-4 py-3">{{ $investigation->title }}</td>
                                <td class="px-4 py-3">
                                    @if ($investigation->isGuest())
                                        {{ __('R$') }} {{ number_format(((int) $investigation->guest_price_cents) / 100, 2, ',', '.') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $investigation->guestPayment ? __((string) $investigation->guestPayment->status) : '—' }}</td>
                                <td class="px-4 py-3">{{ __((string) $investigation->status) }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('admin.investigations.show', $investigation) }}" class="text-indigo-600 hover:underline">{{ __('Ver') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $investigations->links() }}</div>
        </div>
    </div>
</x-app-layout>

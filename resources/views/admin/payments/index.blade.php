<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Pagamentos') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            @include('admin.partials.nav')

            <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Usuário') }}</th>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Valor') }}</th>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Status') }}</th>
                            <th class="px-4 py-2 text-left text-gray-500">{{ __('Data') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($payments as $payment)
                            <tr>
                                <td class="px-4 py-3">{{ $payment->user?->email }}</td>
                                <td class="px-4 py-3">{{ __('R$') }} {{ number_format($payment->amount_cents / 100, 2, ',', '.') }}</td>
                                <td class="px-4 py-3">{{ $payment->status }}</td>
                                <td class="px-4 py-3">{{ $payment->created_at->format('d/m/Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $payments->links() }}</div>
        </div>
    </div>
</x-app-layout>

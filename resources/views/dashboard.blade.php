<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            <div class="mb-6 flex flex-wrap gap-4">
                <a href="{{ route('investigations.create') }}"
                    class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-700">
                    {{ __('Nova investigação') }}
                </a>
                <a href="{{ route('payments.index') }}"
                    class="inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50">
                    {{ __('Comprar créditos') }}
                </a>
            </div>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <p class="text-sm font-medium text-gray-500">{{ __('Saldo de créditos') }}</p>
                        <p class="mt-1 text-3xl font-bold text-gray-900">{{ number_format($balance) }}</p>
                    </div>
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <p class="text-sm font-medium text-gray-500">{{ __('Créditos gastos') }}</p>
                        <p class="mt-1 text-3xl font-bold text-gray-900">{{ number_format($creditsSpent) }}</p>
                    </div>
                </div>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900">{{ __('Investigações recentes') }}</h3>
                        @if ($recentInvestigations->isEmpty())
                            <p class="mt-3 text-sm text-gray-500">{{ __('Você ainda não tem investigações.') }}</p>
                        @else
                            <ul class="mt-3 divide-y divide-gray-200">
                                @foreach ($recentInvestigations as $investigation)
                                    <li class="flex items-center justify-between py-3">
                                        <a href="{{ route('investigations.show', $investigation) }}" class="font-medium text-gray-900 hover:underline">
                                            {{ $investigation->title }}
                                        </a>
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold bg-gray-100 text-gray-700">
                                            {{ __((string) $investigation->status) }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900">{{ __('Pagamentos recentes') }}</h3>
                        @if ($recentPayments->isEmpty())
                            <p class="mt-3 text-sm text-gray-500">{{ __('Nenhum pagamento ainda.') }}</p>
                        @else
                            <ul class="mt-3 divide-y divide-gray-200">
                                @foreach ($recentPayments as $payment)
                                    <li class="flex items-center justify-between py-3">
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">
                                                {{ __('R$') }} {{ number_format($payment->amount_cents / 100, 2, ',', '.') }}
                                            </p>
                                            <p class="text-xs text-gray-500">{{ $payment->created_at->format('d/m/Y H:i') }}</p>
                                        </div>
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold bg-gray-100 text-gray-700">
                                            {{ __((string) $payment->status) }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>


<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            {{ __('Pagamento') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
            <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">{{ __('Pedido') }} #{{ $payment->id }}</h3>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $payment->status === 'paid' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                            {{ $payment->status }}
                        </span>
                    </div>

                    <p class="mt-2 text-sm text-gray-500">
                        {{ __('Valor') }}: {{ __('R$') }} {{ number_format($payment->amount_cents / 100, 2, ',', '.') }}
                    </p>

                    @if ($payment->status !== 'paid')
                        <div class="mt-6 rounded-md bg-gray-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('PIX copia e cola') }}</p>
                            <p class="mt-2 break-all font-mono text-sm text-gray-800">{{ $payment->metadata['pix_payload'] ?? '' }}</p>
                        </div>

                        @if ($isFake)
                            <form method="POST" action="{{ route('payments.simulate', $payment) }}" class="mt-6">
                                @csrf
                                <button type="submit"
                                    class="inline-flex w-full items-center justify-center rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-700">
                                    {{ __('Simular pagamento (provider fake)') }}
                                </button>
                            </form>
                        @endif
                    @else
                        <p class="mt-4 text-sm text-green-700">{{ __('Pagamento confirmado. Créditos adicionados à sua carteira.') }}</p>
                    @endif

                    <a href="{{ route('payments.index') }}" class="mt-6 inline-block text-sm text-gray-600 hover:text-gray-900">
                        &larr; {{ __('Voltar aos pacotes') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

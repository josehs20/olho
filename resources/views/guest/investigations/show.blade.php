<x-public-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Acompanhamento da investigação avulsa') }}</h1>
        <p class="mt-1 text-sm text-gray-500">
            {{ __('Guarde este endereço: ele é a única forma de acessar este resultado.') }}
        </p>
    </x-slot>

    <div class="mb-4 rounded-md bg-white p-4 text-sm text-gray-700 shadow-sm">
        {{ __('Investigação') }}: <span class="font-medium">{{ $investigation->title }}</span>
        · {{ __('Situação') }}: {{ __((string) $investigation->status) }}
        · {{ __('Criada em') }} {{ $investigation->created_at?->format('d/m/Y H:i') }}
    </div>

    @if (session('status'))
        <p class="mb-4 rounded-md bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</p>
    @endif

    <div class="space-y-6">
        <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div class="p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('Pagamento') }}</h2>
                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $payment?->status === 'paid' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                        {{ __((string) ($payment?->status ?? 'pending')) }}
                    </span>
                </div>

                <p class="mt-2 text-sm text-gray-500">
                    {{ __('Valor') }}:
                    {{ __('R$') }} {{ number_format(((int) ($payment?->amount_cents ?? $investigation->guest_price_cents)) / 100, 2, ',', '.') }}
                </p>

                @if ($payment === null)
                    <p class="mt-4 text-sm text-red-600">{{ __('Pedido não encontrado para esta investigação.') }}</p>
                @elseif ($payment->status !== 'paid')
                    <div class="mt-4 rounded-md bg-gray-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('PIX copia e cola') }}</p>
                        <p class="mt-2 break-all font-mono text-sm text-gray-800">{{ data_get($payment->metadata, 'pix_payload', '') }}</p>
                    </div>

                    @if ($isFake)
                        <form method="POST" action="{{ route('guest.payments.simulate', ['token' => $token]) }}" class="mt-4">
                            @csrf
                            <button type="submit"
                                class="inline-flex w-full items-center justify-center rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-700">
                                {{ __('Simular pagamento (provider fake)') }}
                            </button>
                        </form>
                    @endif
                @else
                    <p class="mt-4 text-sm text-green-700">{{ __('Pagamento confirmado.') }}</p>
                @endif

                @if ($investigation->status === 'expired')
                    <p class="mt-4 text-sm text-red-600">
                        {{ __('Este pedido expirou sem pagamento e não foi executado.') }}
                    </p>
                @endif
            </div>
        </section>

        <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div class="p-6">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('Execução') }}</h2>

                <p class="mt-2 text-sm text-gray-600">
                    {{ __('Status') }}:
                    <span id="guest-status" class="font-medium">{{ __((string) $progress['status']) }}</span>
                    @if ($progress['run'])
                        · {{ __('Run') }} #{{ $progress['run']['id'] }} ({{ __((string) $progress['run']['status']) }})
                    @endif
                </p>

                @if ($progress['run'] === null)
                    <p class="mt-3 text-sm text-gray-500">
                        {{ __('A execução começa automaticamente após a confirmação do pagamento.') }}
                    </p>
                @else
                    @if ($progress['run']['error_summary'])
                        <p class="mt-3 text-sm text-amber-700">{{ $progress['run']['error_summary'] }}</p>
                    @endif

                    @if ($progress['sources'] !== [])
                        <table class="mt-4 min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left text-gray-500">{{ __('Módulo') }}</th>
                                    <th class="px-3 py-2 text-left text-gray-500">{{ __('Situação') }}</th>
                                    <th class="px-3 py-2 text-left text-gray-500">{{ __('Resultados') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($progress['sources'] as $source)
                                    <tr>
                                        <td class="px-3 py-2 text-gray-800">{{ $source['name'] }}</td>
                                        <td class="px-3 py-2 text-gray-600">{{ __($source['display_status']) }}</td>
                                        <td class="px-3 py-2 text-gray-600">{{ $source['results'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endif

                @if ($progress['report_available'])
                    <a href="{{ $reportUrl }}"
                        class="mt-4 inline-block rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700">
                        {{ __('Ver relatório') }}
                    </a>
                @endif
            </div>
        </section>

        <p class="text-xs text-gray-500">
            {{ __('Uma correspondência possível não é identidade confirmada. O relatório indica as fontes e as limitações de cada consulta.') }}
        </p>
    </div>

    @push('scripts')
        <script>
            (function () {
                var statusUrl = @json($statusUrl);
                var initialStatus = @json($progress['status']);
                var finished = ['completed', 'partial', 'failed', 'cancelled', 'expired'];
                var attempts = 0;

                var timer = window.setInterval(function () {
                    attempts++;

                    fetch(statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                        .then(function (response) { return response.ok ? response.json() : null; })
                        .then(function (data) {
                            if (! data) { return; }

                            var status = (data.progress && data.progress.status) || null;
                            var paid = data.payment && data.payment.status === 'paid';

                            if (status && finished.indexOf(status) !== -1) {
                                window.clearInterval(timer);
                                window.location.reload();

                                return;
                            }

                            if (paid && initialStatus !== 'running') {
                                window.clearInterval(timer);
                                window.location.reload();
                            }
                        })
                        .catch(function () {});

                    if (attempts >= 240) {
                        window.clearInterval(timer);
                    }
                }, 5000);
            })();
        </script>
    @endpush
</x-public-layout>

<x-public-layout :title="__('Pagamento')">
    <x-slot name="header">
        <p class="eyebrow">{{ __('Pagamento') }}</p>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-mist-50 sm:text-3xl">
            {{ __('Status do pagamento') }}
        </h1>
    </x-slot>

    <div class="mx-auto max-w-xl">
        <div class="surface p-6 sm:p-7">
            <p class="text-sm text-mist-400">{{ __('Pedido') }} #{{ $payment->id }}</p>
            <p class="mt-1 text-sm text-mist-400">
                {{ __('Valor') }}: {{ __('R$') }} {{ number_format($payment->amount_cents / 100, 2, ',', '.') }}
            </p>

            <p class="mt-4 text-lg font-semibold text-mist-100">
                @if ($status === 'paid')
                    {{ __('Pagamento confirmado') }}
                @elseif ($status === 'failed' || $status === 'cancelled')
                    {{ __('Pagamento não concluído') }}
                @elseif ($status === 'expired')
                    {{ __('Pagamento expirado') }}
                @else
                    {{ __('Aguardando confirmação do pagamento') }}
                @endif
            </p>

            <p class="mt-2 text-xs leading-relaxed text-mist-500">
                {{ __('Esta página confere o pagamento no PagBank: se ele já estiver aprovado, os créditos (ou a investigação avulsa) são liberados agora. A confirmação também chega pela notificação do provedor.') }}
            </p>
        </div>
    </div>
</x-public-layout>

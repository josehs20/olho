<x-card eyebrow="{{ __('Pagamento') }}" title="{{ $isPaid ? __('Pagamento confirmado') : __('Aguardando confirmação do PIX') }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-3xl font-semibold tabular-nums text-mist-50">
            {{ __('R$') }} {{ number_format(((int) ($payment?->amount_cents ?? $investigation->guest_price_cents)) / 100, 2, ',', '.') }}
        </p>
        <x-status-badge :status="$paymentStatus ?? 'draft'" />
    </div>

    @if ($payment === null)
        <x-alert tone="danger" class="mt-4">{{ __('Pedido não encontrado para esta investigação.') }}</x-alert>
    @elseif ($isPaid)
        <p class="mt-4 text-sm text-emerald-300">{{ __('A investigação foi liberada e executada em fontes públicas.') }}</p>
    @else
        <div class="mt-5 rounded-xl border border-line bg-ink-950/60 p-4">
            <p class="eyebrow">{{ __('PIX copia e cola') }}</p>
            <p id="pix-payload" class="mt-3 break-all font-mono text-xs leading-relaxed text-mist-200">{{ data_get($payment->metadata, 'pix_payload', '') }}</p>

            <div class="mt-3 flex flex-wrap gap-2">
                <x-btn type="button" variant="outline" size="sm" id="copy-pix">{{ __('Copiar código PIX') }}</x-btn>
            </div>

            @if ($isExpired)
                <p class="mt-4 text-sm text-rose-200">{{ __('Este pedido expirou sem pagamento e a investigação não foi executada.') }}</p>
            @elseif ($isFake)
                <form method="POST" action="{{ route('guest.payments.simulate', ['token' => $token]) }}" class="mt-4">
                    @csrf
                    <x-btn type="submit" variant="primary" class="w-full">{{ __('Simular pagamento (provider fake)') }}</x-btn>
                </form>
            @endif
        </div>
    @endif
</x-card>

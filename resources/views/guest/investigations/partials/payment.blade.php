<x-card eyebrow="{{ __('Pagamento único') }}" :title="$isPaid ? __('O Olho começou a procurar') : __('Sua investigação está pronta para começar')">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-3xl font-semibold tabular-nums text-mist-50">
            {{ __('R$') }} {{ number_format(((int) ($payment?->amount_cents ?? $investigation->guest_price_cents)) / 100, 2, ',', '.') }}
        </p>
        <x-status-badge :status="$paymentStatus ?? 'draft'" />
    </div>

    @if ($payment === null)
        <x-alert tone="danger" class="mt-4">{{ __('Pedido não encontrado para esta investigação.') }}</x-alert>
    @elseif ($isPaid)
        <p class="mt-4 text-sm text-emerald-300">{{ __('O Olho está procurando sinais públicos para esta pista. Cada etapa aparece abaixo em tempo real.') }}</p>
    @else
        <p class="mt-3 text-xs leading-relaxed text-mist-400">{{ __('Pagamento único. Sem assinatura. Sem cadastro obrigatório.') }}</p>

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
                    <x-btn type="submit" variant="primary" class="w-full">{{ __('LIBERAR INVESTIGAÇÃO') }}</x-btn>
                    <p class="mt-2 text-center text-[0.6875rem] text-mist-500">{{ __('Confirmação simulada enquanto o provider está em modo fake.') }}</p>
                </form>
            @endif
        </div>
    @endif
</x-card>

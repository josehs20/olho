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

        @if ($checkoutUrl && ! $isFake)
            {{-- PagBank: o cliente paga no ambiente seguro do provedor, em **outra
                 aba** — esta página (que é o acesso dele à investigação) continua
                 aberta para ele voltar e ver o resultado. --}}
            @if ($isExpired)
                <p class="mt-4 text-sm text-rose-200">{{ __('Este pedido expirou sem pagamento e a investigação não foi executada.') }}</p>
            @else
                <div class="mt-5 rounded-xl border border-line bg-ink-950/60 p-4">
                    <p class="eyebrow">{{ __('Pagamento via PagBank') }}</p>
                    <p class="mt-3 text-sm leading-relaxed text-mist-300">
                        {{ __('Você será direcionado ao ambiente seguro do PagBank, em outra aba — esta página continua aberta para você acompanhar a investigação.') }}
                    </p>
                    <x-btn href="{{ $checkoutUrl }}" target="_blank" rel="noopener noreferrer" variant="primary" class="mt-4 w-full">
                        {{ __('PAGAR COM PAGBANK') }}
                    </x-btn>
                    <p class="mt-2 text-center text-[0.6875rem] text-mist-500">
                        {{ __('A investigação é liberada quando o PagBank confirma o pagamento.') }}
                    </p>
                </div>
            @endif
        @else
            <div class="mt-5 rounded-xl border border-line bg-ink-950/60 p-4">
                <p class="eyebrow">{{ __('PIX copia e cola') }}</p>
                <p id="pix-payload" class="mt-3 break-all font-mono text-xs leading-relaxed text-mist-200">{{ data_get($payment->metadata, 'pix_payload', '') }}</p>

                <div class="mt-3 flex flex-wrap gap-2">
                    <x-btn type="button" variant="outline" size="sm" id="copy-pix">{{ __('Copiar código PIX') }}</x-btn>
                </div>

                @unless ($isExpired)
                    {{-- A página consulta o servidor em segundo plano e recarrega
                         quando o PagBank confirma (o servidor reconfere tudo). --}}
                    <div class="mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-line bg-ink-900/40 px-3 py-3">
                        <span class="badge badge-warning animate-pulse">{{ __('Aguardando') }}</span>
                        <span class="text-sm text-mist-300">{{ __('Aguardando o pagamento...') }}</span>
                    </div>
                @endunless

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

        {{-- Fallback explícito para o webhook que não chega: o visitante pede a
             verificação e o servidor consulta o PagBank antes de liberar. --}}
        @if (! $isFake && ! $isExpired)
            <form method="POST" action="{{ route('guest.payments.verify', ['token' => $token]) }}" class="mt-4">
                @csrf
                <x-btn type="submit" variant="outline" class="w-full">{{ __('JÁ PAGUEI — VERIFICAR AGORA') }}</x-btn>
                <p class="mt-2 text-center text-[0.6875rem] text-mist-500">{{ __('Consulta o PagBank na hora: se o pagamento já foi aprovado, a investigação é liberada.') }}</p>
            </form>
        @endif
    @endif
</x-card>

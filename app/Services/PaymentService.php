<?php

namespace App\Services;

use App\Jobs\ReconcilePaymentJob;
use App\Models\CreditPackage;
use App\Models\Investigation;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\CheckoutProvider;
use App\Payments\DirectPixProvider;
use App\Payments\FakePixProvider;
use App\Payments\PagBank\PagBankException;
use App\Payments\PaymentChannel;
use App\Payments\PaymentMethod;
use App\Payments\PaymentProvider;
use App\Payments\PaymentSettings;
use App\Payments\PaymentStatus;
use App\Payments\PaymentUnavailableException;
use App\Payments\VerifiedPaymentEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly CreditLedgerService $credits,
        private readonly GuestInvestigationService $guests,
        private readonly PaymentSettings $settings,
    ) {}

    /**
     * Cria o pedido e inicia a cobrança pelo canal escolhido na tela.
     *
     * Sem canal (avulsa, chamadas antigas) vale o comportamento do provedor:
     * checkout completo quando ele redireciona, PIX copia-e-cola quando ele
     * emite o payload.
     *
     * Nenhum dado do comprador entra aqui: nome, CPF/CNPJ e e-mail são colhidos
     * pela própria página hospedada do provedor.
     */
    public function checkout(User $user, CreditPackage $package, ?PaymentChannel $channel = null): Payment
    {
        $this->ensureProviderReady();

        if ($channel !== null && ! in_array($channel, $this->availableChannels(), true)) {
            throw PaymentUnavailableException::channelUnavailable();
        }

        if (! $package->active) {
            throw new \InvalidArgumentException('Pacote inativo.');
        }

        if ($package->isGuestKind()) {
            throw new \InvalidArgumentException('O preço da investigação avulsa não é vendido como pacote de créditos.');
        }

        try {
            return DB::transaction(function () use ($user, $package, $channel) {
                $payment = Payment::query()->create([
                    'user_id' => $user->id,
                    'credit_package_id' => $package->id,
                    'provider' => $this->settings->provider(),
                    'amount_cents' => $package->price_cents,
                    'status' => PaymentStatus::Pending->value,
                    'idempotency_key' => (string) Str::uuid(),
                ]);

                return $this->startCharge($payment, $channel);
            });
        } catch (PagBankException $exception) {
            $this->rememberChargeFailure($exception);

            throw $exception;
        }
    }

    /**
     * Cria o pedido e a cobrança da investigação avulsa (prompt 41).
     *
     * Preço fixo pago antes, **sem consumo de créditos**: "pagou, usou, acabou".
     * Idempotente por investigação — uma avulsa tem no máximo um pedido.
     *
     * Sem dados do pagador: a cobrança é um checkout hospedado e quem coleta
     * nome/CPF/CNPJ é a página do provedor.
     */
    public function checkoutGuest(Investigation $investigation, CreditPackage $package): Payment
    {
        if (! $investigation->isGuest()) {
            throw new \InvalidArgumentException('A investigação informada não é avulsa.');
        }

        if ($investigation->status !== Investigation::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Esta investigação avulsa não está aguardando pagamento.');
        }

        if (! $package->isGuestKind() || ! $package->active) {
            throw new \InvalidArgumentException('Preço da investigação avulsa indisponível.');
        }

        $this->ensureProviderReady();

        try {
            return DB::transaction(function () use ($investigation, $package) {
                $existing = Payment::query()
                    ->where('guest_investigation_id', $investigation->id)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                $payment = Payment::query()->create([
                    'user_id' => null,
                    'guest_investigation_id' => $investigation->id,
                    'credit_package_id' => $package->id, // rastreabilidade do preço aplicado
                    'provider' => $this->settings->provider(),
                    'amount_cents' => (int) ($investigation->guest_price_cents ?? $package->price_cents),
                    'status' => PaymentStatus::Pending->value,
                    'idempotency_key' => 'guest-investigation-'.$investigation->id,
                ]);

                return $this->startCharge($payment);
            });
        } catch (PagBankException $exception) {
            $this->rememberChargeFailure($exception);

            throw $exception;
        }
    }

    /**
     * Guarda o motivo da recusa do provedor para o painel do administrador.
     *
     * Precisa acontecer **depois** do rollback: gravado dentro da transação, o
     * diagnóstico seria revertido junto com o pedido que não chegou a existir — e
     * é justamente quando a cobrança falha que o operador precisa dessa
     * informação.
     */
    private function rememberChargeFailure(PagBankException $exception): void
    {
        try {
            $this->settings->rememberCheckoutFailure($exception->getMessage());
        } catch (\Throwable) {
            // O diagnóstico no painel é acessório: nunca pode esconder a falha
            // real (já registrada no log pelo provedor).
            Log::warning('payment.pagbank.failure_not_recorded');
        }
    }

    /**
     * Cria a cobrança no provedor e marca o pedido como aguardando pagamento.
     *
     * Três caminhos, decididos por capacidade e nunca por nome de provedor:
     * 1. canal PIX + provedor que emite o QR Code na aplicação → copia e cola;
     * 2. provedor de checkout → link de redirecionamento (cartão, ou fallback);
     * 3. provedor simples → `createCharge()` (mock).
     */
    public function startCharge(Payment $payment, ?PaymentChannel $channel = null): Payment
    {
        $this->ensureProviderReady();

        if ($channel === PaymentChannel::Pix && $this->provider instanceof DirectPixProvider) {
            return $this->startPixCharge($payment);
        }

        if ($this->provider instanceof CheckoutProvider) {
            return $this->startCheckout($payment, $channel);
        }

        if ($channel === PaymentChannel::Card) {
            // Sem checkout não há como cobrar cartão: melhor recusar do que
            // entregar um PIX disfarçado de cartão.
            throw PaymentUnavailableException::channelUnavailable();
        }

        return $this->startPixCharge($payment);
    }

    /**
     * PIX copia-e-cola emitido na própria aplicação (provedor direto ou mock).
     */
    private function startPixCharge(Payment $payment): Payment
    {
        try {
            $charge = $this->provider->createCharge($payment);
        } catch (PagBankException $exception) {
            // Provedor direto indisponível: o comprador não fica sem cobrança —
            // cai no checkout hospedado, restrito a PIX. A falha é registrada sem
            // payload e sem dado do comprador.
            if (! $this->provider instanceof CheckoutProvider) {
                throw $exception;
            }

            Log::warning('payment.pix_direct.unavailable', [
                'payment_id' => $payment->id,
                'provider' => $this->settings->provider(),
                'reason' => $exception->getMessage(),
            ]);

            return $this->startCheckout($payment, PaymentChannel::Pix);
        }

        $payment->external_id = $charge->externalId;
        // A cobrança direta é sempre um payload PIX (contrato `PixCharge`): aqui o
        // método é conhecido desde a criação, ao contrário do checkout, onde o
        // comprador escolhe na tela do provedor.
        $payment->method = PaymentMethod::Pix;
        $payment->status = PaymentStatus::AwaitingPayment->value;
        $payment->expires_at = $charge->expiresAt ?? $payment->expires_at;
        $payment->metadata = array_merge($payment->metadata ?? [], [
            'pix_payload' => $charge->payload,
            'pix_qr_code' => $charge->qrCode,
        ]);
        $payment->save();

        return $payment;
    }

    /**
     * Cria o checkout no provedor (PagBank) e guarda o link de redirecionamento.
     *
     * O comprador é identificado na página hospedada do provedor: o OLHO não
     * envia `customer` nem qualquer dado pessoal de quem paga.
     */
    private function startCheckout(Payment $payment, ?PaymentChannel $channel = null): Payment
    {
        /** @var CheckoutProvider $provider */
        $provider = $this->provider;

        $methods = $this->methodsFor($channel);

        $session = $provider->createCheckout($payment, [
            'methods' => $methods,
            'max_installments' => $this->settings->maxInstallments(),
            'pix_expiration_minutes' => $this->settings->pixExpirationMinutes(),
        ]);

        $payment->external_id = $session->externalId;
        $payment->provider_checkout_id = $session->externalId;
        $payment->checkout_url = $session->redirectUrl;
        $payment->environment = $this->settings->environment();
        $payment->status = PaymentStatus::AwaitingPayment->value;
        $payment->expires_at = $session->expiresAt ?? $payment->expires_at;
        $payment->metadata = array_merge($payment->metadata ?? [], [
            'pagbank' => $session->raw,
            'checkout_url' => $session->redirectUrl,
            // Auditoria: o que foi oferecido no checkout (o escolhido chega depois,
            // quando o comprador paga).
            'methods_offered' => $methods,
        ]);
        $payment->save();

        return $payment;
    }

    /**
     * Métodos oferecidos no checkout para o canal escolhido na tela.
     *
     * `null` (avulsa legada, chamadas antigas) mantém todos os métodos
     * habilitados, como sempre; PIX restringe a PIX (usado no fallback) e o
     * cartão oferece crédito e/ou débito habilitados — a escolha final entre
     * eles é do comprador na tela do PagBank.
     *
     * @return array<int, string>
     */
    private function methodsFor(?PaymentChannel $channel): array
    {
        if ($channel === PaymentChannel::Pix) {
            return $this->settings->pixEnabled() ? [PaymentMethod::Pix->value] : [];
        }

        if ($channel === PaymentChannel::Card) {
            $methods = [];

            if ($this->settings->creditEnabled()) {
                $methods[] = PaymentMethod::Credit->value;
            }

            if ($this->settings->debitEnabled()) {
                $methods[] = PaymentMethod::Debit->value;
            }

            return $methods;
        }

        return $this->settings->enabledMethods();
    }

    /**
     * Consulta o status do pedido (no provider, quando aplicável).
     */
    public function status(Payment $payment): PaymentStatus
    {
        if ($payment->status === PaymentStatus::AwaitingPayment->value && $payment->external_id) {
            return $this->provider->getStatus($payment->external_id);
        }

        return PaymentStatus::from($payment->status);
    }

    /**
     * O subsistema de pagamento está pronto para iniciar uma cobrança?
     *
     * Provedores de checkout (PagBank) exigem token **e** ao menos uma forma de
     * pagamento habilitada: sem isso o gateway recusaria o checkout e o cliente
     * veria um erro opaco.
     */
    public function available(): bool
    {
        if (! $this->settings->enabled()) {
            return false;
        }

        if ($this->settings->provider() === PaymentSettings::PROVIDER_PAGBANK) {
            return $this->settings->token() !== null && $this->settings->enabledMethods() !== [];
        }

        return true;
    }

    /**
     * Canais de pagamento que podem ser oferecidos na tela (botões PIX/Cartão).
     *
     * @return array<int, PaymentChannel>
     */
    public function availableChannels(): array
    {
        $channels = [];

        if ($this->acceptsPix()) {
            $channels[] = PaymentChannel::Pix;
        }

        if ($this->acceptsCard()) {
            $channels[] = PaymentChannel::Card;
        }

        return $channels;
    }

    /**
     * PIX está disponível?
     *
     * Vale para provedores que emitem o copia-e-cola na aplicação (mock de
     * desenvolvimento) **e** para os de checkout, que oferecem PIX hospedado —
     * caminho usado para toda cobrança PIX em produção.
     */
    public function acceptsPix(): bool
    {
        if (! $this->available() || ! $this->settings->pixEnabled()) {
            return false;
        }

        return $this->provider instanceof DirectPixProvider
            || $this->provider instanceof CheckoutProvider;
    }

    /**
     * Cartão está disponível?
     *
     * Só existe no checkout hospedado: o OLHO nunca recebe nem armazena dados de
     * cartão — o comprador digita tudo na tela do provedor.
     */
    public function acceptsCard(): bool
    {
        if (! $this->available() || ! $this->provider instanceof CheckoutProvider) {
            return false;
        }

        return $this->settings->creditEnabled() || $this->settings->debitEnabled();
    }

    /**
     * Reconcilia o pedido com o provedor e confirma o pagamento quando ele já
     * consta como aprovado.
     *
     * Caminho de segurança para quando a notificação não chega — retorno do
     * checkout, ambiente local sem URL pública, reentrega perdida — sempre a
     * partir da consulta ao provedor: o que o navegador informa nunca decide
     * nada. A confirmação é idempotente, então repetir a consulta não libera
     * créditos nem executa a investigação duas vezes.
     */
    public function reconcile(Payment $payment): PaymentStatus
    {
        // Pedido criado por outro provedor (troca no painel) ou sem cobrança
        // criada: não há o que reconciliar — vale o estado persistido.
        if ($payment->provider !== $this->settings->provider() || $payment->external_id === null) {
            return PaymentStatus::from($payment->status);
        }

        $status = $this->status($payment);

        if ($status !== PaymentStatus::Paid || $payment->status === PaymentStatus::Paid->value) {
            return $status;
        }

        $this->confirmPayment(new VerifiedPaymentEvent(
            externalEventId: 'reconcile-paid-'.$payment->external_id,
            externalId: $payment->external_id,
            status: PaymentStatus::Paid->value,
            method: $this->providerMethod($payment),
        ));

        return $status;
    }

    /**
     * Reconciliação do polling da página, com janela mínima entre consultas.
     *
     * A página do pedido consulta o estado a cada 5s. Reconsultar o provedor em
     * cada volta multiplicaria chamadas da API por comprador sem ganho real: o
     * webhook continua sendo o gatilho normal e o botão "Já paguei" consulta na
     * hora, sem janela. Aqui a consulta é feita no máximo uma vez por
     * `payments.reconcile_cooldown_seconds`; dentro da janela vale o estado
     * persistido (que o webhook/worker mantém atualizado).
     */
    public function reconcileThrottled(Payment $payment, ?int $seconds = null): PaymentStatus
    {
        if ($payment->status !== PaymentStatus::AwaitingPayment->value) {
            return PaymentStatus::from($payment->status);
        }

        $seconds = max(1, $seconds ?? (int) config('payments.reconcile_cooldown_seconds', 20));

        try {
            // `add` é atômico: só quem chega primeiro consulta o provedor.
            if (! Cache::add('payment:reconcile:'.$payment->getKey(), true, $seconds)) {
                return PaymentStatus::from($payment->status);
            }
        } catch (\Throwable) {
            // Cache indisponível não pode impedir a confirmação: reconsulta.
        }

        return $this->reconcile($payment);
    }

    /**
     * Pagamentos PagBank que ainda aguardam confirmação, do mais antigo para o
     * mais novo.
     *
     * Candidatos à sincronização forçada quando a notificação não chega
     * (ambiente local sem URL pública, reentrega perdida, comprador que fecha a
     * tela antes de voltar). Só entram pedidos que já têm cobrança criada no
     * provedor (`external_id`): sem isso não há o que consultar.
     *
     * @return Collection<int, Payment>
     */
    public function pendingPagBank(int $limit = 25): Collection
    {
        return Payment::query()
            ->where('provider', PaymentSettings::PROVIDER_PAGBANK)
            ->where('status', PaymentStatus::AwaitingPayment->value)
            ->whereNotNull('external_id')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Consulta vários pagamentos PagBank pendentes de uma vez (varredura).
     *
     * Uma falha de consulta em um pedido não interrompe os demais: cada linha
     * volta individualmente, com o erro, para o chamador decidir o que exibir.
     * A confirmação continua sendo a mesma de `reconcile()` — idempotente e
     * sempre baseada na resposta do provedor.
     *
     * @return array<int, array{payment: Payment, provider_status: PaymentStatus|null, error: string|null}>
     */
    public function reconcilePendingPagBank(int $limit = 25): array
    {
        $results = [];

        foreach ($this->pendingPagBank($limit) as $payment) {
            try {
                $status = $this->reconcile($payment);
                $payment->refresh();

                $results[] = ['payment' => $payment, 'provider_status' => $status, 'error' => null];
            } catch (\Throwable $exception) {
                // Falha de rede/credencial em um pedido não invalida a varredura:
                // o pedido segue pendente e o operador vê a mensagem do provedor.
                Log::warning('payment.sync.failed', [
                    'payment_id' => $payment->id,
                    'provider' => $payment->provider,
                    'context' => 'sincronização em lote',
                    'error' => $exception->getMessage(),
                ]);

                $results[] = ['payment' => $payment, 'provider_status' => null, 'error' => $exception->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Agenda a verificação de um pedido no worker (fila).
     *
     * Caminho único de quem precisa reconsultar o provedor **fora** da requisição
     * (webhook que não concluiu e botão do painel): o job reconsulta o PagBank e
     * libera créditos/investigação avulsa pelo mesmo `reconcile()` idempotente.
     *
     * Um minuto de atraso evita reconsultar no mesmo instante em que a consulta
     * anterior falhou (provedor instável) ou em que o pedido do checkout ainda
     * não apareceu — e deixa o comprador, que acabou de pagar, sem espera longa.
     */
    public function queueReconcile(Payment $payment, string $reason = 'admin'): void
    {
        ReconcilePaymentJob::dispatch($payment->id, $reason)->delay(now()->addMinute());
    }

    /**
     * Agenda a verificação de todos os pendentes PagBank (teto por chamada).
     *
     * Mesmo conjunto de candidatos da varredura síncrona (`pendingPagBank()`),
     * mas pelo worker: a requisição do navegador termina na hora, sem esperar a
     * API do provedor. Devolve quantos pedidos foram agendados.
     */
    public function queuePendingPagBank(int $limit = 25): int
    {
        $queued = 0;

        foreach ($this->pendingPagBank($limit) as $payment) {
            $this->queueReconcile($payment, 'admin-bulk');
            $queued++;
        }

        return $queued;
    }

    /**
     * Método de pagamento informado pelo provedor (quando já conhecido).
     */
    private function providerMethod(Payment $payment): ?string
    {
        if ($payment->method !== null || $payment->external_id === null) {
            return $payment->method?->value;
        }

        if (! $this->provider instanceof CheckoutProvider) {
            return null;
        }

        try {
            return $this->provider->getCheckoutMethod($payment->external_id);
        } catch (\Throwable) {
            // Dado acessório: a ausência do método nunca impede a confirmação.
            Log::info('payment.reconcile.method_unavailable', ['payment_id' => $payment->id]);

            return null;
        }
    }

    /**
     * Aborta a cobrança quando a integração está desativada ou incompleta.
     */
    private function ensureProviderReady(): void
    {
        if ($this->available()) {
            return;
        }

        Log::warning('payment.unavailable', [
            'provider' => $this->settings->provider(),
            'enabled' => $this->settings->enabled(),
            'has_token' => $this->settings->token() !== null,
            'methods' => $this->settings->enabledMethods(),
        ]);

        throw $this->settings->enabled()
            ? PaymentUnavailableException::misconfigured()
            : PaymentUnavailableException::disabled();
    }

    /**
     * Processa um webhook do gateway (valida, deduplica e confirma pagamento).
     *
     * O webhook é o mecanismo normal de confirmação, e também o gatilho da
     * verificação no worker quando ele **chega e não conclui**:
     *
     * - erro ao consultar o provedor depois de autenticar a notificação (rede,
     *   5xx, 429): a exceção sobe (o PagBank reentrega a notificação) **e** o
     *   pedido é agendado no worker, para não depender só da reentrega;
     * - notificação autenticada que não liberou nada (status ainda aguardando):
     *   o provedor pode estar atrasado (o pedido do checkout demora a aparecer,
     *   a consulta degradou), então o worker confere de novo dentro de instantes.
     *
     * Nos dois casos a confirmação continua sendo a de `reconcile()`/`confirmPayment`:
     * idempotente e baseada apenas na resposta do provedor.
     */
    public function handleWebhook(array $payload, array $headers, ?string $rawBody = null): ?Payment
    {
        try {
            $event = $this->provider->validateWebhook($payload, $headers, $rawBody);
        } catch (\Throwable $exception) {
            $this->scheduleReconcileAfterWebhookFailure($payload, $exception);

            throw $exception;
        }

        if ($event === null) {
            return null;
        }

        $payment = $this->confirmPayment($event);

        if ($event->status === PaymentStatus::AwaitingPayment->value
            && $payment->status !== PaymentStatus::Paid->value) {
            Log::info('payment.webhook.inconclusive', [
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'provider_reference_id' => $event->externalId,
            ]);

            $this->queueReconcile($payment, 'webhook-inconclusive');
        }

        return $payment;
    }

    /**
     * Agenda a verificação depois de uma falha na rota do webhook.
     *
     * A notificação já foi autenticada (a assinatura é validada antes de qualquer
     * consulta), mas o objeto notificado não pôde ser consultado agora. O
     * `Payment` é localizado pelo id que veio do provedor — o mesmo gravado em
     * `external_id`/`provider_checkout_id` na criação da cobrança — e, quando não
     * há pedido do OLHO correspondente, nada é agendado (o evento fica registrado
     * para o operador, sem inventar pedido a partir do payload).
     *
     * @param  array<string, mixed>  $payload
     */
    private function scheduleReconcileAfterWebhookFailure(array $payload, \Throwable $exception): void
    {
        Log::warning('payment.webhook.confirm_failed', [
            'provider' => $this->settings->provider(),
            'provider_reference_id' => $this->webhookReferenceIds($payload)[0] ?? null,
            'error' => $exception->getMessage(),
        ]);

        $payment = $this->paymentForWebhook($payload);

        if ($payment === null) {
            Log::warning('payment.webhook.confirm_unmatched', [
                'provider_reference_id' => $this->webhookReferenceIds($payload)[0] ?? null,
            ]);

            return;
        }

        $this->queueReconcile($payment, 'webhook-error');
    }

    /**
     * Pedido do OLHO referenciado por uma notificação do PagBank.
     *
     * O id notificado é o da cobrança (`CHAR_`) ou o do checkout/pedido
     * (`CHEC_`/`ORDE_`). O OLHO grava o id devolvido pelo provedor na criação da
     * cobrança, então a busca usa exatamente esses ids — nunca `links.href` do
     * payload. Numa notificação de cobrança o pedido vem em
     * `metadata.ps_order_id` (mesma leitura da confirmação normal), e é ele que
     * identifica o pagamento no banco do OLHO.
     *
     * @param  array<string, mixed>  $payload
     */
    private function paymentForWebhook(array $payload): ?Payment
    {
        $ids = $this->webhookReferenceIds($payload);

        if ($ids === []) {
            return null;
        }

        return Payment::query()
            ->where('provider', PaymentSettings::PROVIDER_PAGBANK)
            ->where(function ($query) use ($ids): void {
                $query->whereIn('external_id', $ids)->orWhereIn('provider_checkout_id', $ids);
            })
            ->latest('id')
            ->first();
    }

    /**
     * Ids do provedor presentes na notificação, na ordem de confiança.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function webhookReferenceIds(array $payload): array
    {
        $candidates = [
            $payload['id'] ?? null,
            $payload['checkout_id'] ?? null,
            // `data_get` evita aviso de offset quando o payload traz `metadata`
            // como string, null ou array sem `ps_order_id`.
            data_get($payload, 'metadata.ps_order_id'),
        ];

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $id): string => is_scalar($id) ? (string) $id : '', $candidates),
            fn (string $id): bool => $id !== '',
        )));
    }

    /**
     * Simula a confirmação de pagamento (apenas provider fake).
     */
    public function simulatePayment(Payment $payment): Payment
    {
        if (! $this->provider instanceof FakePixProvider) {
            throw new \RuntimeException('Simulação disponível apenas com provider fake.');
        }

        return $this->confirmPayment(new VerifiedPaymentEvent(
            externalEventId: 'sim_'.Str::uuid(),
            externalId: $payment->external_id,
            status: PaymentStatus::Paid->value,
        ));
    }

    /**
     * Confirma um evento de pagamento verificado, de forma idempotente.
     */
    public function confirmPayment(VerifiedPaymentEvent $event): Payment
    {
        return DB::transaction(function () use ($event) {
            if ($processed = PaymentEvent::query()->where('external_event_id', $event->externalEventId)->first()) {
                return $processed->payment;
            }

            $payment = Payment::query()->where('external_id', $event->externalId)->firstOrFail();

            PaymentEvent::query()->create([
                'payment_id' => $payment->id,
                'external_event_id' => $event->externalEventId,
                'type' => 'payment.'.$event->status,
                'status' => $event->status,
            ]);

            // O método (PIX/débito/crédito) só é conhecido depois que o comprador
            // escolhe na tela do provedor: quando o evento o informa, guardamos
            // para auditoria e para os filtros do painel.
            if ($event->method !== null) {
                $payment->method = $event->method;
            }

            if ($event->status === PaymentStatus::Paid->value && $payment->status !== PaymentStatus::Paid->value) {
                $payment->status = PaymentStatus::Paid->value;
                $payment->paid_at = now();
                $payment->save();

                if ($payment->isGuest()) {
                    // Avulsa: preço fixo pago libera a execução (nenhum crédito).
                    $this->guests->release($payment);
                } else {
                    $this->grantCredits($payment);
                }
            } elseif ($payment->isDirty()) {
                // Evento não conclusivo que trouxe dado novo (ex.: o método).
                $payment->save();
            }

            return $payment;
        });
    }

    /**
     * Concede os créditos do pacote (idempotente por pagamento).
     */
    private function grantCredits(Payment $payment): void
    {
        $package = CreditPackage::query()->findOrFail($payment->credit_package_id);
        $user = User::query()->findOrFail($payment->user_id);
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        $this->credits->purchase(
            $user,
            $wallet,
            $package->credits,
            'payment-paid-'.$payment->id,
            ['payment_id' => $payment->id],
        );
    }
}

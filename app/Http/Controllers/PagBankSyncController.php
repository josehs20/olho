<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Payments\PaymentSettings;
use App\Payments\PaymentStatus;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PagBankSyncController extends Controller
{
    /**
     * Teto de pedidos por varredura no painel.
     *
     * Cada pedido gera 1–2 chamadas à API do provedor: o limite mantém a
     * requisição do navegador curta. Quem precisa de mais usa o comando.
     */
    private const BULK_LIMIT = 25;

    public function __construct(private readonly PaymentService $payments) {}

    /**
     * Força a sincronização do estado de um pagamento no provedor PagBank.
     *
     * POST /admin/payments/{payment}/sync
     *
     * Endpoint administrativo para testar o webhook sem aguardar a notificação:
     * - consulta o estado real no provedor;
     * - confirma o pagamento se ele já constar como PAID;
     * - libera a investigação avulsa ou concede os créditos, de forma idempotente.
     *
     * A consulta roda **na requisição** (o operador vê o resultado agora). Para
     * mandar a verificação ao worker sem esperar a API, use `revalidate`.
     * Exige autenticação e, para segurança, apenas administradores podem acioná-lo.
     */
    public function sync(Request $request, Payment $payment): JsonResponse|RedirectResponse
    {
        abort_if(! $request->user()?->is_admin, 403);

        if ($payment->provider !== PaymentSettings::PROVIDER_PAGBANK) {
            return $this->rejected($request, $payment, sprintf(
                'Este pagamento utiliza o provedor "%s"; o sync só é suportado para o PagBank.',
                $payment->provider,
            ));
        }

        $confirmed = $this->payments->reconcile($payment);

        $payment->refresh();

        $paid = $payment->status === PaymentStatus::Paid->value;

        if (! $request->expectsJson()) {
            // Botão do painel (formulário HTML): o operador fica na lista, com o
            // resultado no flash, em vez de cair no JSON da resposta.
            return back()->with('status', $paid
                ? sprintf(
                    'Pagamento #%d confirmado no PagBank — %s.',
                    $payment->id,
                    $payment->isGuest() ? 'investigação avulsa liberada' : 'créditos liberados',
                )
                : sprintf(
                    'Pagamento #%d consultado no PagBank: segue pendente (provedor responde "%s").',
                    $payment->id,
                    $confirmed->value,
                ));
        }

        return response()->json([
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'provider_status' => $confirmed->value,
            'paid' => $paid,
            'released_investigation' => $paid && $payment->isGuest() && ($payment->guestInvestigation?->exists ?? false),
            'credits_granted' => $paid && ! $payment->isGuest(),
        ]);
    }

    /**
     * Sincroniza de uma vez **todos** os pagamentos PagBank pendentes.
     *
     * POST /admin/payments/sync-pending
     *
     * Mesmo caminho do botão por pedido, em lote: é como conferir todos os
     * pagamentos quando o webhook não chega (ambiente local sem URL pública).
     * Um pedido que falhe na consulta não interrompe os demais — cada um segue
     * pendente e o resumo informa quantos falharam.
     */
    public function syncPending(Request $request): RedirectResponse
    {
        abort_if(! $request->user()?->is_admin, 403);

        $results = $this->payments->reconcilePendingPagBank(self::BULK_LIMIT);

        if ($results === []) {
            return back()->with('status', 'Nenhum pagamento PagBank aguardando confirmação.');
        }

        $confirmed = 0;
        $failed = 0;

        foreach ($results as $result) {
            if ($result['error'] !== null) {
                $failed++;

                continue;
            }

            if ($result['payment']->status === PaymentStatus::Paid->value) {
                $confirmed++;
            }
        }

        $message = sprintf(
            '%d pagamento(s) PagBank consultado(s): %d confirmado(s) agora, %d ainda pendente(s), %d com falha.',
            count($results),
            $confirmed,
            count($results) - $confirmed - $failed,
            $failed,
        );

        if (count($results) >= self::BULK_LIMIT) {
            $message .= ' Havia mais pendentes que o limite desta operação — repita para continuar.';
        }

        return back()->with('status', $message);
    }

    /**
     * Agenda a verificação de um pedido no worker.
     *
     * POST /admin/payments/{payment}/revalidate
     *
     * Botão **Revalidar** da coluna Ações: em vez de consultar o provedor dentro
     * da requisição (que pode levar segundos e falhar por indisponibilidade), a
     * verificação vai para a fila — o worker reconsulta o PagBank e libera
     * créditos ou a investigação avulsa pelo mesmo caminho idempotente. É o botão
     * para o caso desta etapa: a notificação chegou (ou deveria ter chegado) e o
     * pedido continua aguardando. Idempotente: o job é único por pagamento e a
     * confirmação não duplica créditos.
     */
    public function revalidate(Request $request, Payment $payment): JsonResponse|RedirectResponse
    {
        abort_if(! $request->user()?->is_admin, 403);

        if ($payment->provider !== PaymentSettings::PROVIDER_PAGBANK) {
            return $this->rejected($request, $payment, sprintf(
                'Este pagamento utiliza o provedor "%s"; a verificação só é suportada para o PagBank.',
                $payment->provider,
            ));
        }

        if ($payment->external_id === null) {
            return $this->rejected($request, $payment, 'Este pedido ainda não tem cobrança criada no PagBank; não há o que verificar.');
        }

        $this->payments->queueReconcile($payment, 'admin');

        $message = sprintf(
            'Verificação do pagamento #%d agendada no worker: o PagBank será consultado e, se já estiver pago, %s.',
            $payment->id,
            $payment->isGuest() ? 'a investigação avulsa será liberada' : 'os créditos serão liberados',
        );

        if (! $request->expectsJson()) {
            return back()->with('status', $message);
        }

        return response()->json([
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'queued' => true,
            'message' => $message,
        ]);
    }

    /**
     * Agenda a verificação de **todos** os pendentes PagBank no worker.
     *
     * POST /admin/payments/revalidate-pending
     *
     * Mesma varredura do botão "Sincronizar pendentes", mas pela fila: a
     * requisição do navegador termina na hora e cada pedido é conferido pelo
     * worker, com as retentativas do job. O teto por chamada é o mesmo.
     */
    public function revalidatePending(Request $request): RedirectResponse
    {
        abort_if(! $request->user()?->is_admin, 403);

        $queued = $this->payments->queuePendingPagBank(self::BULK_LIMIT);

        if ($queued === 0) {
            return back()->with('status', 'Nenhum pagamento PagBank aguardando confirmação.');
        }

        $message = sprintf(
            '%d verificação(ões) agendada(s) no worker: cada pedido pendente será conferido no PagBank e liberado se já estiver pago.',
            $queued,
        );

        if ($queued >= self::BULK_LIMIT) {
            $message .= ' Havia mais pendentes que o limite desta operação — repita para continuar.';
        }

        return back()->with('status', $message);
    }

    /**
     * Pedido que não pode ser verificado: 422 no JSON (API/teste) e flash no
     * formulário HTML do painel, em vez de derrubar o operador numa página de erro.
     */
    private function rejected(Request $request, Payment $payment, string $message): JsonResponse|RedirectResponse
    {
        if (! $request->expectsJson()) {
            return back()->with('error', $message);
        }

        return response()->json([
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'error' => $message,
        ], 422);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PaymentSettingsRequest;
use App\Payments\PagBank\PagBankClient;
use App\Payments\PagBank\PagBankException;
use App\Payments\PaymentSettings;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Admin → Configurações → Pagamentos (prompt 45).
 *
 * Centraliza provedor, ambiente, métodos (PIX/débito/crédito), parcelamento e
 * credenciais do PagBank. O token nunca é devolvido à view nem logado.
 */
class PaymentSettingsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * As configurações são resolvidas **por método**, nunca guardadas no construtor:
     * o Laravel memoiza a instância do controller no objeto de rota (`Route::getController`)
     * e `flushController()` não é chamado pelo framework, então um processo que atende
     * mais de uma requisição (Octane, worker, suíte de testes) reusaria o mesmo
     * `SettingsService` — e o memo em memória dele mostraria valores antigos.
     */
    public function edit(SettingsService $settings, PaymentSettings $paymentSettings): View
    {
        $lastTest = $settings->getString(PaymentSettings::KEY_LAST_TEST_AT);

        return view('admin.payments.settings', [
            'settings' => $paymentSettings,
            'hasToken' => $paymentSettings->token() !== null,
            'lastTestAt' => $lastTest !== null ? Carbon::parse($lastTest) : null,
            'lastTestOk' => $settings->getBool(PaymentSettings::KEY_LAST_TEST_OK, false),
            'lastTestError' => $settings->getString(PaymentSettings::KEY_LAST_TEST_ERROR),
            // Diagnóstico da última cobrança recusada: o comprador vê uma
            // mensagem curta, mas o operador precisa saber qual parâmetro o
            // PagBank recusou para corrigir a configuração.
            'lastCheckoutError' => $paymentSettings->lastCheckoutFailure(),
            // Sem HTTPS público o provedor não alcança o webhook: a URL é
            // omitida do payload (a confirmação vem do retorno do comprador).
            'webhookIsPublic' => $paymentSettings->webhookIsPublic(),
            'installments' => (array) config('payments.pagbank.installments.allowed', [1]),
            'webhookUrl' => route('payments.webhook.pagbank'),
            // Diagnóstico: endpoint efetivamente testado e prévia do token em uso
            // (nunca o token completo), para o operador comparar com o portal.
            'testEndpoint' => sprintf(
                'GET %s%s',
                rtrim($paymentSettings->apiBaseUrl(), '/'),
                PagBankClient::WEBHOOK_PUBLIC_KEY_PATH,
            ),
            'tokenPreview' => PaymentSettings::maskToken($paymentSettings->token()),
        ]);
    }

    public function update(PaymentSettingsRequest $request, SettingsService $settings): RedirectResponse
    {
        $data = $request->validated();

        $settings->setMany([
            PaymentSettings::KEY_PROVIDER => $data['provider'],
            PaymentSettings::KEY_ENABLED => $request->boolean('enabled') ? '1' : '0',
            PaymentSettings::KEY_ENVIRONMENT => $data['environment'],
            PaymentSettings::KEY_METHOD_PIX => $request->boolean('method_pix') ? '1' : '0',
            PaymentSettings::KEY_METHOD_DEBIT => $request->boolean('method_debit') ? '1' : '0',
            PaymentSettings::KEY_METHOD_CREDIT => $request->boolean('method_credit') ? '1' : '0',
            PaymentSettings::KEY_PIX_EXPIRATION => (string) $data['pix_expiration_minutes'],
            PaymentSettings::KEY_CREDIT_MAX_INSTALLMENTS => (string) $data['credit_max_installments'],
        ]);

        // Segredo: só grava um novo token quando informado; nunca sobrescreve com vazio.
        if (! empty($data['token'])) {
            $settings->set(PaymentSettings::KEY_PAGBANK_TOKEN, (string) $data['token']);
        } elseif ($request->boolean('clear_token')) {
            $settings->forget(PaymentSettings::KEY_PAGBANK_TOKEN);
        }

        $settings->flush();

        $this->audit->log($request->user(), 'admin.payment_settings.update', null, [
            'provider' => $data['provider'],
            'environment' => $data['environment'],
            'enabled' => $request->boolean('enabled'),
            'method_pix' => $request->boolean('method_pix'),
            'method_debit' => $request->boolean('method_debit'),
            'method_credit' => $request->boolean('method_credit'),
            'max_installments' => (int) $data['credit_max_installments'],
        ], $request->ip());

        return redirect()->route('admin.settings.payments.edit')
            ->with('status', 'Configurações de pagamento salvas.');
    }

    /**
     * Testa a conexão/credenciais sem criar cobrança alguma.
     *
     * Usa `GET /public-keys/webhook` (path param oficial) — exige token válido e
     * não gera efeito financeiro. A mensagem de falha traz o status HTTP e a
     * orientação de correção (e nunca o token).
     */
    public function testConnection(
        Request $request,
        PagBankClient $client,
        SettingsService $settings,
        PaymentSettings $paymentSettings,
    ): RedirectResponse {
        $ok = false;
        $error = null;

        try {
            // `GET /public-keys/webhook`: valida o token sem criar cobrança.
            $client->checkConnection();
            $ok = true;
        } catch (PagBankException $exception) {
            $error = $exception->getMessage();
        }

        $settings->setMany([
            PaymentSettings::KEY_LAST_TEST_AT => now()->toIso8601String(),
            PaymentSettings::KEY_LAST_TEST_OK => $ok ? '1' : '0',
            // Guardado para o operador ver o motivo da última falha (nunca o token).
            PaymentSettings::KEY_LAST_TEST_ERROR => $ok ? null : $error,
        ]);

        $environment = $paymentSettings->environment() === PaymentSettings::ENV_PRODUCTION ? 'Produção' : 'Sandbox';

        $this->audit->log($request->user(), 'admin.payment_settings.test', null, [
            'ok' => $ok,
            'environment' => $paymentSettings->environment(),
        ], $request->ip());

        if ($ok) {
            return redirect()->route('admin.settings.payments.edit')
                ->with('status', "Conexão realizada com sucesso. Ambiente: {$environment}. Status: Conectado.");
        }

        return redirect()->route('admin.settings.payments.edit')
            ->with('error', $error ?? 'Não foi possível conectar. Verifique as credenciais e a configuração do PagBank.');
    }
}

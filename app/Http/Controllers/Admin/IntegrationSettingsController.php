<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IntegrationSettingsRequest;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Sources\Integrations\InstagramSettings;
use App\Sources\Integrations\SessionCheckable;
use App\Sources\SourceRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Admin → Configurações → Integrações (prompt 48 §2.1).
 *
 * Centraliza as credenciais e os cookies de serviço usados pelos módulos OSINT —
 * hoje o `sessionid` da conta de serviço (**burner**) do Instagram, exigido pelo
 * `instagrapi`. O valor é um segredo: fica cifrado no banco (`settings`) e nunca
 * é devolvido à view, logado ou impresso em erro.
 *
 * Como no painel de pagamentos, a configuração tipada é resolvida **por método**,
 * nunca guardada no construtor: o Laravel memoiza o controller no objeto de rota
 * (`Route::getController`) e um processo que atende mais de uma requisição
 * (Octane, testes) reusaria um `SettingsService` com memo de memória defasado.
 */
class IntegrationSettingsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function edit(InstagramSettings $settings): View
    {
        $lastCheck = $settings->lastCheckAt();

        return view('admin.integrations.settings', [
            'settings' => $settings,
            'hasSession' => $settings->hasSession(),
            'sessionPreview' => self::mask($settings->sessionId()),
            'dsUserId' => $settings->dsUserId(),
            'status' => $settings->status(),
            'statusLabel' => InstagramSettings::label($settings->status()),
            'statusVariant' => InstagramSettings::variant($settings->status()),
            'lastCheckAt' => $lastCheck !== null ? Carbon::parse($lastCheck) : null,
            'lastMessage' => $settings->lastMessage(),
            // O adapter em uso (`process` ou `mock`), para o operador saber se a
            // consulta real está habilitada neste ambiente.
            'adapter' => $settings->driver() === InstagramSettings::DRIVER_MOCK ? 'mock' : 'process',
        ]);
    }

    public function update(IntegrationSettingsRequest $request, SettingsService $settings): RedirectResponse
    {
        $data = $request->validated();

        $settings->set(InstagramSettings::KEY_DRIVER, $data['instagram_driver']);

        // Segredo: só grava quando informado; nunca sobrescreve com vazio.
        if (($data['session_id'] ?? null) !== null) {
            $settings->set(InstagramSettings::KEY_SESSION_ID, $data['session_id']);
            // Credencial nova começa como "não verificada": o operador precisa
            // rodar "Testar conexão" para o badge refletir a sessão real.
            $settings->set(InstagramSettings::KEY_STATUS, InstagramSettings::STATUS_UNKNOWN);
        } elseif ($request->boolean('clear_session')) {
            $settings->forget(InstagramSettings::KEY_SESSION_ID);
            $settings->set(InstagramSettings::KEY_STATUS, InstagramSettings::STATUS_EXPIRED);
        }

        if (($data['ds_user_id'] ?? null) !== null) {
            $settings->set(InstagramSettings::KEY_DS_USER_ID, $data['ds_user_id']);
        }

        $settings->flush();

        $this->audit->log($request->user(), 'admin.integration_settings.update', null, [
            'integration' => 'instagram',
            'driver' => $data['instagram_driver'],
            'session_updated' => ($data['session_id'] ?? null) !== null,
            'session_cleared' => $request->boolean('clear_session'),
        ], $request->ip());

        return redirect()->route('admin.settings.integrations.edit')
            ->with('status', __('Configurações de integração salvas.'));
    }

    /**
     * Ação "Testar conexão" (§2.1).
     *
     * Resolve o adapter registrado sob o mesmo slug (`instagram`) e executa o
     * diagnóstico da credencial: no adapter real, o script Python resolve
     * `cl.user_info_by_username('instagram')`; no mock, o estado é reportado sem
     * tocar na rede. Nada é consultado sobre alvo algum e nenhum crédito é
     * envolvido.
     */
    public function testConnection(
        Request $request,
        SourceRegistry $registry,
        InstagramSettings $settings,
    ): RedirectResponse {
        if (! $settings->hasSession()) {
            return redirect()->route('admin.settings.integrations.edit')
                ->with('error', __('Informe e salve o sessionid antes de testar a conexão.'));
        }

        $adapter = $registry->get('instagram');

        if (! $adapter instanceof SessionCheckable) {
            return redirect()->route('admin.settings.integrations.edit')
                ->with('error', __('O módulo do Instagram não oferece diagnóstico de sessão neste ambiente.'));
        }

        $result = $adapter->checkSession();

        $status = match (true) {
            $result['healthy'] => InstagramSettings::STATUS_HEALTHY,
            $result['challenge'] => InstagramSettings::STATUS_CHALLENGE_REQUIRED,
            default => InstagramSettings::STATUS_EXPIRED,
        };

        // Diagnóstico cru devolvido pelo script/Instagram (ex.: o texto genérico
        // "We're sorry, but something went wrong. Please try again."). Fica
        // registrado em `lastMessage` ("Última observação") para inspeção, mas
        // **não** é a mensagem de ação: crua e em inglês, parece um erro do
        // sistema e não orienta o operador (mesmo cuidado do
        // `SourceErrorMapper::toOperatorMessage()`).
        $diagnostic = $result['message'];

        // Mensagem de **ação**: clara, em português e acionável, por estado.
        $message = match ($status) {
            InstagramSettings::STATUS_HEALTHY => __('Sessão ativa. Conta de serviço: :account.', [
                'account' => $result['account'] ?? __('não informada'),
            ]),
            InstagramSettings::STATUS_CHALLENGE_REQUIRED => __('O Instagram exigiu verificação humana (desafio/checkpoint). Resolva o desafio na conta de serviço e gere um novo sessionid.'),
            // O cookie pode estar válido no navegador e ainda assim ser recusado
            // aqui: o Instagram vincula a sessão ao IP/dispositivo de origem e
            // limita IPs de datacenter (HTTP 429 / login_required). Regurar o
            // sessionid sem resolver o IP não adianta — por isso a mensagem aponta
            // as duas causas reais em vez de culpar só a credencial.
            default => __('O Instagram recusou a conexão a partir deste servidor. Na maioria dos casos o sessionid está válido no navegador, mas o Instagram bloqueia/limita o IP de datacenter do servidor (erro 429 / login_required). Confirme se a sessão funciona no navegador e, se sim, execute a consulta a partir de um IP residencial ou proxy confiável — não é preciso regerar o sessionid.'),
        };

        // `rememberStatus` grava via `SettingsService::setMany`, que já limpa o
        // cache e o memo da instância em uso — nada mais a invalidar aqui. Guarda
        // o diagnóstico cru quando houver; senão, a própria mensagem de ação.
        $settings->rememberStatus($status, $diagnostic ?? $message);

        $this->audit->log($request->user(), 'admin.integration_settings.test', null, [
            'integration' => 'instagram',
            'status' => $status,
        ], $request->ip());

        return redirect()->route('admin.settings.integrations.edit')
            ->with(
                $status === InstagramSettings::STATUS_HEALTHY ? 'status' : 'error',
                $message,
            );
    }

    /**
     * Prévia do cookie em uso (nunca o valor completo), para o operador comparar
     * com o DevTools sem que o segredo trafegue para o navegador.
     */
    public static function mask(?string $secret): ?string
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        return mb_substr($secret, 0, 4).str_repeat('•', 12).mb_substr($secret, -4);
    }
}

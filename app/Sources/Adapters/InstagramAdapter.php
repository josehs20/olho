<?php

namespace App\Sources\Adapters;

use App\Sources\Exceptions\SourceChallengeException;
use App\Sources\Exceptions\SourceInvalidInputException;
use App\Sources\Exceptions\SourceSessionException;
use App\Sources\Exceptions\SourceUnavailableException;
use App\Sources\Integrations\InstagramSettings;
use App\Sources\Integrations\SessionCheckable;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\InvestigationSource;
use App\Sources\Runners\ToolCommand;
use App\Sources\Runners\ToolOutput;
use App\Sources\Runners\ToolProcessOutcome;
use App\Sources\Runners\ToolProcessRunner;
use App\Sources\Runners\ToolWorkspace;
use App\Sources\SourceOutcome;
use App\Sources\SourceResult;

/**
 * Integração com o Instagram via **instagrapi** (prompt 48, etapa 2 da pipeline:
 * Profile Enrichment & Visual Evidence).
 *
 * O adapter é um **enriquecimento**: ele não descobre o perfil — recebe o
 * username que as fontes da etapa 1 (Maigret/Sherlock/WhatsMyName) já
 * confirmaram e devolve metadados públicos do perfil, com o avatar registrado
 * como evidência visual (`evidence.type = 'avatar'`), para a galeria e a
 * correlação por similaridade perceptual.
 *
 * Forma de execução (docs/21 e docs/42):
 * - o cliente Python/instagrapi roda como **processo separado**
 *   (`docker/php/instagrapi/instagram_profile.py`), nunca dentro do FPM;
 * - o `sessionid` viaja pelo **ambiente do processo** (`INSTAGRAPI_SESSIONID`),
 *   jamais pela argv — argv aparece em `ps` para qualquer usuário do host;
 * - a entrada vai como elemento próprio da argv e é validada antes de qualquer
 *   execução (nunca concatenação de shell);
 * - o script publica um JSON com `session_status`, e o adapter traduz o desfecho
 *   para o contrato de fontes: sessão revogada/desafiada **não** consome crédito
 *   (nada foi consultado sobre o alvo).
 *
 * Uso de fontes públicas/privadas: a integração usa o `sessionid` de uma conta
 * de serviço (burner) informado pelo operador no painel admin. Não burla
 * CAPTCHA nem autenticação de terceiros: quando o Instagram exige verificação
 * humana, a consulta é interrompida e o operador é avisado no painel de
 * integrações — o desafio é resolvido na conta de serviço, não contornado.
 */
final class InstagramAdapter implements InvestigationSource, SessionCheckable
{
    /** Versão do adapter (participa da chave de cache). */
    private const ADAPTER_VERSION = 'v1';

    private const USERNAME_PATTERN = '/^[A-Za-z0-9._]{1,30}$/';

    /** Teto do relatório JSON aceito do script. */
    private const OUTPUT_LIMIT = 5_000_000;

    private const DISCLAIMER = 'Metadados públicos do perfil do Instagram obtidos com a conta de serviço do OLHO. Uma correspondência de username não confirma identidade.';

    public function __construct(
        private readonly ToolProcessRunner $runner,
        private readonly ToolWorkspace $workspace,
        private readonly InstagramSettings $settings,
    ) {}

    public function key(): string
    {
        return 'instagram';
    }

    public function name(): string
    {
        return 'Instagram (instagrapi)';
    }

    public function version(): string
    {
        return self::ADAPTER_VERSION;
    }

    /**
     * O módulo é um enriquecimento de **username**: sem username não há perfil a
     * enriquecer (a etapa 1 é quem descobre que aquele username existe).
     */
    public function supports(InvestigationInput $input): bool
    {
        return $input->username !== null && $this->isValidUsername($input->username);
    }

    public function execute(InvestigationContext $context): SourceOutcome
    {
        $username = $context->input->username;

        if ($username === null || ! $this->isValidUsername($username)) {
            throw new SourceInvalidInputException('O módulo do Instagram exige um username público válido.');
        }

        $username = strtolower(trim($username));
        $workdir = $this->workspace->create($this->workdir());
        $options = $this->options($context);

        try {
            $outcome = $this->runner->run($this->command($username, $workdir, $options), [
                'tool' => 'instagrapi',
                'username' => $username,
            ]);

            $this->assertSession($outcome);

            if (! $outcome->successful) {
                throw new SourceUnavailableException(
                    $outcome->errorMessage ?? 'O Instagram não devolveu dados do perfil.',
                );
            }

            return $this->outcome($username, $outcome);
        } finally {
            $this->workspace->cleanup($workdir);
        }
    }

    public function normalize(array $raw): SourceResult
    {
        $username = is_string($raw['username'] ?? null) ? $raw['username'] : (string) ($raw['value'] ?? '');

        return new SourceResult(
            source: $this->key(),
            entityType: $raw['entity_type'] ?? 'social_profile',
            value: $username,
            url: $raw['url'] ?? (is_string($raw['profile_url'] ?? null) ? $raw['profile_url'] : null),
            title: $raw['title'] ?? (is_string($raw['full_name'] ?? null) ? $raw['full_name'] : $username),
            description: $raw['description'] ?? ($raw['biography'] ?? null),
            confidence: $raw['confidence'] ?? null,
            evidence: $raw['evidence'] ?? [],
            metadata: is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [],
        );
    }

    public function healthCheck(): bool
    {
        return $this->settings->hasSession();
    }

    /* ---------------------------------------------------------------------
     | Execução
     | --------------------------------------------------------------------- */

    /**
     * Traduz o `session_status` publicado pelo script para o contrato de fontes.
     *
     * `EXPIRED`/`INVALID` e `CHALLENGE_REQUIRED` significam que **nada** foi
     * consultado sobre o alvo: os dois lançam exceções com `Skipped` como status
     * de execução, para que a reserva de crédito seja estornada (a mesma regra do
     * módulo não aplicável) e o painel de integrações acuse o problema.
     */
    private function assertSession(ToolProcessOutcome $outcome): void
    {
        $status = strtoupper((string) $outcome->sessionStatus);

        if ($status === '') {
            // Sem `session_status` o script não chegou a avaliar a sessão: o
            // desfecho é tratado pelo `successful`/erro reportado.
            return;
        }

        if ($status === 'CHALLENGE_REQUIRED') {
            throw new SourceChallengeException(sprintf(
                'O Instagram exige verificação humana na conta de serviço%s.',
                $this->accountSuffix($outcome),
            ));
        }

        if ($status !== 'HEALTHY') {
            throw new SourceSessionException(sprintf(
                'A sessão do Instagram está inválida ou expirada%s. Atualize o sessionid em Configurações → Integrações.',
                $this->accountSuffix($outcome),
            ));
        }
    }

    /**
     * Monta o `SourceOutcome` a partir do JSON do script: um finding por perfil
     * encontrado, com o avatar como evidência visual (a galeria e a correlação
     * por similaridade perceptual partem daí).
     */
    private function outcome(string $username, ToolProcessOutcome $outcome): SourceOutcome
    {
        if (($outcome->payload['found'] ?? null) !== true) {
            // Execução concluída sem perfil correspondente: "0 resultados" é um
            // desfecho legítimo (e distinto de "não deu para verificar", que
            // chega aqui como `successful = false`).
            return new SourceOutcome(
                warnings: $this->warnings($outcome),
                metadata: $this->metadata($username, $outcome, 0),
            );
        }

        return new SourceOutcome(
            findings: [$this->finding($username, $outcome)],
            warnings: $this->warnings($outcome),
            metadata: $this->metadata($username, $outcome, 1),
        );
    }

    private function finding(string $username, ToolProcessOutcome $outcome): SourceResult
    {
        $profile = is_array($outcome->payload['profile'] ?? null) ? $outcome->payload['profile'] : [];
        $handle = is_string($profile['username'] ?? null) ? $profile['username'] : $username;
        $avatar = is_string($profile['avatar_url'] ?? null) ? $profile['avatar_url'] : null;

        return new SourceResult(
            source: $this->key(),
            entityType: 'social_profile',
            value: $handle,
            url: is_string($profile['profile_url'] ?? null)
                ? $profile['profile_url']
                : 'https://www.instagram.com/'.rawurlencode($handle).'/',
            title: $this->nullableString($profile['full_name'] ?? null) ?? $handle,
            description: $this->nullableString($profile['biography'] ?? null),
            // Correspondência de username: existência confirmada, identidade não.
            confidence: 0.6,
            evidence: $this->evidence($profile, $avatar),
            metadata: $this->profileMetadata($outcome, $profile),
        );
    }

    /**
     * Evidências do perfil. O avatar vai como `avatar` (URL pública), que é o
     * insumo da Galeria de Evidências Visuais; os demais campos entram como
     * `reference` para o relatório poder citar cada um.
     *
     * @param  array<string, mixed>  $profile
     * @return array<int, array{type: string, value: string}>
     */
    private function evidence(array $profile, ?string $avatar): array
    {
        $evidence = [];

        if ($avatar !== null) {
            $evidence[] = ['type' => 'avatar', 'value' => $avatar];
        }

        foreach ([
            'account' => $profile['profile_url'] ?? null,
            'followers' => $profile['followers'] ?? null,
            'following' => $profile['following'] ?? null,
            'posts' => $profile['posts'] ?? null,
            'private' => isset($profile['is_private']) ? ($profile['is_private'] ? 'true' : 'false') : null,
            'verified' => isset($profile['is_verified']) ? ($profile['is_verified'] ? 'true' : 'false') : null,
            'external_url' => $profile['external_url'] ?? null,
        ] as $type => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $evidence[] = ['type' => 'reference', 'value' => $type.': '.(string) $value];
            }
        }

        return $evidence;
    }

    /**
     * Metadados públicos do adapter (persistidos em `source_runs.metrics.adapter`).
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function profileMetadata(ToolProcessOutcome $outcome, array $profile): array
    {
        return array_filter([
            'tool' => 'instagrapi',
            'tool_version' => $this->nullableString($outcome->payload['instagrapi_version'] ?? null),
            'username' => $this->nullableString($profile['username'] ?? null),
            'user_id' => $this->nullableString($profile['user_id'] ?? null),
            'is_private' => $profile['is_private'] ?? null,
            'is_verified' => $profile['is_verified'] ?? null,
            'avatar_available' => is_string($profile['avatar_url'] ?? null),
            // Correção do prompt 48 §1: a `pHash` do avatar é calculada fora da
            // etapa de coleta. O resultado já sai marcado para a galeria/correlação.
            'visual_correlation' => 'pending',
            'disclaimer' => self::DISCLAIMER,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Rodapé do desfecho: panorama da execução, avisos e o estado da sessão — o
     * `ModuleTestRunner`/`ExecuteSourceJob` copiam isso para `source_runs.metrics`.
     *
     * @return array<string, mixed>
     */
    private function metadata(string $username, ToolProcessOutcome $outcome, int $found): array
    {
        return array_filter([
            'tool' => 'instagrapi',
            'tool_version' => $this->nullableString($outcome->payload['instagrapi_version'] ?? null),
            'username' => $username,
            'profiles_found' => $found,
            'session_status' => strtoupper((string) $outcome->sessionStatus) ?: null,
            'account_username' => $outcome->accountUsername,
            'visual_correlation' => 'pending',
            'disclaimer' => self::DISCLAIMER,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Avisos públicos: os que o script reporta (já em português) somados aos
     * avisos no formato das ferramentas OSINT (`[!] ...`) do stderr.
     *
     * @return array<int, string>
     */
    private function warnings(ToolProcessOutcome $outcome): array
    {
        return array_values(array_unique(array_merge(
            $outcome->warnings,
            ToolOutput::publicWarnings(
                is_string($outcome->payload['stdout_tail'] ?? null) ? $outcome->payload['stdout_tail'] : '',
                is_string($outcome->payload['stderr_tail'] ?? null) ? $outcome->payload['stderr_tail'] : '',
            ),
        )));
    }

    private function accountSuffix(ToolProcessOutcome $outcome): string
    {
        return $outcome->accountUsername !== null && $outcome->accountUsername !== ''
            ? ' (@'.$outcome->accountUsername.')'
            : '';
    }

    private function isValidUsername(string $username): bool
    {
        $username = trim($username);

        return $username !== '' && preg_match(self::USERNAME_PATTERN, $username) === 1;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function binary(): string
    {
        return (string) config('sources.instagram.binary', 'python3');
    }

    private function workdir(): string
    {
        return (string) config('sources.instagram.workdir', storage_path('app/instagram'));
    }

    /**
     * Opções efetivas: configuração da aplicação sobreposta por
     * `source_definitions.config` (contrato `SourceDescriptor`).
     *
     * @return array{request_timeout: int, process_timeout: int, max_output_bytes: int}
     */
    private function options(InvestigationContext $context): array
    {
        $config = (array) config('sources.instagram');
        $overrides = $context->source?->config ?? [];

        $processTimeout = (int) ($overrides['process_timeout'] ?? ($config['process_timeout'] ?? 45));

        // Nunca ultrapassa o timeout declarado pela fonte (menor que o timeout do
        // job, para a execução terminar antes do worker desistir).
        if ($context->source !== null && $context->source->timeout > 0) {
            $processTimeout = min($processTimeout, $context->source->timeout);
        }

        return [
            'request_timeout' => max(1, (int) ($overrides['request_timeout'] ?? ($config['request_timeout'] ?? 15))),
            'process_timeout' => max(5, $processTimeout),
            'max_output_bytes' => max(1024, (int) ($config['max_output_bytes'] ?? self::OUTPUT_LIMIT)),
        ];
    }

    /**
     * Comando do script Python/instagrapi.
     *
     * O username é um elemento próprio da argv; o `sessionid` vai pelo
     * **ambiente** do processo (`INSTAGRAPI_SESSIONID`), nunca na linha de comando
     * (docs/21: nada de segredo visível em `ps`). O ambiente mínimo do
     * `ProcessToolRunner` não vaza `APP_KEY`/credenciais da aplicação.
     *
     * @param  array<string, mixed>  $options
     */
    private function command(string $username, string $workdir, array $options): ToolCommand
    {
        $session = $this->settings->sessionId();

        if ($session === null) {
            // Sem credencial nada é consultado sobre o alvo: o desfecho é o de
            // sessão ausente (SKIPPED + estorno), igual a sessão expirada ou
            // desafio — e o operador recebe a orientação de configuração.
            throw new SourceSessionException(
                'Sessão do Instagram não configurada. Informe o sessionid em Configurações → Integrações.',
            );
        }

        $environment = [
            'INSTAGRAPI_SESSIONID' => $session,
            'INSTAGRAPI_REQUEST_TIMEOUT' => (string) $options['request_timeout'],
            'INSTAGRAPI_MAX_OUTPUT_BYTES' => (string) $options['max_output_bytes'],
        ];

        $dsUserId = $this->settings->dsUserId();

        if ($dsUserId !== null) {
            $environment['INSTAGRAPI_DS_USER_ID'] = $dsUserId;
        }

        // Proxy de egresso (segredo com credenciais): só pelo ambiente, nunca na
        // argv. Contorna o bloqueio de IP de datacenter do Instagram (429).
        $proxy = $this->settings->proxy();

        if ($proxy !== null) {
            $environment['INSTAGRAPI_PROXY'] = $proxy;
        }

        // Permite que um `session_health` seja executado pelo mesmo script
        // (`--mode session`), usado pela ação "Testar conexão" do painel.
        return new ToolCommand(
            binary: $this->binary(),
            arguments: [base_path('docker/php/instagrapi/instagram_profile.py'), '--username', $username],
            timeout: (int) $options['process_timeout'],
            workingDirectory: $workdir,
            environment: $environment,
        );
    }

    /**
     * Chamada de teste de conexão do painel admin (prompt 48 §2.1).
     *
     * Executa o script no modo `--mode session`, que resolve
     * `cl.user_info_by_username('instagram')` com o cookie salvo e devolve o
     * `session_status`. Nada é consultado sobre alvo algum e nenhum crédito é
     * envolvido: é diagnóstico da credencial.
     *
     * @return array{healthy: bool, challenge: bool, message: ?string, account: ?string}
     */
    public function checkSession(): array
    {
        $session = $this->settings->sessionId();

        if ($session === null) {
            return ['healthy' => false, 'challenge' => false, 'account' => null, 'message' => null];
        }

        $workdir = $this->workspace->create($this->workdir());

        $environment = ['INSTAGRAPI_SESSIONID' => $session];

        // O teste de conexão precisa enxergar o mesmo egresso da consulta real:
        // sem o proxy, a sessão é recusada exatamente por usar o IP de datacenter
        // que queremos contornar — o diagnóstico ficaria verde/vermelho por engano.
        $proxy = $this->settings->proxy();

        if ($proxy !== null) {
            $environment['INSTAGRAPI_PROXY'] = $proxy;
        }

        try {
            $outcome = $this->runner->run(new ToolCommand(
                binary: $this->binary(),
                arguments: [base_path('docker/php/instagrapi/instagram_profile.py'), '--mode', 'session'],
                timeout: (int) ($this->config()['process_timeout'] ?? 45),
                workingDirectory: $workdir,
                environment: $environment,
            ), ['tool' => 'instagrapi', 'mode' => 'session']);

            $status = strtoupper((string) $outcome->sessionStatus);

            return [
                'healthy' => $status === 'HEALTHY',
                'challenge' => $status === 'CHALLENGE_REQUIRED',
                'account' => $outcome->accountUsername,
                'message' => $outcome->errorMessage,
            ];
        } finally {
            $this->workspace->cleanup($workdir);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return (array) config('sources.instagram');
    }
}

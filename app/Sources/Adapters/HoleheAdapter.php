<?php

namespace App\Sources\Adapters;

use App\Sources\Adapters\Holehe\HoleheReportParser;
use App\Sources\Exceptions\SourceInvalidInputException;
use App\Sources\Exceptions\SourceTimeoutException;
use App\Sources\Exceptions\SourceUnavailableException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\InvestigationSource;
use App\Sources\Runners\ToolCommand;
use App\Sources\Runners\ToolOutput;
use App\Sources\Runners\ToolResult;
use App\Sources\Runners\ToolRunner;
use App\Sources\Runners\ToolVersionDetector;
use App\Sources\Runners\ToolWorkspace;
use App\Sources\SourceOutcome;
use App\Sources\SourceResult;
use SplFileInfo;

/**
 * Integração real com o Holehe (https://github.com/megadose/holehe).
 *
 * **Licença: GPL-3.0** (diferente das demais fontes, que são MIT/CC BY-SA).
 * Decisão registrada (`docs/30-holehe-integracao.md`): a ferramenta roda como
 * **processo separado** (sem linkagem com o código da aplicação) e o serviço é
 * acessado pela rede, sem distribuição do binário — mesma forma do Maigret e do
 * Sherlock, portanto a GPL não impõe copyleft ao código da plataforma. Se a
 * imagem passar a ser distribuída a terceiros, as obrigações da GPL se aplicam.
 *
 * Escopo e limites (prompt 35):
 *
 * - verificação **pública** de cadastro: consulta não autenticada aos fluxos de
 *   registro/login dos sites, sem acessar contas, credenciais ou conteúdo privado;
 * - `--no-password-recovery` é **obrigatório e não configurável**: o passo de
 *   recuperação de senha nunca é executado (evita disparar redefinições na conta
 *   do titular);
 * - as colunas `emailrecovery`/`phoneNumber`/`others` do CSV nunca são lidas;
 * - uma indicação de serviço associada a um e-mail **não prova** que a pessoa
 *   controla aquela conta — registrado no resultado e no relatório.
 *
 * Reutiliza a mesma camada de execução segura dos demais adapters
 * (`ToolRunner`, `ToolWorkspace`, `ToolVersionDetector`, `ToolOutput`).
 */
final class HoleheAdapter implements InvestigationSource
{
    /** Versão do adapter (participa da chave de cache). */
    private const ADAPTER_VERSION = 'v1';

    /** E-mails aceitos (sem espaços, sem iniciar com `-`). */
    private const EMAIL_PATTERN = '/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+$/';

    private const CSV_FILENAME_PATTERN = '/^holehe_.*_results\.csv$/';

    /** Versão reportada por `<binário> --version` (`holehe v1.61`). */
    private const VERSION_PATTERN = '/holehe\s+v?(\d+\.\d+(?:\.\d+)?)/i';

    private const DISCLAIMER = 'Indicação técnica de cadastro associado ao e-mail (verificação pública de cadastro, sem recuperação de senha). Não prova que uma pessoa controla aquela conta.';

    public function __construct(
        private readonly ToolRunner $runner,
        private readonly HoleheReportParser $parser,
        private readonly ToolWorkspace $workspace,
        private readonly ToolVersionDetector $versions,
    ) {}

    public function key(): string
    {
        return 'holehe';
    }

    public function name(): string
    {
        return 'Holehe';
    }

    public function version(): string
    {
        return self::ADAPTER_VERSION;
    }

    public function supports(InvestigationInput $input): bool
    {
        return $input->email !== null && $this->isValidEmail($input->email);
    }

    public function execute(InvestigationContext $context): SourceOutcome
    {
        $email = $context->input->email;

        if ($email === null || ! $this->isValidEmail($email)) {
            throw new SourceInvalidInputException('O Holehe exige um e-mail válido.');
        }

        $email = mb_strtolower(trim($email));
        $options = $this->options($context);
        $workdir = $this->workspace->create((string) config('sources.holehe.workdir', storage_path('app/holehe')));

        try {
            $result = $this->runner->run($this->command($email, $workdir, $options));
            $findings = $this->parser->parseCsv($this->readReport($workdir, $result, $options), $email);

            return new SourceOutcome(
                findings: $findings,
                warnings: ToolOutput::publicWarnings($result->stdout, $result->stderr),
                metadata: array_filter([
                    'tool' => 'holehe',
                    'tool_version' => $this->versions->detect(
                        $this->binary(),
                        self::VERSION_PATTERN,
                        $workdir,
                        min(15, $options['process_timeout']),
                        // O Holehe não expõe `--version`: a versão vem do `--help`.
                        ['--help'],
                    ),
                    'accounts_found' => count($findings),
                    'password_recovery' => false,
                    'disclaimer' => self::DISCLAIMER,
                    // O Holehe v1.61 retorna 1 mesmo em sucesso: o relatório
                    // gerado é o sinal confiável de execução concluída.
                    'exit_code' => $result->exitCode,
                ], static fn (mixed $value): bool => $value !== null),
            );
        } finally {
            $this->workspace->cleanup($workdir);
        }
    }

    public function normalize(array $raw): SourceResult
    {
        $email = is_string($raw['email'] ?? null) ? $raw['email'] : (is_string($raw['value'] ?? null) ? $raw['value'] : '');

        return $this->parser->toSourceResult($raw, $email) ?? new SourceResult(
            source: $this->key(),
            entityType: $raw['entity_type'] ?? 'email_account',
            value: $email,
            url: $raw['url'] ?? null,
            title: $raw['title'] ?? (is_string($raw['domain'] ?? null) ? $raw['domain'] : null),
            description: $raw['description'] ?? null,
            confidence: $raw['confidence'] ?? null,
            evidence: $raw['evidence'] ?? [],
            metadata: $raw['metadata'] ?? [],
        );
    }

    public function healthCheck(): bool
    {
        // O Holehe não expõe `--version`: a versão vem do `--help`.
        return $this->versions->detect($this->binary(), self::VERSION_PATTERN, null, 15, ['--help']) !== null;
    }

    private function isValidEmail(string $email): bool
    {
        $email = trim($email);

        return $email !== ''
            && strlen($email) <= 254
            && ! str_starts_with($email, '-')
            && preg_match(self::EMAIL_PATTERN, $email) === 1
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function binary(): string
    {
        return (string) config('sources.holehe.binary', 'holehe');
    }

    /**
     * Opções efetivas: configuração da aplicação sobreposta por
     * `source_definitions.config` (contrato `SourceDescriptor`).
     *
     * @return array<string, mixed>
     */
    private function options(InvestigationContext $context): array
    {
        $config = config('sources.holehe');
        $overrides = $context->source?->config ?? [];

        $processTimeout = (int) ($overrides['process_timeout'] ?? $config['process_timeout']);

        if ($context->source !== null && $context->source->timeout > 0) {
            $processTimeout = min($processTimeout, $context->source->timeout);
        }

        return [
            'request_timeout' => max(1, (int) ($overrides['request_timeout'] ?? $config['request_timeout'])),
            'process_timeout' => max(5, $processTimeout),
            'max_report_bytes' => max(1024, (int) ($overrides['max_report_bytes'] ?? ($config['max_report_bytes'] ?? 2_000_000))),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function command(string $email, string $workdir, array $options): ToolCommand
    {
        // O e-mail é um elemento próprio da argv — jamais concatenado em shell.
        // `--no-password-recovery` é fixo: o passo mais invasivo nunca roda.
        return new ToolCommand(
            binary: $this->binary(),
            arguments: [
                $email,
                '--no-password-recovery',
                '--only-used',
                '--no-color',
                '--no-clear',
                '--csv',
                '--timeout', (string) $options['request_timeout'],
            ],
            timeout: $options['process_timeout'],
            workingDirectory: $workdir,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function readReport(string $workdir, ToolResult $result, array $options): string
    {
        if ($result->timedOut) {
            throw new SourceTimeoutException('A execução do Holehe excedeu o tempo limite.');
        }

        // O relatório é a única prova confiável de sucesso (o exit code do
        // Holehe v1.61 é 1 mesmo quando a execução funciona).
        $file = $workdir !== '' ? $this->workspace->file($workdir, self::CSV_FILENAME_PATTERN) : null;

        if (! $file instanceof SplFileInfo) {
            throw new SourceUnavailableException('O Holehe não produziu relatório de resultados.');
        }

        if ($file->getSize() > $options['max_report_bytes']) {
            throw new SourceUnavailableException('O relatório do Holehe excedeu o tamanho máximo permitido.');
        }

        return (string) $file->getContents();
    }
}

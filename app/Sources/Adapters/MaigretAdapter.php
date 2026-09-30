<?php

namespace App\Sources\Adapters;

use App\Sources\Adapters\Maigret\MaigretReportParser;
use App\Sources\Exceptions\SourceInvalidInputException;
use App\Sources\Exceptions\SourceTimeoutException;
use App\Sources\Exceptions\SourceUnavailableException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\InvestigationSource;
use App\Sources\Runners\ToolCommand;
use App\Sources\Runners\ToolOutput;
use App\Sources\Runners\ToolRunner;
use App\Sources\Runners\ToolVersionDetector;
use App\Sources\Runners\ToolWorkspace;
use App\Sources\SourceOutcome;
use App\Sources\SourceResult;

/**
 * Integração real com o Maigret (https://github.com/soxoj/maigret, licença MIT).
 *
 * Requisitos: Python >= 3.10 e `pip install maigret==0.6.6` na imagem
 * (ver docker/php/Dockerfile). Comportamento:
 *
 * - varredura com escopo limitado (`--top-sites`) e timeout por requisição;
 * - banco de dados local do Maigret e `--no-autoupdate` (execução determinística);
 * - execução isolada (diretório temporário próprio, removido ao final);
 * - entrada do usuário vai como **elemento separado da argv** e é validada antes
 *   de qualquer execução (nunca vira shell);
 * - o relatório bruto é convertido para o contrato comum (`SourceResult`) pelo
 *   `MaigretReportParser`.
 *
 * A decisão de usar o Maigret real ou o mock é da configuração
 * (`config/sources.php` → `maigret.driver`), não do adapter.
 */
final class MaigretAdapter implements InvestigationSource
{
    /** Versão do adapter (participa da chave de cache). */
    private const ADAPTER_VERSION = 'v2';

    /** Usernames públicos aceitos (sem espaços, sem iniciar com `-`). */
    private const USERNAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    private const REPORT_FILENAME_PATTERN = '/^report_.*_simple\.json$/';

    /** Versão reportada por `<binário> --version`. */
    private const VERSION_PATTERN = '/maigret\s+v?(\d+\.\d+(?:\.\d+)?)/i';

    public function __construct(
        private readonly ToolRunner $runner,
        private readonly MaigretReportParser $parser,
        private readonly ToolWorkspace $workspace,
        private readonly ToolVersionDetector $versions,
    ) {}

    public function key(): string
    {
        return 'maigret';
    }

    public function name(): string
    {
        return 'Maigret';
    }

    public function version(): string
    {
        return self::ADAPTER_VERSION;
    }

    public function supports(InvestigationInput $input): bool
    {
        return $input->username !== null && $this->isValidUsername($input->username);
    }

    public function execute(InvestigationContext $context): SourceOutcome
    {
        $username = $context->input->username;

        if ($username === null || ! $this->isValidUsername($username)) {
            throw new SourceInvalidInputException('O Maigret exige um username público válido.');
        }

        $options = $this->options($context);
        $workdir = $this->workspace->create((string) config('sources.maigret.workdir', storage_path('app/maigret')));

        try {
            $result = $this->runner->run($this->command($username, $workdir, $options));

            if ($result->timedOut) {
                throw new SourceTimeoutException('A execução do Maigret excedeu o tempo limite.');
            }

            $findings = $this->parser->parse($this->readReport($workdir, $options));

            return new SourceOutcome(
                findings: $findings,
                warnings: ToolOutput::publicWarnings($result->stdout, $result->stderr),
                metadata: array_filter([
                    'tool' => 'maigret',
                    'tool_version' => $this->versions->detect(
                        $this->binary(),
                        self::VERSION_PATTERN,
                        $workdir,
                        min(15, $options['process_timeout']),
                    ),
                    'sites_checked' => $options['top_sites'],
                    'accounts_found' => count($findings),
                    'exit_code' => $result->exitCode,
                ], static fn (mixed $value): bool => $value !== null),
            );
        } finally {
            $this->workspace->cleanup($workdir);
        }
    }

    public function normalize(array $raw): SourceResult
    {
        $siteName = $raw['site'] ?? $raw['site_name'] ?? null;

        if (is_string($siteName)) {
            $result = $this->parser->toSourceResult($siteName, $raw);

            if ($result !== null) {
                return $result;
            }
        }

        return new SourceResult(
            source: $this->key(),
            entityType: $raw['entity_type'] ?? 'social_profile',
            value: $raw['value'] ?? $raw['username'] ?? (is_string($siteName) ? $siteName : ''),
            url: $raw['url'] ?? $raw['url_user'] ?? null,
            title: $raw['title'] ?? (is_string($siteName) ? $siteName : null),
            description: $raw['description'] ?? null,
            confidence: $raw['confidence'] ?? null,
            evidence: $raw['evidence'] ?? [],
            metadata: $raw['metadata'] ?? [],
        );
    }

    public function healthCheck(): bool
    {
        $result = $this->runner->run(new ToolCommand(
            binary: $this->binary(),
            arguments: ['--version'],
            timeout: 15,
        ));

        return str_contains(strtolower($result->stdout.$result->stderr), 'maigret');
    }

    private function isValidUsername(string $username): bool
    {
        return preg_match(self::USERNAME_PATTERN, $username) === 1;
    }

    private function binary(): string
    {
        return (string) config('sources.maigret.binary', 'maigret');
    }

    /**
     * Opções efetivas: configuração da aplicação sobreposta por
     * `source_definitions.config` (contrato `SourceDescriptor`).
     *
     * @return array{request_timeout: int, process_timeout: int, top_sites: int, max_connections: int, extract: bool, database: ?string, max_report_bytes: int}
     */
    private function options(InvestigationContext $context): array
    {
        $config = config('sources.maigret');
        $overrides = $context->source?->config ?? [];

        $processTimeout = (int) ($overrides['process_timeout'] ?? $config['process_timeout']);

        // Nunca ultrapassa o timeout declarado pela fonte (menor que o timeout
        // do job, para a execução terminar antes do worker desistir).
        if ($context->source !== null && $context->source->timeout > 0) {
            $processTimeout = min($processTimeout, $context->source->timeout);
        }

        $database = $overrides['database'] ?? $config['database'];

        return [
            'request_timeout' => max(1, (int) ($overrides['request_timeout'] ?? $config['request_timeout'])),
            'process_timeout' => max(5, $processTimeout),
            'top_sites' => max(1, (int) ($overrides['top_sites'] ?? $config['top_sites'])),
            'max_connections' => max(1, (int) ($overrides['max_connections'] ?? $config['max_connections'])),
            'extract' => (bool) ($overrides['extract'] ?? $config['extract']),
            'database' => is_string($database) && $database !== '' ? $database : null,
            'max_report_bytes' => max(1024, (int) ($overrides['max_report_bytes'] ?? config('sources.maigret.max_report_bytes', 5_000_000))),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function command(string $username, string $workdir, array $options): ToolCommand
    {
        // O username é um elemento próprio da argv — jamais concatenado em shell.
        $arguments = [
            $username,
            '--id-type', 'username',
            '--timeout', (string) $options['request_timeout'],
            '--top-sites', (string) $options['top_sites'],
            '--max-connections', (string) $options['max_connections'],
            '--no-color',
            '--no-progressbar',
            '--no-autoupdate',
            '--no-recursion',
            '--print-errors',
            '-J', 'simple',
            '-fo', $workdir,
        ];

        if (! $options['extract']) {
            $arguments[] = '--no-extracting';
        }

        if ($options['database'] !== null) {
            $arguments[] = '--db';
            $arguments[] = $options['database'];
        }

        return new ToolCommand(
            binary: $this->binary(),
            arguments: $arguments,
            timeout: $options['process_timeout'],
            workingDirectory: $workdir,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function readReport(string $workdir, array $options): array
    {
        $file = $this->workspace->file($workdir, self::REPORT_FILENAME_PATTERN);

        if ($file === null) {
            throw new SourceUnavailableException('O Maigret não produziu relatório de resultados.');
        }

        if ($file->getSize() > $options['max_report_bytes']) {
            throw new SourceUnavailableException('O relatório do Maigret excedeu o tamanho máximo permitido.');
        }

        $decoded = json_decode((string) $file->getContents(), true);

        if (! is_array($decoded)) {
            throw new SourceUnavailableException('O relatório do Maigret veio em formato inesperado.');
        }

        return $decoded;
    }
}

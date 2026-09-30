<?php

namespace App\Sources\Adapters;

use App\Sources\Adapters\Sherlock\SherlockReportParser;
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

/**
 * Integração real com o Sherlock (https://github.com/sherlock-project/sherlock,
 * licença MIT).
 *
 * Requisitos: Python >= 3.9 e `pip install sherlock-project` na imagem (ver
 * docker/php/Dockerfile). Reutiliza a mesma camada de execução segura do
 * Maigret (`ToolRunner`, `ToolWorkspace`, `ToolVersionDetector`, `ToolOutput`):
 * apenas os argumentos da ferramenta e o parser são específicos deste adapter.
 *
 * Particularidades do Sherlock (0.16.x):
 * - não exporta JSON; o resultado estruturado vem do CSV (`--csv`);
 * - não possui limite "top N": o escopo é definido por uma lista de sites
 *   (`--site`, configurável) para respeitar as fontes e caber no timeout;
 * - a varredura é sequencial e o CSV só é escrito ao final, então uma execução
 *   interrompida cai para as linhas `[+]` já impressas em stdout (resultados
 *   parciais preservados com aviso).
 */
final class SherlockAdapter implements InvestigationSource
{
    /** Versão do adapter (participa da chave de cache). */
    private const ADAPTER_VERSION = 'v1';

    /** Usernames públicos aceitos (sem espaços, sem iniciar com `-`). */
    private const USERNAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    private const CSV_FILENAME_PATTERN = '/\.csv$/i';

    /** Versão reportada por `<binário> --version`. */
    private const VERSION_PATTERN = '/sherlock\s+v?(\d+\.\d+(?:\.\d+)?)/i';

    public function __construct(
        private readonly ToolRunner $runner,
        private readonly SherlockReportParser $parser,
        private readonly ToolWorkspace $workspace,
        private readonly ToolVersionDetector $versions,
    ) {}

    public function key(): string
    {
        return 'sherlock';
    }

    public function name(): string
    {
        return 'Sherlock';
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
            throw new SourceInvalidInputException('O Sherlock exige um username público válido.');
        }

        $options = $this->options($context);
        $workdir = $this->workspace->create((string) config('sources.sherlock.workdir', storage_path('app/sherlock')));

        try {
            $result = $this->runner->run($this->command($username, $workdir, $options));

            $findings = $this->readFindings($workdir, $username, $result);
            $warnings = ToolOutput::publicWarnings($result->stdout, $result->stderr);

            if ($result->timedOut) {
                if ($findings === []) {
                    throw new SourceTimeoutException('A execução do Sherlock excedeu o tempo limite.');
                }

                $warnings[] = 'Tempo limite atingido: resultados parciais (a varredura não terminou).';
            }

            return new SourceOutcome(
                findings: $findings,
                warnings: array_values(array_unique($warnings)),
                metadata: array_filter([
                    'tool' => 'sherlock',
                    'tool_version' => $this->versions->detect(
                        $this->binary(),
                        self::VERSION_PATTERN,
                        $workdir,
                        min(15, $options['process_timeout']),
                    ),
                    'sites_checked' => $options['sites'] === [] ? null : count($options['sites']),
                    'accounts_found' => count($findings),
                    'exit_code' => $result->exitCode,
                    'partial' => $result->timedOut ?: null,
                ], static fn (mixed $value): bool => $value !== null),
            );
        } finally {
            $this->workspace->cleanup($workdir);
        }
    }

    public function normalize(array $raw): SourceResult
    {
        return $this->parser->toSourceResult($raw) ?? new SourceResult(
            source: $this->key(),
            entityType: $raw['entity_type'] ?? 'social_profile',
            value: $raw['value'] ?? $raw['username'] ?? '',
            url: $raw['url'] ?? $raw['url_user'] ?? null,
            title: $raw['title'] ?? $raw['name'] ?? null,
            description: $raw['description'] ?? null,
            confidence: $raw['confidence'] ?? null,
            evidence: $raw['evidence'] ?? [],
            metadata: $raw['metadata'] ?? [],
        );
    }

    public function healthCheck(): bool
    {
        return $this->versions->detect($this->binary(), self::VERSION_PATTERN) !== null;
    }

    private function isValidUsername(string $username): bool
    {
        return preg_match(self::USERNAME_PATTERN, $username) === 1;
    }

    private function binary(): string
    {
        return (string) config('sources.sherlock.binary', 'sherlock');
    }

    /**
     * Opções efetivas: configuração da aplicação sobreposta por
     * `source_definitions.config` (contrato `SourceDescriptor`).
     *
     * @return array{request_timeout: int, process_timeout: int, sites: array<int, string>}
     */
    private function options(InvestigationContext $context): array
    {
        $config = config('sources.sherlock');
        $overrides = $context->source?->config ?? [];

        $processTimeout = (int) ($overrides['process_timeout'] ?? $config['process_timeout']);

        if ($context->source !== null && $context->source->timeout > 0) {
            $processTimeout = min($processTimeout, $context->source->timeout);
        }

        $sites = $overrides['sites'] ?? $config['sites'];

        if (is_string($sites)) {
            $sites = explode(',', $sites);
        }

        return [
            'request_timeout' => max(1, (int) ($overrides['request_timeout'] ?? $config['request_timeout'])),
            'process_timeout' => max(5, $processTimeout),
            'sites' => is_array($sites)
                ? array_values(array_filter(array_map('trim', $sites), static fn (mixed $site): bool => is_string($site) && $site !== ''))
                : [],
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
            '--timeout', (string) $options['request_timeout'],
            '--no-color',
            '--print-found',
            '--local',
            '--csv',
            '-fo', $workdir,
        ];

        foreach ($options['sites'] as $site) {
            $arguments[] = '--site';
            $arguments[] = $site;
        }

        return new ToolCommand(
            binary: $this->binary(),
            arguments: $arguments,
            timeout: $options['process_timeout'],
            workingDirectory: $workdir,
        );
    }

    /**
     * @return array<int, SourceResult>
     */
    private function readFindings(string $workdir, string $username, ToolResult $result): array
    {
        $file = $this->workspace->file($workdir, self::CSV_FILENAME_PATTERN);

        if ($file !== null) {
            return $this->parser->parseCsv((string) $file->getContents());
        }

        $partial = $this->parser->parseFoundLines($result->stdout, $username);

        if ($partial !== [] || $result->timedOut) {
            return $partial;
        }

        throw new SourceUnavailableException('O Sherlock não produziu resultados legíveis.');
    }
}

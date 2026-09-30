<?php

namespace App\Sources\Adapters;

use App\Sources\Adapters\WhatsMyName\WhatsMyNameChecker;
use App\Sources\Adapters\WhatsMyName\WhatsMyNameDataset;
use App\Sources\Adapters\WhatsMyName\WhatsMyNameReportParser;
use App\Sources\Exceptions\SourceInvalidInputException;
use App\Sources\Exceptions\SourceTimeoutException;
use App\Sources\Exceptions\SourceUnavailableException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\InvestigationSource;
use App\Sources\SourceOutcome;
use App\Sources\SourceResult;

/**
 * WhatsMyName como fonte independente.
 *
 * Modo de execução atual do projeto upstream (verificado no prompt 34): o
 * WhatsMyName é um **dataset** público (CC BY-SA 4.0, © Micah Hoffman e
 * colaboradores); os checkers embutidos foram removidos em maio/2023 e a busca
 * é feita por ferramentas externas que consomem o `wmn-data.json` segundo o
 * `wmn-data-schema.json`. Este adapter fixa o dataset na imagem e executa as
 * verificações conforme esse schema, convertendo o resultado para o contrato
 * comum — sem contornar autenticação, CAPTCHA ou paywall.
 */
final class WhatsMyNameAdapter implements InvestigationSource
{
    /** Versão do adapter (participa da chave de cache). */
    private const ADAPTER_VERSION = 'v1';

    /** Usernames públicos aceitos (sem espaços, sem iniciar com `-`). */
    private const USERNAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    public function __construct(
        private readonly WhatsMyNameDataset $dataset,
        private readonly WhatsMyNameChecker $checker,
        private readonly WhatsMyNameReportParser $parser,
    ) {}

    public function key(): string
    {
        return 'whatsmyname';
    }

    public function name(): string
    {
        return 'WhatsMyName';
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
            throw new SourceInvalidInputException('O WhatsMyName exige um username público válido.');
        }

        $options = $this->options($context);
        $data = $this->dataset->load($options['database'], $options['revision']);

        $sites = $this->dataset->select(
            $data['sites'],
            $options['categories'],
            $options['excluded_categories'],
            $options['sites'],
            $options['max_sites'],
        );

        if ($sites === []) {
            throw new SourceUnavailableException('Nenhum site do WhatsMyName é elegível com os filtros configurados.');
        }

        $report = $this->checker->check($sites, $username, $options);
        $findings = $this->parser->parse($report['found']);

        // Nenhuma resposta válida e tempo não esgotado: a fonte está inacessível.
        if ($report['checked'] === 0 && ! $report['timed_out']) {
            throw new SourceUnavailableException('A fonte WhatsMyName não respondeu às verificações.');
        }

        // Orçamento esgotado sem nenhuma descoberta: nada foi entregue.
        if ($report['timed_out'] && $findings === []) {
            throw new SourceTimeoutException('A verificação do WhatsMyName excedeu o tempo limite.');
        }

        $warnings = [];

        if ($report['timed_out']) {
            $warnings[] = 'Tempo limite atingido: resultados parciais (a verificação não terminou).';
        }

        if ($report['errors'] > 0) {
            $warnings[] = $report['errors'].' site(s) não puderam ser avaliados nesta execução.';
        }

        return new SourceOutcome(
            findings: $findings,
            warnings: $warnings,
            metadata: array_filter([
                'tool' => 'whatsmyname',
                'tool_version' => $data['revision'],
                'dataset_license' => 'CC BY-SA 4.0',
                'sites_checked' => count($sites),
                'sites_reachable' => $report['checked'],
                'sites_unresolved' => $report['errors'] > 0 ? $report['errors'] : null,
                'accounts_found' => count($findings),
                'partial' => $report['timed_out'] ?: null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    public function normalize(array $raw): SourceResult
    {
        return $this->parser->toSourceResult($raw) ?? new SourceResult(
            source: $this->key(),
            entityType: $raw['entity_type'] ?? 'social_profile',
            value: $raw['value'] ?? $raw['username'] ?? '',
            url: $raw['url'] ?? null,
            title: $raw['title'] ?? $raw['site'] ?? null,
            description: $raw['description'] ?? null,
            confidence: $raw['confidence'] ?? null,
            evidence: $raw['evidence'] ?? [],
            metadata: $raw['metadata'] ?? [],
        );
    }

    public function healthCheck(): bool
    {
        try {
            $data = $this->dataset->load(
                (string) config('sources.whatsmyname.database', ''),
                $this->stringOrNull(config('sources.whatsmyname.revision')),
            );
        } catch (SourceUnavailableException) {
            return false;
        }

        return $data['sites'] !== [];
    }

    private function isValidUsername(string $username): bool
    {
        return preg_match(self::USERNAME_PATTERN, $username) === 1;
    }

    /**
     * Opções efetivas: configuração da aplicação sobreposta por
     * `source_definitions.config` (contrato `SourceDescriptor`).
     *
     * @return array<string, mixed>
     */
    private function options(InvestigationContext $context): array
    {
        $config = config('sources.whatsmyname');
        $overrides = $context->source?->config ?? [];

        $executionTimeout = (int) ($overrides['execution_timeout'] ?? $config['execution_timeout']);

        if ($context->source !== null && $context->source->timeout > 0) {
            $executionTimeout = min($executionTimeout, $context->source->timeout);
        }

        return [
            'database' => (string) ($overrides['database'] ?? $config['database']),
            'revision' => $this->stringOrNull($overrides['revision'] ?? $config['revision']),
            'request_timeout' => max(1, (int) ($overrides['request_timeout'] ?? $config['request_timeout'])),
            'execution_timeout' => max(1, $executionTimeout),
            'concurrency' => max(1, (int) ($overrides['concurrency'] ?? $config['concurrency'])),
            'max_sites' => max(1, (int) ($overrides['max_sites'] ?? $config['max_sites'])),
            'categories' => $this->stringList($overrides['categories'] ?? $config['categories'] ?? []),
            'excluded_categories' => $this->stringList($overrides['excluded_categories'] ?? $config['excluded_categories'] ?? []),
            'sites' => $this->stringList($overrides['sites'] ?? $config['sites'] ?? []),
            'user_agent' => (string) ($overrides['user_agent'] ?? $config['user_agent']),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return is_array($value)
            ? array_values(array_filter(array_map('trim', $value), static fn (mixed $item): bool => is_string($item) && $item !== ''))
            : [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

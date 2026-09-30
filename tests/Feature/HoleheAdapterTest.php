<?php

namespace Tests\Feature;

use App\Sources\Adapters\Holehe\HoleheReportParser;
use App\Sources\Adapters\HoleheAdapter;
use App\Sources\Adapters\HoleheMockAdapter;
use App\Sources\Exceptions\SourceInvalidInputException;
use App\Sources\Exceptions\SourceTimeoutException;
use App\Sources\Exceptions\SourceUnavailableException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\Runners\ToolCommand;
use App\Sources\Runners\ToolResult;
use App\Sources\Runners\ToolVersionDetector;
use App\Sources\Runners\ToolWorkspace;
use App\Sources\SourceDescriptor;
use App\Sources\SourceRegistry;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\FakeToolRunner;
use Tests\TestCase;

/**
 * Adapter real do Holehe sem tocar na rede: valida a argv (com
 * `--no-password-recovery` obrigatório), o isolamento/limpeza, o mapeamento de
 * falhas e a conversão do CSV para o contrato comum.
 */
class HoleheAdapterTest extends TestCase
{
    private FakeToolRunner $runner;

    private HoleheAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sources.holehe.workdir' => storage_path('framework/testing/holehe')]);

        $this->runner = new FakeToolRunner;
        $this->adapter = new HoleheAdapter(
            $this->runner,
            new HoleheReportParser,
            new ToolWorkspace(new Filesystem),
            new ToolVersionDetector($this->runner),
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(storage_path('framework/testing/holehe'));

        parent::tearDown();
    }

    private function context(string $email = 'alvo@example.test'): InvestigationContext
    {
        return new InvestigationContext(
            input: new InvestigationInput(email: $email),
            investigationRunId: 1,
            sourceRunId: 1,
            source: new SourceDescriptor(slug: 'holehe', type: 'email', timeout: 45),
        );
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/holehe/holehe_results.csv');
    }

    /**
     * O Holehe não expõe `--version`; a versão é obtida com `--help`.
     */
    private function isVersionProbe(ToolCommand $command): bool
    {
        return in_array('--version', $command->arguments, true) || in_array('--help', $command->arguments, true);
    }

    /**
     * O Holehe escreve o CSV no diretório corrente e retorna exit code 1 mesmo
     * em execução bem-sucedida.
     */
    private function withCsv(int $exitCode = 1): void
    {
        $this->runner->onRun = function (ToolCommand $command) use ($exitCode): ToolResult {
            if ($this->isVersionProbe($command)) {
                return new ToolResult(exitCode: 0, stdout: "holehe v1.61\n", stderr: '', durationMs: 5);
            }

            file_put_contents($command->workingDirectory.'/holehe_1234_alvo_results.csv', $this->fixture());

            return new ToolResult(exitCode: $exitCode, stdout: '', stderr: "All results have been exported\n", durationMs: 1200);
        };
    }

    public function test_supports_only_valid_emails(): void
    {
        $this->assertTrue($this->adapter->supports(new InvestigationInput(email: 'alvo@example.test')));
        $this->assertTrue($this->adapter->supports(new InvestigationInput(email: 'a.b+c@sub.dominio.com.br')));

        $this->assertFalse($this->adapter->supports(new InvestigationInput(email: 'sem-arroba')));
        $this->assertFalse($this->adapter->supports(new InvestigationInput(email: '-x@example.test')));
        $this->assertFalse($this->adapter->supports(new InvestigationInput(email: 'a b@example.test')));
        $this->assertFalse($this->adapter->supports(new InvestigationInput(username: 'joao')));
    }

    public function test_finds_services_and_disables_password_recovery(): void
    {
        $this->withCsv();

        $outcome = $this->adapter->execute($this->context());

        $this->assertSame(['github.com', 'spotify.com'], array_map(static fn ($finding) => $finding->title, $outcome->findings));
        $this->assertSame('holehe', $outcome->metadata['tool']);
        $this->assertSame('1.61', $outcome->metadata['tool_version']);
        $this->assertSame(2, $outcome->metadata['accounts_found']);
        $this->assertFalse($outcome->metadata['password_recovery']);
        $this->assertStringContainsString('Não prova que uma pessoa controla aquela conta', $outcome->metadata['disclaimer']);
        $this->assertSame(1, $outcome->metadata['exit_code']);

        // Cada descoberta carrega o aviso de limitação do sinal de e-mail.
        foreach ($outcome->findings as $finding) {
            $this->assertSame(0.5, $finding->confidence);
            $this->assertSame('email_registration_indicator', $finding->evidence[0]['type']);
        }

        $command = $this->runner->commands[0];

        $this->assertSame('holehe', $command->binary);
        $this->assertSame('alvo@example.test', $command->arguments[0]);
        $this->assertContains('--no-password-recovery', $command->arguments);
        $this->assertContains('--only-used', $command->arguments);
        $this->assertContains('--csv', $command->arguments);
        $this->assertContains('--no-clear', $command->arguments);
        $this->assertSame(45, $command->timeout);

        // Diretório isolado é limpo ao final da execução.
        $this->assertNotNull($command->workingDirectory);
        $this->assertFalse(is_dir($command->workingDirectory));
    }

    public function test_empty_result_when_email_is_not_registered_anywhere(): void
    {
        $this->runner->onRun = function (ToolCommand $command): ToolResult {
            if ($this->isVersionProbe($command)) {
                return new ToolResult(exitCode: 0, stdout: "holehe v1.61\n", stderr: '', durationMs: 5);
            }

            file_put_contents(
                $command->workingDirectory.'/holehe_9_alvo_results.csv',
                "name,domain,method,frequent_rate_limit,rateLimit,exists,emailrecovery,phoneNumber,others\naboutme,about.me,register,False,True,False,,,\n",
            );

            return new ToolResult(exitCode: 1, stdout: '', stderr: '', durationMs: 900);
        };

        $outcome = $this->adapter->execute($this->context());

        $this->assertTrue($outcome->isEmpty());
        $this->assertSame(0, $outcome->metadata['accounts_found']);
    }

    public function test_missing_report_is_mapped_to_unavailable(): void
    {
        $this->runner->onRun = fn (ToolCommand $command): ToolResult => new ToolResult(1, '', 'traceback', 100);

        $this->expectException(SourceUnavailableException::class);

        $this->adapter->execute($this->context());
    }

    public function test_timeout_is_mapped_to_timeout(): void
    {
        $this->runner->onRun = fn (ToolCommand $command): ToolResult => new ToolResult(
            exitCode: ToolResult::EXIT_TIMEOUT,
            stdout: '',
            stderr: '',
            durationMs: 45000,
            timedOut: true,
        );

        $this->expectException(SourceTimeoutException::class);

        $this->adapter->execute($this->context());
    }

    public function test_invalid_email_is_rejected_without_running_the_tool(): void
    {
        try {
            $this->adapter->execute($this->context('-x --csv'));
            $this->fail('Esperava SourceInvalidInputException.');
        } catch (SourceInvalidInputException) {
            $this->assertSame([], $this->runner->commands);
        }
    }

    public function test_normalize_converts_raw_csv_row(): void
    {
        $result = $this->adapter->normalize([
            'email' => 'alvo@example.test',
            'exists' => 'True',
            'domain' => 'github.com',
            'name' => 'github',
            'method' => 'register',
        ]);

        $this->assertSame('holehe', $result->source);
        $this->assertSame('email_account', $result->entityType);
        $this->assertSame('https://github.com', $result->url);
    }

    public function test_health_check_reads_the_tool_version(): void
    {
        $this->runner->onRun = fn (ToolCommand $command): ToolResult => new ToolResult(0, "holehe v1.61\n", '', 5);

        $this->assertTrue($this->adapter->healthCheck());
    }

    public function test_registry_swaps_mock_and_real_by_configuration(): void
    {
        config(['sources.holehe.driver' => 'process']);
        app()->forgetInstance(SourceRegistry::class);
        $this->assertInstanceOf(HoleheAdapter::class, app(SourceRegistry::class)->get('holehe'));

        config(['sources.holehe.driver' => 'mock']);
        app()->forgetInstance(SourceRegistry::class);
        $this->assertInstanceOf(HoleheMockAdapter::class, app(SourceRegistry::class)->get('holehe'));
    }
}

<?php

namespace Tests\Feature;

use App\Sources\Adapters\Sherlock\SherlockReportParser;
use App\Sources\Adapters\SherlockAdapter;
use App\Sources\Adapters\SherlockMockAdapter;
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
 * Adapter real do Sherlock sem tocar na rede: montagem da argv, isolamento,
 * mapeamento de falhas e conversão do CSV para o contrato comum.
 */
class SherlockAdapterTest extends TestCase
{
    private FakeToolRunner $runner;

    private SherlockAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sources.sherlock.workdir' => storage_path('framework/testing/sherlock')]);

        $this->runner = new FakeToolRunner;
        $this->adapter = new SherlockAdapter(
            $this->runner,
            new SherlockReportParser,
            new ToolWorkspace(new Filesystem),
            new ToolVersionDetector($this->runner),
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(storage_path('framework/testing/sherlock'));

        parent::tearDown();
    }

    private function context(string $username): InvestigationContext
    {
        return new InvestigationContext(
            input: new InvestigationInput(username: $username),
            investigationRunId: 1,
            sourceRunId: 1,
            source: new SourceDescriptor(slug: 'sherlock', type: 'username', timeout: 45),
        );
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/sherlock/report_python.csv');
    }

    private function withCsv(): void
    {
        $this->runner->onRun = function (ToolCommand $command): ToolResult {
            if (in_array('--version', $command->arguments, true)) {
                return new ToolResult(exitCode: 0, stdout: "Sherlock v0.16.2\n", stderr: '', durationMs: 5);
            }

            file_put_contents($command->workingDirectory.'/python.csv', $this->fixture());

            return new ToolResult(exitCode: 0, stdout: "[!] Site X is unavailable\n", stderr: '', durationMs: 3000);
        };
    }

    public function test_supports_only_safe_public_usernames(): void
    {
        $this->assertTrue($this->adapter->supports(new InvestigationInput(username: 'joao.silva')));
        $this->assertFalse($this->adapter->supports(new InvestigationInput(username: '-o')));
        $this->assertFalse($this->adapter->supports(new InvestigationInput(username: 'a b')));
        $this->assertFalse($this->adapter->supports(new InvestigationInput(username: 'x;rm -rf /')));
        $this->assertFalse($this->adapter->supports(new InvestigationInput(email: 'a@b.c')));
    }

    public function test_executes_tool_with_separate_argv_and_parses_csv(): void
    {
        $this->withCsv();

        $outcome = $this->adapter->execute($this->context('python'));

        $this->assertCount(4, $outcome->findings);
        $this->assertSame('0.16.2', $outcome->metadata['tool_version']);
        $this->assertSame('sherlock', $outcome->metadata['tool']);
        $this->assertSame(4, $outcome->metadata['accounts_found']);
        $this->assertSame(count(config('sources.sherlock.sites')), $outcome->metadata['sites_checked']);
        $this->assertSame(['Site X is unavailable'], $outcome->warnings);

        $command = $this->runner->commands[0];

        $this->assertSame('sherlock', $command->binary);
        $this->assertSame('python', $command->arguments[0]);
        $this->assertContains('--local', $command->arguments);
        $this->assertContains('--print-found', $command->arguments);
        $this->assertContains('--csv', $command->arguments);
        $this->assertContains('-fo', $command->arguments);
        $this->assertContains('--site', $command->arguments);
        $this->assertSame(45, $command->timeout);

        // Diretório isolado é limpo ao final da execução.
        $this->assertNotNull($command->workingDirectory);
        $this->assertFalse(is_dir($command->workingDirectory));
    }

    public function test_timeout_with_partial_output_keeps_findings_and_warns(): void
    {
        $this->runner->onRun = function (ToolCommand $command): ToolResult {
            if (in_array('--version', $command->arguments, true)) {
                return new ToolResult(exitCode: 0, stdout: "Sherlock v0.16.2\n", stderr: '', durationMs: 5);
            }

            return new ToolResult(
                exitCode: ToolResult::EXIT_TIMEOUT,
                stdout: "[+] GitHub: https://www.github.com/python\n[+] Telegram: https://t.me/python\n",
                stderr: '',
                durationMs: 45000,
                timedOut: true,
            );
        };

        $outcome = $this->adapter->execute($this->context('python'));

        $this->assertCount(2, $outcome->findings);
        $this->assertContains('Tempo limite atingido: resultados parciais (a varredura não terminou).', $outcome->warnings);
        $this->assertTrue($outcome->metadata['partial']);

        $workdir = $this->runner->commands[0]->workingDirectory;
        $this->assertFalse(is_dir($workdir));
    }

    public function test_timeout_without_output_is_mapped_to_timeout(): void
    {
        $this->runner->onRun = fn (ToolCommand $command): ToolResult => new ToolResult(
            exitCode: ToolResult::EXIT_TIMEOUT,
            stdout: '',
            stderr: '',
            durationMs: 45000,
            timedOut: true,
        );

        $this->expectException(SourceTimeoutException::class);

        $this->adapter->execute($this->context('python'));
    }

    public function test_missing_csv_and_no_stdout_is_mapped_to_unavailable(): void
    {
        $this->runner->onRun = fn (ToolCommand $command): ToolResult => new ToolResult(1, '', 'fatal', 100);

        $this->expectException(SourceUnavailableException::class);

        $this->adapter->execute($this->context('python'));
    }

    public function test_unsafe_username_is_rejected_without_running_the_tool(): void
    {
        try {
            $this->adapter->execute($this->context('-o /etc/passwd'));
            $this->fail('Esperava SourceInvalidInputException.');
        } catch (SourceInvalidInputException) {
            $this->assertSame([], $this->runner->commands);
        }
    }

    public function test_normalize_converts_raw_csv_row(): void
    {
        $result = $this->adapter->normalize([
            'name' => 'GitHub',
            'username' => 'python',
            'url_user' => 'https://www.github.com/python',
            'exists' => 'Claimed',
        ]);

        $this->assertSame('sherlock', $result->source);
        $this->assertSame('python', $result->value);
        $this->assertSame('https://www.github.com/python', $result->url);
    }

    public function test_health_check_reads_the_tool_version(): void
    {
        $this->runner->onRun = fn (ToolCommand $command): ToolResult => new ToolResult(0, "Sherlock v0.16.2\n", '', 5);

        $this->assertTrue($this->adapter->healthCheck());
    }

    public function test_registry_swaps_mock_and_real_by_configuration(): void
    {
        config(['sources.sherlock.driver' => 'process']);
        app()->forgetInstance(SourceRegistry::class);
        $this->assertInstanceOf(SherlockAdapter::class, app(SourceRegistry::class)->get('sherlock'));

        config(['sources.sherlock.driver' => 'mock']);
        app()->forgetInstance(SourceRegistry::class);
        $this->assertInstanceOf(SherlockMockAdapter::class, app(SourceRegistry::class)->get('sherlock'));
    }
}

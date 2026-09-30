<?php

namespace Tests\Feature;

use App\Sources\Adapters\Maigret\MaigretReportParser;
use App\Sources\Adapters\MaigretAdapter;
use App\Sources\Adapters\MaigretMockAdapter;
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
 * Adapter real do Maigret sem tocar na rede: valida a montagem da argv, o
 * isolamento do diretório, o mapeamento de falhas e a conversão do relatório.
 * A execução real da ferramenta é coberta por `MaigretRealIntegrationTest`.
 */
class MaigretAdapterTest extends TestCase
{
    private FakeToolRunner $runner;

    private MaigretAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sources.maigret.workdir' => storage_path('framework/testing/maigret')]);

        $this->runner = new FakeToolRunner;
        $this->adapter = new MaigretAdapter(
            $this->runner,
            new MaigretReportParser,
            new ToolWorkspace(new Filesystem),
            new ToolVersionDetector($this->runner),
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(storage_path('framework/testing/maigret'));

        parent::tearDown();
    }

    private function context(string $username): InvestigationContext
    {
        return new InvestigationContext(
            input: new InvestigationInput(username: $username),
            investigationRunId: 1,
            sourceRunId: 1,
            source: new SourceDescriptor(slug: 'maigret', type: 'username', timeout: 45),
        );
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/maigret/report_python_simple.json');
    }

    public function test_supports_only_safe_public_usernames(): void
    {
        $this->assertTrue($this->adapter->supports(new InvestigationInput(username: 'joao.silva')));
        $this->assertTrue($this->adapter->supports(new InvestigationInput(username: 'dev_2026')));

        $this->assertFalse($this->adapter->supports(new InvestigationInput(username: '-o')));
        $this->assertFalse($this->adapter->supports(new InvestigationInput(username: 'a b')));
        $this->assertFalse($this->adapter->supports(new InvestigationInput(username: 'x;rm -rf /')));
        $this->assertFalse($this->adapter->supports(new InvestigationInput(email: 'a@b.c')));
    }

    public function test_executes_tool_with_separate_argv_and_parses_report(): void
    {
        $this->runner->onRun = function (ToolCommand $command): ToolResult {
            if (($command->arguments[0] ?? null) === '--version') {
                return new ToolResult(exitCode: 0, stdout: "maigret 0.6.6\n", stderr: '', durationMs: 5);
            }

            file_put_contents($command->workingDirectory.'/report_python_simple.json', $this->fixture());

            return new ToolResult(
                exitCode: 0,
                stdout: "[!] Too many errors of type \"Bot protection\" (10.71%).\n",
                stderr: '',
                durationMs: 9000,
            );
        };

        $outcome = $this->adapter->execute($this->context('python'));

        $this->assertCount(2, $outcome->findings);
        $this->assertSame('0.6.6', $outcome->metadata['tool_version']);
        $this->assertSame(2, $outcome->metadata['accounts_found']);
        $this->assertSame(30, $outcome->metadata['sites_checked']);
        $this->assertSame(['Too many errors of type "Bot protection" (10.71%).'], $outcome->warnings);

        $command = $this->runner->commands[0];

        $this->assertSame('maigret', $command->binary);
        $this->assertSame('python', $command->arguments[0]);
        $this->assertContains('--id-type', $command->arguments);
        $this->assertContains('--no-autoupdate', $command->arguments);
        $this->assertContains('--no-extracting', $command->arguments);
        $this->assertContains('-J', $command->arguments);
        $this->assertContains('simple', $command->arguments);
        $this->assertContains((string) config('sources.maigret.top_sites'), $command->arguments);
        $this->assertSame(45, $command->timeout);

        // Diretório isolado é limpo ao final da execução.
        $this->assertNotNull($command->workingDirectory);
        $this->assertFalse(is_dir($command->workingDirectory));
    }

    public function test_timeout_is_mapped_and_workdir_is_cleaned(): void
    {
        $this->runner->onRun = fn (ToolCommand $command): ToolResult => new ToolResult(
            exitCode: ToolResult::EXIT_TIMEOUT,
            stdout: '',
            stderr: '',
            durationMs: 45000,
            timedOut: true,
        );

        $workdir = null;

        try {
            $this->adapter->execute($this->context('python'));
            $this->fail('Esperava SourceTimeoutException.');
        } catch (SourceTimeoutException) {
            $workdir = $this->runner->commands[0]->workingDirectory;
        }

        $this->assertNotNull($workdir);
        $this->assertFalse(is_dir($workdir));
    }

    public function test_missing_report_is_mapped_to_unavailable(): void
    {
        $this->runner->onRun = fn (ToolCommand $command): ToolResult => new ToolResult(1, '', 'fatal error', 100);

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

    public function test_normalize_converts_raw_maigret_entry(): void
    {
        $result = $this->adapter->normalize([
            'site' => 'GitHub',
            'username' => 'python',
            'url_user' => 'https://github.com/python',
        ]);

        $this->assertSame('maigret', $result->source);
        $this->assertSame('social_profile', $result->entityType);
        $this->assertSame('python', $result->value);
        $this->assertSame('https://github.com/python', $result->url);
    }

    public function test_health_check_reads_the_tool_version(): void
    {
        $this->runner->onRun = fn (ToolCommand $command): ToolResult => new ToolResult(0, "maigret 0.6.6\n", '', 5);

        $this->assertTrue($this->adapter->healthCheck());
    }

    public function test_registry_swaps_mock_and_real_by_configuration(): void
    {
        config(['sources.maigret.driver' => 'process']);
        app()->forgetInstance(SourceRegistry::class);
        $this->assertInstanceOf(MaigretAdapter::class, app(SourceRegistry::class)->get('maigret'));

        config(['sources.maigret.driver' => 'mock']);
        app()->forgetInstance(SourceRegistry::class);
        $this->assertInstanceOf(MaigretMockAdapter::class, app(SourceRegistry::class)->get('maigret'));
    }
}

<?php

namespace Tests\Feature;

use App\Services\SettingsService;
use App\Sources\Adapters\InstagramAdapter;
use App\Sources\Exceptions\SourceChallengeException;
use App\Sources\Exceptions\SourceSessionException;
use App\Sources\Integrations\InstagramSettings;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\Runners\ToolCommand;
use App\Sources\Runners\ToolProcessRunner;
use App\Sources\Runners\ToolResult;
use App\Sources\Runners\ToolRunner;
use App\Sources\Runners\ToolWorkspace;
use App\Sources\SourceDescriptor;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 48 — contrato do adapter real do Instagram, com um `ToolRunner` falso
 * (o script Python não é executado: o que se verifica é a tradução do JSON de
 * saída para o contrato de fontes e para os estados de sessão do painel).
 */
class InstagramAdapterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Runner falso: devolve a saída pré-programada e registra o comando montado
     * (para comprovar que o `sessionid` **não** vai na argv).
     */
    private function fakeRunner(string $stdout, bool $timedOut = false, int $exitCode = 0): ToolRunner
    {
        return new class($stdout, $timedOut, $exitCode) implements ToolRunner
        {
            public ?ToolCommand $command = null;

            public function __construct(
                private readonly string $stdout,
                private readonly bool $timedOut,
                private readonly int $exitCode,
            ) {}

            public function run(ToolCommand $command): ToolResult
            {
                $this->command = $command;

                return new ToolResult(
                    exitCode: $this->timedOut ? ToolResult::EXIT_TIMEOUT : $this->exitCode,
                    stdout: $this->stdout,
                    stderr: '',
                    durationMs: 42,
                    timedOut: $this->timedOut,
                );
            }
        };
    }

    private function adapter(ToolRunner $runner): InstagramAdapter
    {
        return new InstagramAdapter(
            new ToolProcessRunner($runner),
            new ToolWorkspace(new Filesystem),
            app(InstagramSettings::class),
        );
    }

    private function storeSession(string $session = '1234567890abcdef'): void
    {
        app(SettingsService::class)->set(InstagramSettings::KEY_SESSION_ID, $session);
    }

    private function context(string $username = 'exemplo'): InvestigationContext
    {
        return new InvestigationContext(
            input: new InvestigationInput(username: $username),
            source: new SourceDescriptor(slug: 'instagram', type: 'username', timeout: 30),
        );
    }

    /**
     * Perfil encontrado: um resultado com avatar (evidência visual), metadados
     * públicos e a marca da correlação por similaridade ainda pendente.
     */
    public function test_profile_is_mapped_with_avatar_evidence_and_no_secret_in_argv(): void
    {
        $this->storeSession('SEGREDO-DO-COOKIE-123456');

        $runner = $this->fakeRunner(json_encode([
            'ok' => true,
            'session_status' => 'HEALTHY',
            'found' => true,
            'instagrapi_version' => '2.7.4',
            'profile' => [
                'username' => 'exemplo',
                'user_id' => '123',
                'full_name' => 'Exemplo',
                'biography' => 'bio pública',
                'profile_url' => 'https://www.instagram.com/exemplo/',
                'avatar_url' => 'https://cdn.example.test/avatar.jpg',
                'is_private' => false,
                'is_verified' => true,
                'followers' => 1500,
                'posts' => 12,
            ],
        ]));

        $outcome = $this->adapter($runner)->execute($this->context());

        $this->assertCount(1, $outcome->findings);
        $finding = $outcome->findings[0];
        $this->assertSame('social_profile', $finding->entityType);
        $this->assertSame('exemplo', $finding->value);
        $this->assertSame('Exemplo', $finding->title);
        $this->assertSame(0.6, $finding->confidence);

        $avatars = array_values(array_filter(
            $finding->evidence,
            fn (array $item) => $item['type'] === 'avatar',
        ));
        $this->assertSame('https://cdn.example.test/avatar.jpg', $avatars[0]['value']);

        $this->assertSame('HEALTHY', $outcome->metadata['session_status']);
        $this->assertSame('2.7.4', $outcome->metadata['tool_version']);
        $this->assertSame('pending', $outcome->metadata['visual_correlation']);

        // O cookie não pode aparecer na linha de comando (argv é visível em `ps`).
        $argv = implode(' ', [$runner->command->binary, ...$runner->command->arguments]);
        $this->assertStringNotContainsString('SEGREDO-DO-COOKIE', $argv);
        $this->assertSame('SEGREDO-DO-COOKIE-123456', $runner->command->environment['INSTAGRAPI_SESSIONID']);
    }

    /**
     * Sessão expirada e desafio humano **não** são "0 resultados": interrompem a
     * execução com status `skipped`, o que estorna a reserva de crédito.
     */
    public function test_expired_session_and_challenge_stop_without_charging(): void
    {
        $this->storeSession();

        $expired = $this->adapter($this->fakeRunner(json_encode([
            'ok' => false,
            'session_status' => 'EXPIRED',
            'error_code' => 'session_invalid',
            'error' => 'login_required',
        ])));

        $this->expectException(SourceSessionException::class);
        $this->expectExceptionMessage('Atualize o sessionid');

        $expired->execute($this->context());
    }

    public function test_challenge_required_is_reported_separately(): void
    {
        $this->storeSession();

        $adapter = $this->adapter($this->fakeRunner(json_encode([
            'ok' => false,
            'session_status' => 'CHALLENGE_REQUIRED',
            'error_code' => 'session_invalid',
            'error' => 'challenge_required',
        ])));

        $this->expectException(SourceChallengeException::class);
        $adapter->execute($this->context());
    }

    /**
     * Sem credencial salva nada é executado: o desfecho é o de sessão ausente
     * (SKIPPED + estorno) e o operador recebe a orientação de configuração.
     */
    public function test_missing_session_fails_before_running_the_script(): void
    {
        $runner = $this->fakeRunner('{}');

        $this->expectException(SourceSessionException::class);
        $this->expectExceptionMessage('Configurações → Integrações');

        try {
            $this->adapter($runner)->execute($this->context());
        } finally {
            $this->assertNull($runner->command, 'O processo não deveria ter sido executado.');
        }
    }

    /**
     * O proxy de egresso (segredo com credenciais na URL) vai pelo **ambiente**,
     * nunca pela argv — mesmo cuidado do `sessionid`. Contorna o bloqueio de IP
     * de datacenter do Instagram sem expor a credencial em `ps`/logs.
     */
    public function test_proxy_is_passed_only_through_the_environment(): void
    {
        $this->storeSession();
        app(SettingsService::class)->set(
            InstagramSettings::KEY_PROXY,
            'http://usuario:senha@proxy.residencial:8080',
        );

        $runner = $this->fakeRunner(json_encode([
            'ok' => true,
            'session_status' => 'HEALTHY',
            'found' => true,
            'profile' => ['username' => 'exemplo'],
        ]));

        $this->adapter($runner)->execute($this->context());

        $this->assertSame(
            'http://usuario:senha@proxy.residencial:8080',
            $runner->command->environment['INSTAGRAPI_PROXY'],
        );

        // A credencial do proxy não pode aparecer na linha de comando.
        $argv = implode(' ', [$runner->command->binary, ...$runner->command->arguments]);
        $this->assertStringNotContainsString('proxy.residencial', $argv);
        $this->assertStringNotContainsString('usuario:senha', $argv);
    }

    /**
     * Sem proxy configurado, a variável simplesmente não é injetada (o cliente
     * instagrapi segue com o egresso padrão do container).
     */
    public function test_no_proxy_leaves_the_environment_untouched(): void
    {
        $this->storeSession();

        $runner = $this->fakeRunner(json_encode([
            'ok' => true,
            'session_status' => 'HEALTHY',
            'found' => true,
            'profile' => ['username' => 'exemplo'],
        ]));

        $this->adapter($runner)->execute($this->context());

        $this->assertArrayNotHasKey('INSTAGRAPI_PROXY', $runner->command->environment);
    }

    /**
     * O teste de conexão do painel precisa enxergar o **mesmo** egresso da
     * consulta real: sem o proxy, a sessão seria recusada justamente pelo IP de
     * datacenter que queremos contornar, e o diagnóstico ficaria verde/vermelho
     * por engano. Por isso o `checkSession()` também injeta `INSTAGRAPI_PROXY`.
     */
    public function test_check_session_also_uses_the_configured_proxy(): void
    {
        $this->storeSession();
        app(SettingsService::class)->set(
            InstagramSettings::KEY_PROXY,
            'socks5://usuario:senha@proxy.residencial:1080',
        );

        $runner = $this->fakeRunner(json_encode(['session_status' => 'HEALTHY']));

        $this->adapter($runner)->checkSession();

        $this->assertSame(
            'socks5://usuario:senha@proxy.residencial:1080',
            $runner->command->environment['INSTAGRAPI_PROXY'],
        );
        // Confirma que é de fato o modo de teste de conexão (--mode session).
        $this->assertContains('--mode', $runner->command->arguments);
        $this->assertContains('session', $runner->command->arguments);
    }

    /**
     * `normalizeProxy()` aceita apenas esquemas de proxy conhecidos com host e
     * descarta o resto — um valor inválido nunca chega ao cliente (quebraria
     * todas as requisições); o adapter segue sem proxy.
     */
    public function test_normalize_proxy_accepts_known_schemes_and_rejects_junk(): void
    {
        $this->assertSame(
            'http://usuario:senha@proxy:8080',
            InstagramSettings::normalizeProxy('http://usuario:senha@proxy:8080'),
        );
        $this->assertSame(
            'socks5://proxy:1080',
            InstagramSettings::normalizeProxy('  socks5://proxy:1080  '),
        );
        $this->assertSame(
            'socks5h://user:p@ss@proxy:1080',
            InstagramSettings::normalizeProxy('socks5h://user:p@ss@proxy:1080'),
        );

        $this->assertNull(InstagramSettings::normalizeProxy(null));
        $this->assertNull(InstagramSettings::normalizeProxy(''));
        $this->assertNull(InstagramSettings::normalizeProxy('CONFIGURE_ME'));
        $this->assertNull(InstagramSettings::normalizeProxy('proxy:8080')); // sem esquema
        $this->assertNull(InstagramSettings::normalizeProxy('ftp://proxy:21')); // esquema estranho
        $this->assertNull(InstagramSettings::normalizeProxy('http://')); // sem host
    }
}

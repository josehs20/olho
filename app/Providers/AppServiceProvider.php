<?php

namespace App\Providers;

use App\Models\Investigation;
use App\Models\User;
use App\Payments\FakePixProvider;
use App\Payments\PagBankProvider;
use App\Payments\PaymentProvider;
use App\Payments\PaymentSettings;
use App\Policies\InvestigationPolicy;
use App\Sources\Adapters\HoleheAdapter;
use App\Sources\Adapters\HoleheMockAdapter;
use App\Sources\Adapters\InstagramAdapter;
use App\Sources\Adapters\InstagramMockAdapter;
use App\Sources\Adapters\MaigretAdapter;
use App\Sources\Adapters\MaigretMockAdapter;
use App\Sources\Adapters\SherlockAdapter;
use App\Sources\Adapters\SherlockMockAdapter;
use App\Sources\Adapters\WhatsMyNameAdapter;
use App\Sources\Adapters\WhatsMyNameMockAdapter;
use App\Sources\InvestigationSource;
use App\Sources\Mocks\MockEmailSource;
use App\Sources\Mocks\MockUsernameSource;
use App\Sources\Mocks\MockWebSource;
use App\Sources\OsintMode;
use App\Sources\Runners\ProcessToolRunner;
use App\Sources\Runners\RecordingToolRunner;
use App\Sources\Runners\ToolProcessRunner;
use App\Sources\Runners\ToolRunner;
use App\Sources\Runners\ToolRunRecorder;
use App\Sources\Runners\ToolWorkspace;
use App\Sources\SourceRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentProvider::class, function (Application $app) {
            $settings = $app->make(PaymentSettings::class);

            return match ($settings->provider()) {
                PaymentSettings::PROVIDER_FAKE => new FakePixProvider,
                PaymentSettings::PROVIDER_PAGBANK => $app->make(PagBankProvider::class),
                default => throw new \RuntimeException('Payment provider não suportado: '.$settings->provider()),
            };
        });

        // Execução de ferramentas externas (docs/21): argv separado, timeout,
        // diretório isolado e ambiente controlado.
        //
        // O decorator apenas observa o `ToolResult` (prompt 47): a captura de
        // stdout/stderr/exit code fica ligada somente durante um teste individual
        // de módulo (`ToolRunRecorder::start()`). Fora dessa janela o
        // comportamento é exatamente o do runner de processo.
        $this->app->singleton(ToolRunRecorder::class);

        $this->app->bind(ToolRunner::class, fn (Application $app) => new RecordingToolRunner(
            new ProcessToolRunner,
            $app->make(ToolRunRecorder::class),
        ));

        // Runner de scripts auxiliares que devolvem JSON (prompt 48: instagrapi).
        // Passa pelo `ToolRunner` para herdar argv separado, timeout, diretório
        // isolado e captura pelo `ToolRunRecorder` (stdout/stderr/exit code no
        // painel de testes individuais).
        $this->app->bind(ToolProcessRunner::class, fn (Application $app) => new ToolProcessRunner(
            $app->make(ToolRunner::class),
        ));

        $this->app->bind(ToolWorkspace::class, fn () => new ToolWorkspace($this->app->make(Filesystem::class)));

        // `InstagramSettings` é resolvido **por método** (sem singleton), como o
        // `PaymentSettings`: o memo do `SettingsService` é por instância e um
        // singleton guardaria valores antigos entre requisições do mesmo processo
        // (Octane, testes, worker) — escritas de outra instância limpam o cache
        // compartilhado, mas não o memo local de quem já leu.

        $this->app->singleton(SourceRegistry::class, function (Application $app) {
            return new SourceRegistry([
                new MockUsernameSource,
                new MockEmailSource,
                new MockWebSource,
                $this->maigretSource($app),
                $this->sherlockSource($app),
                $this->whatsMyNameSource($app),
                $this->holeheSource($app),
                $this->instagramSource($app),
            ]);
        });
    }

    /**
     * Decide real vs. mock pelo modo efetivo (`OsintMode`), que já aplica a
     * validação de `APP_ENV`: integração real somente em produção.
     *
     * O driver histórico de cada fonte (`process`/`http`) não escolhe mais a
     * implementação — o ambiente vence para **todas** as fontes, de forma
     * transparente para o restante do domínio (mesmo contrato, mesmo slug).
     */
    private function useReal(): bool
    {
        return OsintMode::forced();
    }

    /**
     * Maigret real ou mock determinístico, conforme o modo efetivo
     * (`APP_ENV`/`OSINT_MODE`). Mesmo slug (`maigret`), mesmo contrato — trocar
     * a implementação não altera o restante do domínio.
     */
    private function maigretSource(Application $app): InvestigationSource
    {
        return $this->useReal()
            ? $app->make(MaigretAdapter::class)
            : new MaigretMockAdapter;
    }

    /**
     * Sherlock real ou mock determinístico, conforme o modo efetivo. Mesmo slug
     * (`sherlock`) e mesmo contrato.
     */
    private function sherlockSource(Application $app): InvestigationSource
    {
        return $this->useReal()
            ? $app->make(SherlockAdapter::class)
            : new SherlockMockAdapter;
    }

    /**
     * WhatsMyName real (`http`, verificações conforme o schema do dataset) ou
     * mock determinístico, conforme o modo efetivo. Mesmo slug (`whatsmyname`)
     * e contrato.
     */
    private function whatsMyNameSource(Application $app): InvestigationSource
    {
        return $this->useReal()
            ? $app->make(WhatsMyNameAdapter::class)
            : new WhatsMyNameMockAdapter;
    }

    /**
     * Holehe real (`process`, GPL-3.0, sem recuperação de senha) ou mock
     * determinístico, conforme o modo efetivo. Mesmo slug (`holehe`) e contrato.
     */
    private function holeheSource(Application $app): InvestigationSource
    {
        return $this->useReal()
            ? $app->make(HoleheAdapter::class)
            : new HoleheMockAdapter;
    }

    /**
     * Instagram (prompt 48): adapter real (script Python/instagrapi) ou mock
     * determinístico, conforme o modo efetivo. Mesmo slug (`instagram`) e
     * contrato.
     */
    private function instagramSource(Application $app): InvestigationSource
    {
        return $this->useReal()
            ? $app->make(InstagramAdapter::class)
            : new InstagramMockAdapter;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
        // Datas relativas (diffForHumans) e formatações seguem o idioma da aplicação.
        Carbon::setLocale(config('app.locale'));

        Gate::define('access-admin', fn (User $user) => $user->is_admin);
        Gate::policy(Investigation::class, InvestigationPolicy::class);

        // Investigação avulsa (prompt 41): freia criação em massa de cobranças e
        // força bruta/enumeração de tokens (limite por IP e por hash do token).
        RateLimiter::for('guest-create', fn (Request $request) => [
            Limit::perMinute(5)->by('guest-create-ip:'.$request->ip()),
            Limit::perHour(20)->by('guest-create-hour:'.$request->ip()),
        ]);

        RateLimiter::for('guest-access', function (Request $request) {
            $token = (string) $request->route('token');

            return [
                Limit::perMinute(60)->by('guest-access-ip:'.$request->ip()),
                Limit::perMinute(30)->by('guest-access-token:'.hash('sha256', $token)),
            ];
        });

        // Testes individuais de módulo (prompt 47 §12): cada disparo executa uma
        // ferramenta externa (processo/HTTP) e reserva crédito — limita rajadas
        // por administrador autenticado.
        RateLimiter::for('module-test', fn (Request $request) => [
            Limit::perMinute(6)->by('module-test-user:'.($request->user()?->id ?? 'guest')),
            Limit::perHour(60)->by('module-test-hour:'.($request->user()?->id ?? 'guest')),
        ]);

        // Sincronização forçada de pagamento PagBank (webhook sem entrega): cada
        // acionamento reconsulta o provedor externo — limita rajadas por
        // administrador autenticado, sem afetar o polling normal do cliente.
        RateLimiter::for('admin-sync', fn (Request $request) => [
            Limit::perMinute(30)->by('admin-sync-user:'.($request->user()?->id ?? 'guest')),
            Limit::perHour(300)->by('admin-sync-hour:'.($request->user()?->id ?? 'guest')),
        ]);

        // "Já paguei" (conta): cada clique reconsulta o PagBank. O limite por
        // pedido evita que várias abas/duplo clique multipliquem chamadas; o
        // limite por usuário segura rajadas sem impedir a tentativa legítima.
        RateLimiter::for('payment-verify', function (Request $request) {
            $payment = $request->route('payment');
            $paymentId = is_object($payment) ? $payment->getKey() : $payment;

            return [
                Limit::perMinute(6)->by('payment-verify-user:'.($request->user()?->id ?? 'guest')),
                Limit::perMinute(3)->by('payment-verify-order:'.$paymentId),
            ];
        });

        // "Já paguei" (avulsa): mesma consulta externa, sem sessão autenticada.
        // O token é a credencial; o limite por hash evita varredura de pedidos.
        RateLimiter::for('guest-verify', function (Request $request) {
            $token = hash('sha256', (string) $request->route('token'));

            return [
                Limit::perMinute(6)->by('guest-verify-ip:'.$request->ip()),
                Limit::perMinute(4)->by('guest-verify-token:'.$token),
                Limit::perHour(20)->by('guest-verify-hour:'.$token),
            ];
        });
    }
}

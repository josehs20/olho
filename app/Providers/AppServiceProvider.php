<?php

namespace App\Providers;

use App\Models\Investigation;
use App\Models\User;
use App\Payments\FakePixProvider;
use App\Payments\PaymentProvider;
use App\Policies\InvestigationPolicy;
use App\Sources\Adapters\HoleheAdapter;
use App\Sources\Adapters\HoleheMockAdapter;
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
use App\Sources\Runners\ProcessToolRunner;
use App\Sources\Runners\ToolRunner;
use App\Sources\SourceRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentProvider::class, function () {
            return match (config('payments.provider')) {
                'fake' => new FakePixProvider,
                default => throw new \RuntimeException('Payment provider não suportado: '.config('payments.provider')),
            };
        });

        // Execução de ferramentas externas (docs/21): argv separado, timeout,
        // diretório isolado e ambiente controlado.
        $this->app->bind(ToolRunner::class, ProcessToolRunner::class);

        $this->app->singleton(SourceRegistry::class, function (Application $app) {
            return new SourceRegistry([
                new MockUsernameSource,
                new MockEmailSource,
                new MockWebSource,
                $this->maigretSource($app),
                $this->sherlockSource($app),
                $this->whatsMyNameSource($app),
                $this->holeheSource($app),
            ]);
        });
    }

    /**
     * Maigret real (`process`) ou mock determinístico (`mock`), conforme
     * `config/sources.php`. Mesmo slug (`maigret`), mesmo contrato — trocar a
     * implementação não altera o restante do domínio.
     */
    private function maigretSource(Application $app): InvestigationSource
    {
        return config('sources.maigret.driver') === 'process'
            ? $app->make(MaigretAdapter::class)
            : new MaigretMockAdapter;
    }

    /**
     * Sherlock real (`process`) ou mock determinístico (`mock`), conforme
     * `config/sources.php`. Mesmo slug (`sherlock`) e mesmo contrato.
     */
    private function sherlockSource(Application $app): InvestigationSource
    {
        return config('sources.sherlock.driver') === 'process'
            ? $app->make(SherlockAdapter::class)
            : new SherlockMockAdapter;
    }

    /**
     * WhatsMyName real (`http`, verificações conforme o schema do dataset) ou
     * mock determinístico (`mock`). Mesmo slug (`whatsmyname`) e contrato.
     */
    private function whatsMyNameSource(Application $app): InvestigationSource
    {
        return config('sources.whatsmyname.driver') === 'http'
            ? $app->make(WhatsMyNameAdapter::class)
            : new WhatsMyNameMockAdapter;
    }

    /**
     * Holehe real (`process`, GPL-3.0, sem recuperação de senha) ou mock
     * determinístico (`mock`, padrão). Mesmo slug (`holehe`) e contrato.
     */
    private function holeheSource(Application $app): InvestigationSource
    {
        return config('sources.holehe.driver') === 'process'
            ? $app->make(HoleheAdapter::class)
            : new HoleheMockAdapter;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
    }
}

<?php

namespace Tests\Feature;

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
use App\Sources\OsintMode;
use App\Sources\SourceRegistry;
use Tests\TestCase;

/**
 * Validação por ambiente do modo de execução OSINT.
 *
 * Prova que a integração real só acontece com `APP_ENV=production`: em local,
 * testing ou staging o modo efetivo é sempre `mock` (nenhuma ferramenta externa
 * é disparada), independentemente de `OSINT_MODE` e dos drivers por fonte.
 */
class OsintModeTest extends TestCase
{
    /**
     * Reconstrói o registry após mudar a configuração (é um singleton).
     */
    private function freshRegistry(): SourceRegistry
    {
        $this->app->forgetInstance(SourceRegistry::class);

        return $this->app->make(SourceRegistry::class);
    }

    /**
     * Simula o único ambiente que autoriza integrações reais.
     */
    private function production(): void
    {
        $this->app['env'] = OsintMode::PRODUCTION_ENV;
    }

    public function test_non_production_always_runs_mock_even_with_osint_mode_real(): void
    {
        // Drivers por fonte em real, mas fora de produção a validação de
        // ambiente vence: NENHUMA ferramenta externa é disparada.
        config([
            'osint.mode' => 'real',
            'sources.maigret.driver' => 'process',
            'sources.sherlock.driver' => 'process',
            'sources.whatsmyname.driver' => 'http',
            'sources.holehe.driver' => 'process',
            'sources.instagram.driver' => 'process',
        ]);

        $this->assertFalse(OsintMode::isProduction());
        $this->assertSame(OsintMode::MODE_MOCK, OsintMode::mode());
        $this->assertTrue(OsintMode::forcesMock());
        $this->assertFalse(OsintMode::forced());

        $registry = $this->freshRegistry();

        $this->assertInstanceOf(MaigretMockAdapter::class, $registry->get('maigret'));
        $this->assertInstanceOf(SherlockMockAdapter::class, $registry->get('sherlock'));
        $this->assertInstanceOf(WhatsMyNameMockAdapter::class, $registry->get('whatsmyname'));
        $this->assertInstanceOf(HoleheMockAdapter::class, $registry->get('holehe'));
        $this->assertInstanceOf(InstagramMockAdapter::class, $registry->get('instagram'));
    }

    public function test_production_defaults_to_real_when_osint_mode_is_unset(): void
    {
        $this->production();
        config(['osint.mode' => null]);

        $this->assertTrue(OsintMode::isProduction());
        $this->assertSame(OsintMode::MODE_REAL, OsintMode::mode());
        $this->assertTrue(OsintMode::forcesReal());
        $this->assertTrue(OsintMode::forced());
    }

    public function test_production_treats_auto_and_unknown_modes_as_real(): void
    {
        $this->production();

        config(['osint.mode' => 'auto']);
        $this->assertSame(OsintMode::MODE_REAL, OsintMode::mode());

        config(['osint.mode' => 'qualquer-coisa']);
        $this->assertSame(OsintMode::MODE_REAL, OsintMode::mode());
    }

    public function test_production_real_mode_forces_real_adapters_even_when_per_source_is_mock(): void
    {
        // Em produção com `OSINT_MODE=real`, drivers por fonte em `mock` não
        // impedem a integração real: o ambiente vence e NENHUM mock é usado.
        $this->production();
        config([
            'osint.mode' => 'real',
            'sources.maigret.driver' => 'mock',
            'sources.sherlock.driver' => 'mock',
            'sources.whatsmyname.driver' => 'mock',
            'sources.holehe.driver' => 'mock',
            'sources.instagram.driver' => 'mock',
        ]);

        $this->assertTrue(OsintMode::forcesReal());
        $this->assertTrue(OsintMode::forced());

        $registry = $this->freshRegistry();

        $this->assertInstanceOf(MaigretAdapter::class, $registry->get('maigret'));
        $this->assertInstanceOf(SherlockAdapter::class, $registry->get('sherlock'));
        $this->assertInstanceOf(WhatsMyNameAdapter::class, $registry->get('whatsmyname'));
        $this->assertInstanceOf(HoleheAdapter::class, $registry->get('holehe'));
        $this->assertInstanceOf(InstagramAdapter::class, $registry->get('instagram'));
    }

    public function test_production_mock_mode_is_a_kill_switch_even_when_per_source_is_real(): void
    {
        // `OSINT_MODE=mock` em produção é o único caminho de volta aos mocks,
        // sem alterar o `APP_ENV` (drivers `process`/`http` são ignorados).
        $this->production();
        config([
            'osint.mode' => 'mock',
            'sources.maigret.driver' => 'process',
            'sources.sherlock.driver' => 'process',
            'sources.whatsmyname.driver' => 'http',
            'sources.holehe.driver' => 'process',
            'sources.instagram.driver' => 'process',
        ]);

        $this->assertTrue(OsintMode::forcesMock());
        $this->assertFalse(OsintMode::forced());

        $registry = $this->freshRegistry();

        $this->assertInstanceOf(MaigretMockAdapter::class, $registry->get('maigret'));
        $this->assertInstanceOf(SherlockMockAdapter::class, $registry->get('sherlock'));
        $this->assertInstanceOf(WhatsMyNameMockAdapter::class, $registry->get('whatsmyname'));
        $this->assertInstanceOf(HoleheMockAdapter::class, $registry->get('holehe'));
        $this->assertInstanceOf(InstagramMockAdapter::class, $registry->get('instagram'));
    }

    public function test_labels_and_tones_follow_the_environment(): void
    {
        // Fora de produção (suíte) o modo efetivo é mock.
        $this->assertSame('Mock (simulado)', OsintMode::label());
        $this->assertSame('warning', OsintMode::tone());

        $this->production();
        config(['osint.mode' => 'real']);
        $this->assertSame('Integração real', OsintMode::label());
        $this->assertSame('success', OsintMode::tone());

        config(['osint.mode' => 'mock']);
        $this->assertSame('Mock (simulado)', OsintMode::label());
        $this->assertSame('warning', OsintMode::tone());
    }
}

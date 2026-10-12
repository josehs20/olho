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
 * Parâmetro de controle central `OSINT_MODE` (mock vs. integração real).
 *
 * Prova que um único interruptor decide, para todo o `SourceRegistry`, se as
 * investigações usam os adapters determinísticos (mock) ou disparam as
 * integrações reais — e que o modo `auto` preserva a decisão por fonte
 * (o comportamento histórico, no qual a suíte de testes se apoia).
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

    public function test_mode_defaults_to_auto_when_unset(): void
    {
        config(['osint.mode' => null]);

        $this->assertSame(OsintMode::MODE_AUTO, OsintMode::mode());
        $this->assertTrue(OsintMode::isAuto());
        $this->assertNull(OsintMode::forced());
    }

    public function test_unknown_mode_falls_back_to_auto(): void
    {
        config(['osint.mode' => 'qualquer-coisa']);

        $this->assertSame(OsintMode::MODE_AUTO, OsintMode::mode());
        $this->assertTrue(OsintMode::isAuto());
    }

    public function test_real_mode_forces_real_adapters_even_when_per_source_is_mock(): void
    {
        // Drivers por fonte em `mock` (como a suíte) mas o interruptor em `real`:
        // o interruptor central vence e NENHUM mock é usado.
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

    public function test_mock_mode_forces_mock_adapters_even_when_per_source_is_real(): void
    {
        // Drivers por fonte em real (`process`/`http`) mas o interruptor em
        // `mock`: os mocks determinísticos vencem (comportamento de teste).
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

    public function test_auto_mode_preserves_per_source_decision(): void
    {
        // Sem override global, cada fonte segue o seu próprio driver — o
        // comportamento anterior, no qual a suíte hermética se apoia.
        config([
            'osint.mode' => 'auto',
            'sources.maigret.driver' => 'process',
            'sources.whatsmyname.driver' => 'http',
            'sources.sherlock.driver' => 'mock',
            'sources.holehe.driver' => 'mock',
            'sources.instagram.driver' => 'mock',
        ]);

        $this->assertNull(OsintMode::forced());

        $registry = $this->freshRegistry();

        $this->assertInstanceOf(MaigretAdapter::class, $registry->get('maigret'));
        $this->assertInstanceOf(WhatsMyNameAdapter::class, $registry->get('whatsmyname'));
        $this->assertInstanceOf(SherlockMockAdapter::class, $registry->get('sherlock'));
        $this->assertInstanceOf(HoleheMockAdapter::class, $registry->get('holehe'));
        $this->assertInstanceOf(InstagramMockAdapter::class, $registry->get('instagram'));
    }

    public function test_labels_and_tones_map_each_mode(): void
    {
        config(['osint.mode' => 'real']);
        $this->assertSame('Integração real', OsintMode::label());
        $this->assertSame('success', OsintMode::tone());

        config(['osint.mode' => 'mock']);
        $this->assertSame('Mock (simulado)', OsintMode::label());
        $this->assertSame('warning', OsintMode::tone());

        config(['osint.mode' => 'auto']);
        $this->assertSame('Automático (por fonte)', OsintMode::label());
        $this->assertSame('violet', OsintMode::tone());
    }
}

<?php

namespace Tests\Feature;

use Database\Seeders\CreditPackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test da landing (prompt 42).
 *
 * A página inicial passou a exibir preço real da consulta avulsa e os pacotes de
 * créditos vindos do banco — por isso o teste prepara o schema e as seeds mínimas.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_renders_with_real_pricing_and_ctas(): void
    {
        $this->seed(CreditPackageSeeder::class);

        $this->get('/')
            ->assertOk()
            ->assertSee('VOCÊ DÁ A PISTA.')
            ->assertSee('O OLHO PROCURA.')
            ->assertSee('INVESTIGAR AGORA')
            ->assertSee('WhatsApp')
            ->assertSee('fora do escopo');
    }

    /**
     * Redesign da landing (prompt 50): foco em redes sociais, demonstração
     * visual fictícia do relatório enriquecido e blocos de benefícios.
     *
     * A demonstração é 100% fictícia (avatares SVG gerados localmente) — nunca
     * pessoas reais nem requisições de rede. O selo "demonstração" e o aviso de
     * correspondência garantem que nada fictício se passe por descoberta real.
     */
    public function test_landing_showcases_social_osint_demo_and_benefits(): void
    {
        $this->seed(CreditPackageSeeder::class);

        $response = $this->get('/');
        $response->assertOk();

        $html = $response->getContent();

        // Hero com foco em pegada digital / redes sociais.
        $this->assertStringContainsString('pegada digital', $html);
        $this->assertStringContainsString('redes sociais', $html);

        // Demonstração visual fictícia do relatório enriquecido.
        $this->assertStringContainsString('Exemplo de relatório', $html);
        $this->assertStringContainsString('Enriquecimento de perfil em tempo real', $html);
        $this->assertStringContainsString('Coleta somente de fontes públicas', $html);
        $this->assertStringContainsString('Relatório unificado', $html);
        $this->assertStringContainsString('ana.vermelho', $html);
        $this->assertStringContainsString('Sinal de correlação', $html);

        // Benefícios de conversão.
        $this->assertStringContainsString('Mapeamento multiplataforma', $html);
        $this->assertStringContainsString('Enriquecimento visual', $html);
        $this->assertStringContainsString('Relatórios prontos para auditoria', $html);

        // Formulário rápido conectado na rota existente da avulsa (sem backend novo).
        $this->assertStringContainsString(route('guest.investigations.store'), $html);

        // Nenhuma imagem externa de pessoa real: só SVG inline.
        $this->assertStringNotContainsString('<img', $html);
    }

    /**
     * As tags SEO/OG precisam sair como HTML no <head>, não como texto visível.
     * Regressão: passar HTML por atributo de componente (sanitizeComponentAttribute)
     * escapa a string e o navegador exibe as tags na tela.
     */
    public function test_landing_renders_seo_meta_tags_as_html_instead_of_text(): void
    {
        $this->seed(CreditPackageSeeder::class);

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('<meta name="description" content="', false)
            ->assertSee('<meta property="og:title" content="', false)
            ->assertSee('<meta property="og:description" content="', false)
            ->assertSee('<meta property="og:type" content="website">', false)
            ->assertSee('<meta name="twitter:card" content="summary">', false)
            ->assertSee('<meta name="robots" content="index, follow">', false)
            ->assertDontSee('&lt;meta', false);
    }
}

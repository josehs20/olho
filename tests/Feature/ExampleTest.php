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
            ->assertSee('INVESTIGUE UMA')
            ->assertSee('PRESENÇA DIGITAL')
            ->assertSee('INICIAR INVESTIGAÇÃO')
            ->assertSee('WhatsApp')
            ->assertSee('fora do escopo');
    }
}

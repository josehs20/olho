<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Contrato de empilhamento do modal (Breeze + Tailwind v4).
 *
 * O painel e o backdrop são irmãos, e o backdrop é posicionado (`fixed`): se o
 * painel não tiver posicionamento/z-index próprios, ele é pintado antes dos
 * elementos posicionados e o backdrop passa a cobrir o modal — o modal abre e
 * "desaparece" atrás do véu escuro. No Tailwind v4 a classe `transform` não gera
 * transform nenhum (suas variáveis ficam vazias, o valor é inválido em tempo de
 * computação), então o stacking context que o Breeze contava no v3 não existe.
 *
 * @see resources/views/components/modal.blade.php
 */
class ModalComponentTest extends TestCase
{
    public function test_modal_panel_stays_above_the_backdrop(): void
    {
        $html = Blade::render('<x-modal name="sample">conteúdo do modal</x-modal>');

        $backdrop = strpos($html, 'bg-ink-950/80');
        $panel = strpos($html, 'relative z-10');

        $this->assertNotFalse($backdrop, 'O modal deve manter o véu (backdrop) escuro.');
        $this->assertNotFalse(
            $panel,
            'O painel precisa de posicionamento próprio (`relative z-10`) para ser pintado acima do backdrop.',
        );
        $this->assertGreaterThan($backdrop, $panel, 'No DOM, o painel vem depois do backdrop.');
    }

    public function test_modal_stays_closed_until_it_is_opened(): void
    {
        $html = Blade::render('<x-modal name="sample">conteúdo do modal</x-modal>');

        // O estado inicial é fechado; quem abre é o Alpine (`x-show`/`open-modal`).
        $this->assertStringContainsString('display: none', $html);
        $this->assertStringNotContainsString('display: block', $html);
    }
}

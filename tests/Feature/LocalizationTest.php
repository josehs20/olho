<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_welcome_page_is_in_portuguese(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('VOCÊ DÁ A PISTA.')
            ->assertSee('O OLHO PROCURA.');
    }

    public function test_login_screen_is_in_portuguese(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Entrar')
            ->assertSee('Senha')
            ->assertSee('Esqueceu sua senha?');
    }

    public function test_registration_validation_messages_are_in_portuguese(): void
    {
        $this->post('/register', [])
            ->assertSessionHasErrors(['name', 'email', 'password']);

        $errors = session('errors')->getBag('default');

        $this->assertStringContainsString('O campo nome é obrigatório.', (string) $errors->first('name'));
        $this->assertStringContainsString('O campo e-mail é obrigatório.', (string) $errors->first('email'));
        $this->assertStringContainsString('O campo senha é obrigatório.', (string) $errors->first('password'));
    }

    public function test_invalid_login_message_is_in_portuguese(): void
    {
        $this->post('/login', [
            'email' => 'ninguem@example.com',
            'password' => 'senha-errada',
        ])->assertSessionHasErrors([
            'email' => 'Essas credenciais não foram encontradas em nossos registros.',
        ]);
    }

    public function test_status_labels_are_translated(): void
    {
        $this->assertSame('na fila', __('queued'));
        $this->assertSame('em execução', __('running'));
        $this->assertSame('concluída', __('completed'));
        $this->assertSame('aguardando pagamento', __('awaiting_payment'));
    }
}

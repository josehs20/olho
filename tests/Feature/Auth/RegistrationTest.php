<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Maria de Souza',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_requires_password_confirmation(): void
    {
        $response = $this->post('/register', [
            'name' => 'Maria de Souza',
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(['password']);
        $this->assertGuest();
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $response = $this->post('/register', [
            'name' => 'Maria de Souza',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors(['email']);
        $this->assertGuest();
    }

    /**
     * O cadastro não pede CPF/CNPJ: quem identifica o pagador é a **página
     * hospedada do provedor**, no momento da compra.
     */
    public function test_registration_does_not_ask_for_a_document(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertDontSee('CPF')
            ->assertDontSee('tax_id');
    }

    /**
     * Nenhum documento é coletado nem gravado: o dado do comprador existe só na
     * página hospedada do PagBank (minimização de dados).
     */
    public function test_registration_ignores_any_document_field(): void
    {
        $this->post('/register', [
            'name' => 'Maria de Souza',
            'tax_id' => '529.982.247-25',
            'email' => 'maria@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticated();

        $user = User::query()->latest('id')->firstOrFail();

        // A coluna não existe mais e qualquer `tax_id` enviado é simplesmente
        // ignorado (não é fillable, não é persistido).
        $this->assertFalse(Schema::hasColumn('users', 'tax_id'));
        $this->assertArrayNotHasKey('tax_id', $user->getAttributes());
        $this->assertStringNotContainsString('52998224725', (string) json_encode($user->getAttributes()));
    }
}

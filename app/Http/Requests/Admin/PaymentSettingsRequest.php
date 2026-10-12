<?php

namespace App\Http\Requests\Admin;

use App\Payments\PaymentSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validação da tela Admin → Configurações → Pagamentos (prompt 45).
 */
class PaymentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::in([PaymentSettings::PROVIDER_FAKE, PaymentSettings::PROVIDER_PAGBANK])],
            'enabled' => ['boolean'],
            'environment' => ['required', Rule::in([PaymentSettings::ENV_SANDBOX, PaymentSettings::ENV_PRODUCTION])],
            // Segredo opcional: se vazio, o token atual é preservado. O valor chega
            // já normalizado (sem aspas/quebras de linha/`Bearer`) e só aceita o
            // alfabeto típico de token — colar o header inteiro é rejeitado aqui.
            'token' => ['nullable', 'string', 'min:8', 'max:1024', 'regex:/^[A-Za-z0-9._~+\/=\-]+$/'],
            'clear_token' => ['boolean'],
            'method_pix' => ['boolean'],
            'method_debit' => ['boolean'],
            'method_credit' => ['boolean'],
            'pix_expiration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'credit_max_installments' => ['required', 'integer', Rule::in((array) config('payments.pagbank.installments.allowed', [1]))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.regex' => 'Informe apenas o token do PagBank (sem espaços, aspas, "Bearer" ou o nome do header).',
            'token.min' => 'Token muito curto: confira se copiou o valor completo.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $token = $this->input('token');

        $this->merge([
            'enabled' => $this->boolean('enabled'),
            'clear_token' => $this->boolean('clear_token'),
            'method_pix' => $this->boolean('method_pix'),
            'method_debit' => $this->boolean('method_debit'),
            'method_credit' => $this->boolean('method_credit'),
            // Normaliza antes de validar/gravar: colar da "Minha Conta" costuma
            // trazer aspas, quebras de linha e o prefixo "Bearer", que produzem um
            // header Authorization inválido no PagBank.
            'token' => is_string($token) ? PaymentSettings::normalizeToken($token) : $token,
        ]);
    }

    /**
     * Regras que dependem de mais de um campo.
     *
     * O PagBank recusa um checkout sem `payment_methods`: com o provedor
     * selecionado é obrigatório manter ao menos uma forma de pagamento
     * habilitada (o `fake` ignora as formas, então não é afetado).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('provider') !== PaymentSettings::PROVIDER_PAGBANK) {
                    return;
                }

                if (! $this->boolean('method_pix') && ! $this->boolean('method_debit') && ! $this->boolean('method_credit')) {
                    $validator->errors()->add(
                        'methods',
                        'Habilite ao menos uma forma de pagamento: o PagBank não aceita checkout sem método de pagamento.',
                    );
                }
            },
        ];
    }
}

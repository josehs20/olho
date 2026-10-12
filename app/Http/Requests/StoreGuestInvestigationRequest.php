<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação da entrada da investigação avulsa (prompt 41).
 *
 * O visitante informa apenas os dados públicos necessários; a escolha de
 * módulos não existe aqui (a plataforma usa todos os aplicáveis e o preço é
 * fixo), por isso não há campo `modules`.
 *
 * Nenhum dado do pagador é pedido: a cobrança é um checkout hospedado e quem
 * coleta nome, CPF/CNPJ e e-mail é a página do provedor.
 */
class StoreGuestInvestigationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'username' => ['required_without:email,phone', 'nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:2'],
        ];
    }
}

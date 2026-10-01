<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Custo em créditos por requisição de uma fonte (prompt 43).
 *
 * Autorização dupla: a rota já roda sob middleware `admin` e o `authorize()`
 * revalida o ator (defesa em profundidade). O valor é inteiro, >= 0 (zero é
 * permitido: o módulo executa sem consumir créditos) e tem teto razoável para
 * impedir entradas absurdas que distorceriam a política de cobrança.
 */
class AdminSourceCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'cost_credits' => ['required', 'integer', 'min:0', 'max:1000'],
        ];
    }
}

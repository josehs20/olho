<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Geração do dump do banco pelo painel admin.
 *
 * A rota já roda sob o middleware `admin`; o `authorize()` revalida o ator
 * (defesa em profundidade). O único input é a flag opcional `download`, que
 * decide se a resposta já entrega o arquivo ou apenas volta para a tela.
 */
class AdminDatabaseDumpRequest extends FormRequest
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
            'download' => ['nullable', 'boolean'],
        ];
    }
}

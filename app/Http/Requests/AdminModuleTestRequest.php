<?php

namespace App\Http\Requests;

use App\Models\SourceDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Disparo de um teste individual de módulo (prompt 47 §3/§12).
 *
 * Autorização dupla: a rota já roda sob o middleware `admin` e o `authorize()`
 * revalida o ator (defesa em profundidade). O módulo é validado contra a
 * **allow-list** de `config/module_tests.php` — nada do formulário vira comando:
 * o adapter monta o argv a partir da própria configuração da fonte.
 */
class AdminModuleTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'module' => ['required', 'string', Rule::in((array) config('module_tests.modules', []))],
            'target' => array_merge(['required', 'string', 'min:2', 'max:255'], $this->targetRules()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'module.in' => __('Módulo não disponível para teste individual.'),
            'target.regex' => __('Informe apenas letras, números, ponto, hífen ou sublinhado para o username.'),
            'target.email' => __('Este módulo consulta e-mail: informe um endereço válido.'),
        ];
    }

    /**
     * O alvo é validado conforme o tipo declarado pela fonte (username ou e-mail),
     * exatamente como a entrada da investigação normal.
     *
     * @return array<int, string>
     */
    private function targetRules(): array
    {
        $definition = SourceDefinition::query()->where('slug', $this->input('module'))->first();

        if ($definition === null) {
            return [];
        }

        return $definition->type === 'email'
            ? ['email']
            : ['regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/'];
    }
}

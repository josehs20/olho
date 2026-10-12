<?php

namespace App\Http\Requests\Admin;

use App\Sources\Integrations\InstagramSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação da tela Admin → Configurações → Integrações (prompt 48 §2.1).
 *
 * O `sessionid` do Instagram é um segredo de longa duração: a mesma disciplina
 * do token do PagBank — valor opcional (vazio preserva o atual), normalizado
 * antes de validar e nunca exibido de volta em claro.
 */
class IntegrationSettingsRequest extends FormRequest
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
            'instagram_driver' => ['required', Rule::in(['process', 'mock'])],
            'session_id' => ['nullable', 'string', 'min:8', 'max:512', 'regex:/^[A-Za-z0-9._%:\-]+$/'],
            'ds_user_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._%:\-]+$/'],
            'clear_session' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'session_id.regex' => 'Informe apenas o valor do cookie sessionid (sem espaços, aspas ou o nome do cookie).',
            'session_id.min' => 'sessionid muito curto: confira se copiou o valor completo.',
            'ds_user_id.regex' => 'ID de usuário inválido (apenas números).',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'clear_session' => $this->boolean('clear_session'),
            // Colar do DevTools costuma trazer `sessionid=...;` e a linha inteira
            // do Cookie: normaliza antes de validar/gravar, como o token do PagBank.
            'session_id' => InstagramSettings::normalizeCookie($this->input('session_id')),
            'ds_user_id' => InstagramSettings::normalizeCookie($this->input('ds_user_id')),
        ]);
    }
}

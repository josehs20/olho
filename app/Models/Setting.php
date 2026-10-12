<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuração chave/valor persistida de forma cifrada.
 *
 * O cast `encrypted` cifra no momento da escrita e decifra na leitura usando a
 * APP_KEY — nenhum segredo (token de gateway, chave etc.) fica em texto puro no
 * banco nem aparece em dumps de tabela.
 */
class Setting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }
}

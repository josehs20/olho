<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Agregado diário do contador de visitantes do site (painel admin).
 *
 * Nenhum dado pessoal é armazenado: apenas `page_views` (páginas HTML
 * carregadas) e `unique_visitors` (sessões únicas) por dia.
 */
class Visit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'page_views' => 'integer',
            'unique_visitors' => 'integer',
        ];
    }
}

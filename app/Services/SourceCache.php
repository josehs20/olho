<?php

namespace App\Services;

use App\Sources\InvestigationInput;
use App\Sources\InvestigationSource;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache de resultado de fonte, escopado por source + versão + hash de entrada.
 * Não inclui usuário: a consulta é sobre informação pública, e a chave de
 * entrada garante que nunca se retorne o resultado de uma entrada diferente.
 */
class SourceCache
{
    public function remember(InvestigationSource $source, InvestigationInput $input, Closure $callback): array
    {
        if (! config('source_cache.enabled')) {
            return $callback();
        }

        $ttl = (int) config('source_cache.ttl', 3600);

        return Cache::remember($this->key($source, $input), $ttl, $callback);
    }

    public function key(InvestigationSource $source, InvestigationInput $input): string
    {
        return 'source:'.$source->key().':'.$source->version().':'.$this->inputHash($input);
    }

    public function inputHash(InvestigationInput $input): string
    {
        return hash('sha256', json_encode([
            'name' => $input->name,
            'username' => $input->username,
            'email' => $input->email,
            'phone' => $input->phone,
            'city' => $input->city,
            'state' => $input->state,
        ]));
    }
}

<?php

namespace Database\Seeders;

use App\Models\SourceDefinition;
use Illuminate\Database\Seeder;

/**
 * Dados de referência das fontes de pesquisa: uma linha por adapter registrado
 * no `SourceRegistry`. Sem estas linhas, `/investigations/create` não oferece
 * nenhum módulo e o pipeline não executa nada.
 *
 * Determinístico e idempotente. Preserva a coluna `active`, que é operada pelo
 * admin em `/admin/sources` (reexecutar a seed não reativa fonte desligada).
 */
class SourceDefinitionSeeder extends Seeder
{
    /**
     * @var array<int, array{slug: string, name: string, type: string, cost_credits: int, timeout: int}>
     */
    private const SOURCES = [
        ['slug' => 'mock-username', 'name' => 'Mock Username', 'type' => 'username', 'cost_credits' => 1, 'timeout' => 30],
        ['slug' => 'mock-email', 'name' => 'Mock Email', 'type' => 'email', 'cost_credits' => 1, 'timeout' => 30],
        ['slug' => 'mock-web', 'name' => 'Mock Web', 'type' => 'web', 'cost_credits' => 1, 'timeout' => 30],
        ['slug' => 'maigret', 'name' => 'Maigret', 'type' => 'username', 'cost_credits' => 2, 'timeout' => 60],
        ['slug' => 'sherlock', 'name' => 'Sherlock', 'type' => 'username', 'cost_credits' => 2, 'timeout' => 60],
        ['slug' => 'whatsmyname', 'name' => 'WhatsMyName', 'type' => 'username', 'cost_credits' => 2, 'timeout' => 60],
        ['slug' => 'holehe', 'name' => 'Holehe', 'type' => 'email', 'cost_credits' => 2, 'timeout' => 60],
    ];

    public function run(): void
    {
        foreach (self::SOURCES as $source) {
            $definition = SourceDefinition::query()->firstOrNew(['slug' => $source['slug']]);

            $definition->fill([
                'name' => $source['name'],
                'type' => $source['type'],
                'cost_credits' => $source['cost_credits'],
                'timeout' => $source['timeout'],
                // Reflete a política real de retry do ExecuteSourceJob.
                'retry_policy' => ['tries' => 2, 'backoff' => [10, 60]],
            ]);

            if (! $definition->exists) {
                $definition->active = true;
            }

            $definition->save();
        }
    }
}

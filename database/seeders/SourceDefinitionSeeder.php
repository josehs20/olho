<?php

namespace Database\Seeders;

use App\Models\SourceDefinition;
use Illuminate\Database\Seeder;

/**
 * Dados de referência das fontes de pesquisa: uma linha por adapter registrado
 * no `SourceRegistry`. Sem estas linhas, `/investigations/create` não oferece
 * nenhum módulo e o pipeline não executa nada.
 *
 * Determinístico e idempotente. Preserva as colunas operadas pelo admin em
 * `/admin/sources` (reexecutar a seed não reativa fonte desligada **nem**
 * sobrescreve o `cost_credits` configurado — prompt 43).
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
        // Etapa 2 da pipeline (prompt 48): enriquecimento de perfil do Instagram
        // a partir do username confirmado na etapa 1. Precisa da sessão de
        // serviço configurada em /admin/settings/integrations.
        ['slug' => 'instagram', 'name' => 'Instagram (instagrapi)', 'type' => 'username', 'cost_credits' => 2, 'timeout' => 60],
    ];

    public function run(): void
    {
        foreach (self::SOURCES as $source) {
            $definition = SourceDefinition::query()->firstOrNew(['slug' => $source['slug']]);

            $definition->fill([
                'name' => $source['name'],
                'type' => $source['type'],
                'timeout' => $source['timeout'],
                // Reflete a política real de retry do ExecuteSourceJob.
                'retry_policy' => ['tries' => 2, 'backoff' => [10, 60]],
            ]);

            if (! $definition->exists) {
                $definition->active = true;
                // Custo inicial apenas na criação: depois disso o valor pertence
                // ao administrador (prompt 43) e a seed não pode revertê-lo.
                $definition->cost_credits = $source['cost_credits'];
            }

            $definition->save();
        }
    }
}

<?php

namespace App\Services;

use App\Models\SourceDefinition;
use App\Models\SourceRun;
use App\Sources\SourceOutcome;

/**
 * Persistência do desfecho de uma execução de fonte: resultados normalizados,
 * evidências e métricas do adapter.
 *
 * Extraído do `ExecuteSourceJob` (prompt 47 §16: "não duplicar lógica de
 * normalização/pipeline") para que o painel de testes individuais grave pelo
 * **mesmo** caminho da investigação normal — a normalização continua sendo a
 * única implementação (`SourceResultNormalizer`).
 */
class SourceOutcomePersister
{
    public function __construct(private readonly SourceResultNormalizer $normalizer) {}

    /**
     * Grava findings e evidências da execução.
     *
     * @return array{results: int, evidence: int}
     */
    public function persist(SourceRun $sourceRun, SourceDefinition $definition, SourceOutcome $outcome): array
    {
        $evidence = 0;

        foreach ($outcome->findings as $result) {
            $model = $sourceRun->sourceResults()->create(array_merge(
                ['source_definition_id' => $definition->id],
                $this->normalizer->attributes($result),
            ));

            foreach ($this->normalizer->evidenceAttributes($result) as $attributes) {
                $model->evidence()->create($attributes);
                $evidence++;
            }
        }

        return ['results' => count($outcome->findings), 'evidence' => $evidence];
    }

    /**
     * Registra contagem de resultados, versão da ferramenta e avisos/metadados
     * públicos do adapter no próprio `SourceRun` (prompt 31). O `save()` fica a
     * cargo de quem chama, junto com o status final.
     */
    public function record(SourceRun $sourceRun, SourceOutcome $outcome): void
    {
        $sourceRun->result_count = count($outcome->findings);
        $sourceRun->tool_version = $this->toolVersion($outcome);

        $metrics = $sourceRun->metrics ?? [];

        if ($outcome->warnings !== []) {
            $metrics['warnings'] = array_slice($outcome->warnings, 0, 20);
        }

        if ($outcome->metadata !== []) {
            $metrics['adapter'] = $outcome->metadata;
        }

        $sourceRun->metrics = $metrics;
    }

    private function toolVersion(SourceOutcome $outcome): ?string
    {
        $version = $outcome->metadata['tool_version'] ?? null;

        return is_scalar($version) ? (string) $version : null;
    }
}

<?php

namespace App\Services;

use App\Models\InvestigationRun;
use App\Models\SourceResult;
use App\Models\SourceRun;
use Illuminate\Support\Collection;

/**
 * Constrói o **manifesto** do relatório (prompt 38).
 *
 * O relatório HTML é uma leitura dos dados persistidos do pipeline. Para que um
 * relatório histórico não mude silenciosamente (docs/12), o manifesto é gravado
 * em `reports.metadata` no momento da geração e passa a ser a fonte preferida de
 * exibição: nomes de fontes, status, contagens, descobertas consolidadas,
 * correlação e limitações ficam congelados para aquele run.
 */
class ReportManifestBuilder
{
    /** Versão do formato do manifesto (mudança de formato exige bump). */
    public const VERSION = 'report-manifest-v1';

    /**
     * Manifesto persistido do run (se existir na versão corrente) ou recém
     * calculado a partir do estado atual do pipeline.
     *
     * @return array<string, mixed>
     */
    public function for(InvestigationRun $run): array
    {
        $persisted = $run->report?->metadata;

        if (is_array($persisted) && ($persisted['version'] ?? null) === self::VERSION) {
            return $persisted;
        }

        return $this->build($run);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(InvestigationRun $run): array
    {
        $run->loadMissing(['investigation', 'sourceRuns.sourceDefinition', 'sourceRuns.sourceResults.evidence']);

        $sourceRuns = $run->sourceRuns->sortBy('id')->values();
        $results = $sourceRuns->flatMap->sourceResults;
        $representatives = $results->filter(fn (SourceResult $result) => empty($result->metadata['duplicate_of']));
        $correlation = $run->metadata['correlation'] ?? null;

        return [
            'version' => self::VERSION,
            'generated_at' => now()->toISOString(),
            'investigation' => [
                'id' => $run->investigation_id,
                'title' => $run->investigation?->title,
            ],
            'run' => [
                'id' => $run->id,
                'run_number' => $run->run_number,
                'status' => $run->status,
                'reason' => $run->metadata['finalization']['reason'] ?? null,
                'started_at' => $run->started_at?->toISOString(),
                'finished_at' => $run->finished_at?->toISOString(),
                'credits_reserved' => (int) $run->credits_reserved,
                'credits_consumed' => (int) $run->credits_consumed,
                'pipeline' => $run->metadata['pipeline'] ?? [],
            ],
            'identifiers' => $this->identifiers($run),
            'modules_requested' => $run->moduleSlugs(),
            'sources' => $sourceRuns->map(fn (SourceRun $sourceRun) => $this->source($sourceRun))->all(),
            'summary' => $this->summary($sourceRuns, $results, $representatives),
            'discoveries' => $representatives->map(fn (SourceResult $result) => $this->discovery($result))->values()->all(),
            'correlation' => $correlation,
            'limitations' => $this->limitations($sourceRuns, $correlation),
            'sources_failed' => $sourceRuns
                ->filter(fn (SourceRun $sourceRun) => in_array($sourceRun->status, ['failed', 'timeout', 'skipped'], true))
                ->map(fn (SourceRun $sourceRun) => [
                    'slug' => $sourceRun->source,
                    'name' => $sourceRun->sourceDefinition?->name ?? $sourceRun->source,
                    'status' => $sourceRun->status,
                    'status_label' => $sourceRun->displayStatus(),
                    'error_code' => $sourceRun->error_code,
                    'error_message_public' => $sourceRun->error_message_public,
                ])->values()->all(),
        ];
    }

    /**
     * Hash do manifesto (integridade do que foi congelado).
     *
     * @param  array<string, mixed>  $manifest
     */
    public function contentHash(array $manifest): string
    {
        return hash('sha256', (string) json_encode($manifest));
    }

    /**
     * @return array<string, string>
     */
    private function identifiers(InvestigationRun $run): array
    {
        $snapshot = $run->input_snapshot ?? [];
        $identifiers = [];

        foreach (['name', 'username', 'email', 'phone', 'city', 'state'] as $field) {
            $value = $snapshot[$field] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                $identifiers[$field] = (string) $value;
            }
        }

        return $identifiers;
    }

    /**
     * @return array<string, mixed>
     */
    private function source(SourceRun $sourceRun): array
    {
        return [
            'slug' => $sourceRun->source,
            'name' => $sourceRun->sourceDefinition?->name ?? $sourceRun->source,
            'type' => $sourceRun->sourceDefinition?->type,
            'status' => $sourceRun->status,
            'status_label' => $sourceRun->displayStatus(),
            'attempts' => (int) $sourceRun->attempts,
            'result_count' => (int) $sourceRun->result_count,
            'tool_version' => $sourceRun->tool_version,
            'duration_ms' => $sourceRun->duration_ms,
            'started_at' => $sourceRun->started_at?->toISOString(),
            'finished_at' => $sourceRun->finished_at?->toISOString(),
            'error_code' => $sourceRun->error_code,
            'error_message_public' => $sourceRun->error_message_public,
            'warnings' => $sourceRun->metrics['warnings'] ?? [],
        ];
    }

    /**
     * @param  Collection<int, SourceRun>  $sourceRuns
     * @param  Collection<int, SourceResult>  $results
     * @param  Collection<int, SourceResult>  $representatives
     * @return array<string, int>
     */
    private function summary(Collection $sourceRuns, Collection $results, Collection $representatives): array
    {
        $byStatus = $sourceRuns->countBy('status');

        return [
            'sources_total' => $sourceRuns->count(),
            'sources_completed' => (int) ($byStatus['completed'] ?? 0),
            'sources_failed' => (int) ($byStatus['failed'] ?? 0),
            'sources_timeout' => (int) ($byStatus['timeout'] ?? 0),
            'sources_skipped' => (int) ($byStatus['skipped'] ?? 0),
            'sources_pending' => (int) (($byStatus['pending'] ?? 0) + ($byStatus['running'] ?? 0)),
            'results_total' => $results->count(),
            'discoveries_total' => $representatives->count(),
            'discoveries_consolidated' => $representatives->filter(fn (SourceResult $result) => (int) ($result->metadata['dedup_group_size'] ?? 1) > 1)->count(),
            'duplicates_total' => $results->filter(fn (SourceResult $result) => ! empty($result->metadata['duplicate_of']))->count(),
        ];
    }

    /**
     * Descoberta consolidada: representante + todas as observações das fontes.
     *
     * @return array<string, mixed>
     */
    private function discovery(SourceResult $result): array
    {
        return [
            'key' => $result->dedup_hash,
            'entity_type' => $result->entity_type,
            'value' => $result->value,
            'title' => $result->title,
            'url' => $result->url,
            'platform' => $result->metadata['platform'] ?? null,
            'confidence' => $result->confidence !== null ? (float) $result->confidence : null,
            'observed_at' => $result->observed_at?->toISOString(),
            'sources' => $result->metadata['dedup_sources'] ?? [$result->sourceRun?->source],
            'evidence' => $result->evidence->map(fn ($evidence) => [
                'type' => $evidence->type,
                'value' => $evidence->value,
            ])->all(),
            'observations' => $result->metadata['dedup_observations'] ?? [],
        ];
    }

    /**
     * Limitações observáveis: correlação + falhas de fonte + avisos públicos.
     *
     * @param  Collection<int, SourceRun>  $sourceRuns
     * @param  array<string, mixed>|null  $correlation
     * @return array<int, string>
     */
    private function limitations(Collection $sourceRuns, ?array $correlation): array
    {
        $limitations = $correlation['limitations'] ?? [];

        foreach ($sourceRuns as $sourceRun) {
            if (in_array($sourceRun->status, ['failed', 'timeout', 'skipped'], true)) {
                $name = $sourceRun->sourceDefinition?->name ?? $sourceRun->source;
                $limitations[] = "A fonte '{$name}' não foi concluída ({$sourceRun->displayStatus()}): "
                    .($sourceRun->error_message_public ?? $sourceRun->error_code ?? 'sem detalhe público').'.';
            }

            foreach ($sourceRun->metrics['warnings'] ?? [] as $warning) {
                $limitations[] = (string) $warning;
            }
        }

        return array_values(array_unique($limitations));
    }
}

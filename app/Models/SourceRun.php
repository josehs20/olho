<?php

namespace App\Models;

use App\Sources\Runners\ToolWarningsTranslator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceRun extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * Mantém a denormalização consistente: toda execução registra a investigação
     * e o slug da fonte (snapshot), mesmo quando criada por factory, de modo que
     * o histórico permaneça legível sem depender de joins.
     */
    protected static function booted(): void
    {
        static::creating(function (SourceRun $sourceRun): void {
            if ($sourceRun->investigation_id === null && $sourceRun->investigation_run_id !== null) {
                $sourceRun->investigation_id = InvestigationRun::query()
                    ->whereKey($sourceRun->investigation_run_id)
                    ->value('investigation_id');
            }

            if ($sourceRun->source === null && $sourceRun->source_definition_id !== null) {
                $sourceRun->source = SourceDefinition::query()
                    ->whereKey($sourceRun->source_definition_id)
                    ->value('slug');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'result_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'metrics' => 'array',
        ];
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function investigationRun(): BelongsTo
    {
        return $this->belongsTo(InvestigationRun::class);
    }

    public function sourceDefinition(): BelongsTo
    {
        return $this->belongsTo(SourceDefinition::class);
    }

    public function sourceResults(): HasMany
    {
        return $this->hasMany(SourceResult::class);
    }

    /**
     * Avisos públicos da execução prontos para exibição (prompt 31).
     *
     * O texto gravado em `metrics.warnings` já é traduzido no momento da
     * execução; registros anteriores a isso são convertidos aqui, para que painel
     * e relatório nunca exibam o aviso bruto da ferramenta em inglês.
     *
     * @return array<int, string>
     */
    public function publicWarnings(): array
    {
        $warnings = $this->metrics['warnings'] ?? [];

        if (! is_array($warnings)) {
            return [];
        }

        $translated = array_map(
            fn (string $warning) => ToolWarningsTranslator::translate($warning),
            array_values(array_filter($warnings, 'is_string')),
        );

        return array_values(array_unique(array_filter($translated)));
    }

    /**
     * Status de exibição da fonte (docs/10-resultados-evidencias.md).
     */
    public function displayStatus(): string
    {
        return match ($this->status) {
            'failed', 'timeout' => 'fonte indisponível',
            'pending', 'running', 'skipped' => 'não pesquisado',
            'completed' => $this->completedDisplayStatus(),
            default => 'não pesquisado',
        };
    }

    private function completedDisplayStatus(): string
    {
        if ($this->sourceResults->isEmpty()) {
            return 'sem resultado';
        }

        $strong = $this->sourceResults->contains(
            fn ($result) => (float) ($result->confidence ?? 0) >= 0.7,
        );

        return $strong ? 'encontrado' : 'possível correspondência';
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceRun extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'metrics' => 'array',
        ];
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

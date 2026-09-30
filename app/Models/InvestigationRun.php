<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InvestigationRun extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'credits_reserved' => 'integer',
            'credits_consumed' => 'integer',
            'input_snapshot' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * Indica se uma etapa do pipeline já foi concluída (idempotência).
     */
    public function pipelineStage(string $stage): bool
    {
        return (bool) data_get($this->metadata, "pipeline.{$stage}");
    }

    /**
     * Marca uma etapa do pipeline como concluída.
     */
    public function markPipelineStage(string $stage): void
    {
        $metadata = $this->metadata ?? [];
        data_set($metadata, "pipeline.{$stage}", now()->toISOString());
        $this->metadata = $metadata;
        $this->save();
    }

    /**
     * Slugs dos módulos selecionados no run (contrato do `input_snapshot`).
     *
     * @return array<int, string>
     */
    public function moduleSlugs(): array
    {
        $modules = $this->input_snapshot['modules'] ?? [];

        return is_array($modules)
            ? array_values(array_filter($modules, 'is_string'))
            : [];
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function sourceRuns(): HasMany
    {
        return $this->hasMany(SourceRun::class);
    }

    public function creditReservations(): HasMany
    {
        return $this->hasMany(CreditReservation::class);
    }

    public function report(): HasOne
    {
        return $this->hasOne(Report::class);
    }
}

<?php

namespace App\Models;

use App\Sources\SourceResult as SourceResultDto;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceResult extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'raw_payload' => 'array',
            'metadata' => 'array',
            'observed_at' => 'datetime',
        ];
    }

    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(SourceRun::class);
    }

    public function sourceDefinition(): BelongsTo
    {
        return $this->belongsTo(SourceDefinition::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class);
    }

    /**
     * Converte a linha persistida de volta para o contrato do adapter, para que
     * a normalização/deduplicação reutilize exatamente as mesmas regras sobre os
     * dados gravados (prompt 36).
     */
    public function toSourceResult(): SourceResultDto
    {
        $evidence = $this->raw_payload['evidence'] ?? null;

        if (! is_array($evidence)) {
            $evidence = $this->relationLoaded('evidence')
                ? $this->evidence->map(fn (Evidence $item) => ['type' => $item->type, 'value' => $item->value])->all()
                : [];
        }

        return new SourceResultDto(
            source: (string) ($this->sourceRun?->source ?? ''),
            entityType: (string) $this->entity_type,
            value: (string) $this->value,
            url: $this->url,
            title: $this->title,
            description: $this->description,
            confidence: $this->confidence !== null ? (float) $this->confidence : null,
            evidence: array_values(array_filter($evidence, 'is_array')),
            metadata: $this->metadata ?? [],
        );
    }
}

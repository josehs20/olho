<?php

namespace App\Models;

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
}

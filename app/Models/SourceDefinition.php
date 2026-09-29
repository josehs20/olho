<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceDefinition extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'cost_credits' => 'integer',
            'cost_operational_cents' => 'integer',
            'timeout' => 'integer',
            'retry_policy' => 'array',
            'config' => 'array',
        ];
    }

    public function sourceRuns(): HasMany
    {
        return $this->hasMany(SourceRun::class);
    }

    public function sourceResults(): HasMany
    {
        return $this->hasMany(SourceResult::class);
    }
}

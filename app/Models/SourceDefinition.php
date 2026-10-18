<?php

namespace App\Models;

use App\Sources\OsintMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceDefinition extends Model
{
    use HasFactory;

    /**
     * Módulos simulados: não possuem integração real e só existem para o
     * ambiente de desenvolvimento/teste. Com integração real (produção) eles
     * ficam fora da seleção, da execução e das reexecuções.
     *
     * @var array<int, string>
     */
    public const MOCK_SLUGS = ['mock-username', 'mock-email', 'mock-web'];

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

    /**
     * Módulos executáveis no modo efetivo atual.
     *
     * Com integração real (produção) os módulos simulados são excluídos: eles
     * não têm adapter real e produziriam resultados fictícios (`example.com`).
     * No modo mock (desenvolvimento/teste) todos continuam disponíveis.
     */
    public function scopeExecutable(Builder $query): void
    {
        if (OsintMode::forcesReal()) {
            $query->whereNotIn('slug', self::MOCK_SLUGS);
        }
    }
}

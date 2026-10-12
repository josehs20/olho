<?php

namespace App\Models;

use App\ModuleTests\ModuleTestStatus;
use App\Sources\Runners\ToolWarningsTranslator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Teste individual de um módulo OSINT disparado pelo painel admin (prompt 47).
 *
 * Registro de diagnóstico: aponta para a execução real (`investigation_run_id`,
 * `source_run_id`) onde os resultados/evidências foram gravados, para a reserva
 * de crédito correspondente e para os tempos por etapa da execução.
 */
class ModuleTest extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'metadata' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'result_count' => 'integer',
            'evidence_count' => 'integer',
            'exit_code' => 'integer',
            'credits_cost' => 'integer',
            'credits_before' => 'integer',
            'credits_after' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceDefinition(): BelongsTo
    {
        return $this->belongsTo(SourceDefinition::class);
    }

    public function creditReservation(): BelongsTo
    {
        return $this->belongsTo(CreditReservation::class);
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function investigationRun(): BelongsTo
    {
        return $this->belongsTo(InvestigationRun::class);
    }

    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(SourceRun::class);
    }

    public function statusEnum(): ModuleTestStatus
    {
        return ModuleTestStatus::tryFrom($this->status) ?? ModuleTestStatus::Pending;
    }

    /**
     * Aguardando/executando — a tela acompanha o status até finalizar.
     */
    public function isRunning(): bool
    {
        return ! $this->statusEnum()->isTerminal();
    }

    /**
     * Tempos por etapa registrados na execução (prompt 47 §13).
     *
     * @return array<string, int>
     */
    public function timings(): array
    {
        $timings = $this->metadata['timings'] ?? [];

        if (! is_array($timings)) {
            return [];
        }

        return array_filter($timings, 'is_int');
    }

    /**
     * Tempo de uma etapa, se medido.
     */
    public function timing(string $stage): ?int
    {
        return $this->timings()[$stage] ?? null;
    }

    /**
     * Tempo decorrido (§10): duração final quando concluído, ou o decorrido até
     * agora enquanto a execução ainda está em andamento.
     */
    public function elapsedMs(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) round(abs(
            $this->started_at->diffInMilliseconds($this->finished_at ?? now())
        ));
    }

    public function durationMs(): ?int
    {
        return $this->duration_ms ?? $this->elapsedMs();
    }

    /**
     * Avisos públicos do módulo (ex.: resultados parciais, sites não avaliados).
     *
     * O adapter já publica o aviso traduzido; registros gravados antes disso são
     * convertidos aqui, para que a tela nunca exiba o texto bruto em inglês
     * (`ToolWarningsTranslator`).
     *
     * @return array<int, string>
     */
    public function warnings(): array
    {
        $warnings = $this->metadata['warnings'] ?? [];

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
     * Formata milissegundos no padrão exibido no painel (§10: 00:02:41).
     */
    public static function formatDuration(?int $milliseconds): string
    {
        if ($milliseconds === null) {
            return '—';
        }

        if ($milliseconds < 1000) {
            return $milliseconds.' ms';
        }

        $seconds = intdiv($milliseconds, 1000);

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}

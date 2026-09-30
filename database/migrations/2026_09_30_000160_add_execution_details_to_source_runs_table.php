<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Detalhes de execução por módulo (prompt 31).
 *
 * Completa `source_runs` para responder "o que exatamente ocorreu em cada
 * fonte": identificação direta da investigação, snapshot do slug da fonte,
 * contagem de resultados e versão da ferramenta. Renomeia `attempt` para
 * `attempts` (número de tentativas executadas).
 *
 * `metrics` (JSON) permanece para avisos e metadados extras do adapter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_runs', function (Blueprint $table) {
            $table->foreignId('investigation_id')->nullable()->after('investigation_run_id')->constrained()->cascadeOnDelete();
            $table->string('source')->nullable()->after('source_definition_id');
            $table->unsignedInteger('result_count')->default(0)->after('status');
            $table->string('tool_version')->nullable()->after('result_count');
            $table->renameColumn('attempt', 'attempts');
        });

        // `attempts` passa a contar tentativas executadas (0 antes da execução).
        Schema::table('source_runs', function (Blueprint $table) {
            $table->unsignedInteger('attempts')->default(0)->change();
        });

        // Backfill das execuções já existentes a partir das tabelas vizinhas.
        DB::statement('UPDATE source_runs SET investigation_id = (SELECT investigation_id FROM investigation_runs WHERE investigation_runs.id = source_runs.investigation_run_id) WHERE investigation_id IS NULL');
        DB::statement('UPDATE source_runs SET source = (SELECT slug FROM source_definitions WHERE source_definitions.id = source_runs.source_definition_id) WHERE source IS NULL');
        DB::statement('UPDATE source_runs SET result_count = (SELECT COUNT(*) FROM source_results WHERE source_results.source_run_id = source_runs.id)');

        // Toda execução pertence a uma investigação (a aplicação sempre preenche).
        Schema::table('source_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('investigation_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('source_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('investigation_id');
            $table->dropColumn(['source', 'result_count', 'tool_version']);
            $table->renameColumn('attempts', 'attempt');
        });
    }
};

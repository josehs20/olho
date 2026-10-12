<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Painel admin de testes individuais de módulo (prompt 47).
 *
 * Uma linha por execução isolada de módulo. Guarda o que a operação precisa
 * para diagnosticar lentidão/erro/consumo sem depender de leitura de log:
 * alvo, status, durações (total e por etapa), contagens, exit code, saída
 * resumida das ferramentas, créditos antes/custo/depois e o vínculo com a
 * execução real (`investigation_runs` / `source_runs`) e com a reserva de
 * crédito. Não duplica resultados: findings e evidências continuam em
 * `source_results` / `evidence`, gravados pelo mesmo caminho do pipeline real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_tests', function (Blueprint $table) {
            $table->id();
            // Administrador que disparou o teste (área é restrita ao admin).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_definition_id')->constrained()->cascadeOnDelete();
            $table->string('module'); // slug do módulo testado (snapshot legível)
            $table->string('target'); // alvo informado (username/e-mail)
            $table->json('input')->nullable(); // entrada normalizada (contrato InvestigationInput)

            $table->string('status')->default('pending');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->unsignedInteger('result_count')->default(0);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->integer('exit_code')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message_public')->nullable();
            $table->text('stdout')->nullable();
            $table->text('stderr')->nullable();

            $table->unsignedInteger('credits_cost')->default(0);
            $table->integer('credits_before')->nullable();
            $table->integer('credits_after')->nullable();
            $table->foreignId('credit_reservation_id')->nullable()->constrained('credit_reservations')->nullOnDelete();

            // Execução real correspondente (investigação/runs do domínio).
            $table->foreignId('investigation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('investigation_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_run_id')->nullable()->constrained('source_runs')->nullOnDelete();

            // Tempos por etapa, avisos e metadados públicos do adapter.
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['source_definition_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_tests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobrança por módulo (prompt 39).
 *
 * Cada reserva passa a registrar a qual módulo pertence, para que o
 * encerramento do run possa consumir apenas os módulos **concluídos** e
 * estornar os que falharam/expiraram/não eram aplicáveis — sem corromper o
 * saldo e com histórico auditável no ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_reservations', function (Blueprint $table) {
            $table->string('source')->nullable()->after('investigation_run_id');
            $table->index(['investigation_run_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('credit_reservations', function (Blueprint $table) {
            $table->dropIndex(['investigation_run_id', 'source']);
            $table->dropColumn('source');
        });
    }
};

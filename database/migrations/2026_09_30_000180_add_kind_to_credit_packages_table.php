<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preço fixo da investigação avulsa (prompt 41).
 *
 * `credit_packages` passa a distinguir pacotes de créditos (`credits`) do preço
 * fixo da investigação avulsa (`guest_investigation`), para que o admin mantenha
 * o valor em `/admin/packages` sem deploy e sem uma tabela de configuração
 * paralela. O pacote avulso não concede créditos: o visitante paga um valor fixo
 * por uma execução ("pagou, usou, acabou").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_packages', function (Blueprint $table) {
            $table->string('kind')->default('credits')->after('name');
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::table('credit_packages', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};

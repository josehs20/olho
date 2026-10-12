<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos do checkout PagBank (prompt 45).
 *
 * `external_id` passa a guardar o id do checkout (`CHEC_...`) e ganhamos as
 * colunas específicas do provedor para consulta/histórico. Nenhuma coluna de
 * dados de cartão é criada (o OLHO nunca armazena PAN/CVV).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider_checkout_id')->nullable()->after('external_id');
            $table->string('checkout_url', 2048)->nullable()->after('provider_checkout_id');
            $table->string('method')->nullable()->after('status');
            $table->string('environment')->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['provider_checkout_id', 'checkout_url', 'method', 'environment']);
        });
    }
};

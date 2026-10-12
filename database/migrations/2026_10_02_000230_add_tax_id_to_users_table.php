<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CPF/CNPJ do titular da conta (pagador).
 *
 * **Histórico:** esta coluna foi criada quando o OLHO montava o objeto
 * `customer` do PagBank. O fluxo atual usa o checkout hospedado
 * (`customer_modifiable: true`) e o documento é informado na página do
 * provedor; a coluna é removida em
 * `2026_10_02_000240_drop_tax_id_from_users_table`. A migration permanece
 * apenas para que instalações que já a executaram continuem consistentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 512 caracteres: o valor cifrado é bem maior que os 14 dígitos.
            $table->string('tax_id', 512)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tax_id');
        });
    }
};

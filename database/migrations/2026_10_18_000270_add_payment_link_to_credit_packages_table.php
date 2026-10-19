<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link de pagamento por pacote (provedor `payment_link`).
 *
 * O administrador gera o link no site do banco e cola aqui, um por pacote —
 * inclusive no preço da investigação avulsa. Nenhum segredo é persistido: o
 * link é público por natureza (é o mesmo que o comprador recebe) e não expira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_packages', function (Blueprint $table) {
            $table->string('payment_link', 2048)->nullable()->after('price_cents');
        });
    }

    public function down(): void
    {
        Schema::table('credit_packages', function (Blueprint $table) {
            $table->dropColumn('payment_link');
        });
    }
};

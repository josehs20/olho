<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O CPF/CNPJ deixou de ser coletado pelo OLHO.
 *
 * Quem informa o documento é a **página hospedada do PagBank** (checkout com
 * `customer_modifiable: true`), então a coluna do cadastro perdeu a razão de
 * existir: dado pessoal que não é usado não deve ser guardado (minimização).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // O conteúdo anterior é irrecuperável: a coluna volta vazia.
            $table->string('tax_id', 512)->nullable()->after('name');
        });
    }
};

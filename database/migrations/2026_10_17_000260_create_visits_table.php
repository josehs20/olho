<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contador de visitantes do site (painel admin).
 *
 * Uma linha por dia com dois agregados: `page_views` (carregamentos de página
 * HTML bem-sucedidos) e `unique_visitors` (sessões únicas no dia). Nenhum dado
 * pessoal é guardado — sem IP, user-agent, URL ou identificador de visitante —
 * apenas contadores agregados, em linha com a diretriz de coleta mínima
 * (docs/14-privacidade.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedBigInteger('page_views')->default(0);
            $table->unsignedBigInteger('unique_visitors')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};

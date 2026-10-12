<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuração administrativa chave/valor (prompt 45).
 *
 * Armazena pares `key => value`. O valor é persistido **cifrado** pelo cast
 * `encrypted` do model (App\Models\Setting), garantindo que credenciais de
 * gateway (ex.: token PagBank) nunca fiquem em texto puro no banco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};

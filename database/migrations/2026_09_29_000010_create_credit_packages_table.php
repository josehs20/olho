<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('credits');
            $table->unsignedInteger('price_cents');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_packages');
    }
};

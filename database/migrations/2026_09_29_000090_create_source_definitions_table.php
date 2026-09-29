<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('type');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('cost_credits')->default(0);
            $table->unsignedInteger('cost_operational_cents')->nullable();
            $table->unsignedInteger('timeout')->default(30);
            $table->json('retry_policy')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();

            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_definitions');
    }
};

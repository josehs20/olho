<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_definition_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('error_code')->nullable();
            $table->string('error_message_public')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->unique(['investigation_run_id', 'source_definition_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_runs');
    }
};

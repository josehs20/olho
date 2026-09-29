<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('run_number')->default(1);
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('credits_reserved')->default(0);
            $table->unsignedBigInteger('credits_consumed')->default(0);
            $table->json('input_snapshot')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->unique(['investigation_id', 'run_number']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_runs');
    }
};

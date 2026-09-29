<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_definition_id')->constrained()->restrictOnDelete();
            $table->string('entity_type')->nullable();
            $table->string('value');
            $table->string('url')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->string('dedup_hash', 64)->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();

            $table->index('dedup_hash');
            $table->index('entity_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_results');
    }
};

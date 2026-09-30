<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Relatório de investigação avulsa (prompt 41).
 *
 * O relatório é sempre vinculado ao run; passa a poder existir sem usuário
 * quando o run pertence a uma investigação avulsa.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        if (! $isSqlite) {
            Schema::table('reports', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        Schema::table('reports', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        if (! $isSqlite) {
            Schema::table('reports', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        if (! $isSqlite) {
            Schema::table('reports', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        DB::table('reports')->whereNull('user_id')->delete();

        Schema::table('reports', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        if (! $isSqlite) {
            Schema::table('reports', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};

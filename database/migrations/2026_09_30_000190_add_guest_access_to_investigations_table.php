<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Investigação avulsa (prompt 41).
 *
 * O visitante não cria conta: a investigação passa a poder existir sem
 * `user_id`, marcada com `origin = guest`, e o acesso é autorizado apenas pelo
 * token de alta entropia (persistido como hash SHA-256), nunca pelo id.
 * `guest_price_cents` congela o preço aplicado no momento da criação.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        if (! $isSqlite) {
            Schema::table('investigations', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        Schema::table('investigations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('investigations', function (Blueprint $table) {
            $table->string('origin')->default('account')->after('user_id');
            $table->char('access_token_hash', 64)->nullable()->after('status');
            $table->timestamp('access_token_created_at')->nullable()->after('access_token_hash');
            $table->timestamp('access_token_expires_at')->nullable()->after('access_token_created_at');
            $table->unsignedInteger('guest_price_cents')->nullable()->after('selected_modules');
            $table->unique('access_token_hash');
            $table->index(['origin', 'status']);
        });

        if (! $isSqlite) {
            Schema::table('investigations', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        if (! $isSqlite) {
            Schema::table('investigations', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        // Reverter a nulabilidade exige remover antes as investigações sem dono.
        DB::table('investigations')->whereNull('user_id')->delete();

        Schema::table('investigations', function (Blueprint $table) {
            $table->dropUnique(['access_token_hash']);
            $table->dropIndex(['origin', 'status']);
            $table->dropColumn([
                'origin',
                'access_token_hash',
                'access_token_created_at',
                'access_token_expires_at',
                'guest_price_cents',
            ]);
        });

        Schema::table('investigations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        if (! $isSqlite) {
            Schema::table('investigations', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};

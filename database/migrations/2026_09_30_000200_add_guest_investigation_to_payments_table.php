<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pagamento da investigação avulsa (prompt 41).
 *
 * O pedido deixa de exigir usuário e passa a poder apontar para uma
 * investigação avulsa (um pedido por investigação), mantendo a abstração PIX e
 * os eventos de pagamento já existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        if (! $isSqlite) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('guest_investigation_id')->nullable()->after('credit_package_id')->constrained('investigations')->cascadeOnDelete();
            $table->unique('guest_investigation_id');
        });

        if (! $isSqlite) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        if (! $isSqlite) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        DB::table('payments')->whereNull('user_id')->delete();

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['guest_investigation_id']);
            $table->dropConstrainedForeignId('guest_investigation_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        if (! $isSqlite) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};

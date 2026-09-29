<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreditConcurrencyTest extends TestCase
{
    /**
     * Retorna o nome do banco MySQL real lido do .env (o phpunit.xml sobrescreve
     * DB_DATABASE com :memory: para os testes sqlite, então precisamos ler o arquivo).
     */
    private function mysqlDatabase(): ?string
    {
        try {
            $content = file_get_contents(base_path('.env'));
            if ($content === false || ! preg_match('/^DB_DATABASE=(.+)$/m', $content, $m)) {
                return null;
            }

            $database = trim($m[1]);
        } catch (\Throwable) {
            return null;
        }

        return ($database === '' || $database === ':memory:') ? null : $database;
    }

    public function test_wallet_row_lock_serializes_concurrent_writers(): void
    {
        $database = $this->mysqlDatabase();

        if ($database === null) {
            $this->markTestSkipped('Concorrência requer MySQL real (FOR UPDATE).');
        }

        $base = config('database.connections.mysql');
        $base['database'] = $database;

        config()->set('database.connections.concurrency_test', $base + ['name' => 'concurrency_test']);
        config()->set('database.connections.concurrency_test_2', $base + ['name' => 'concurrency_test_2']);

        $db = DB::connection('concurrency_test');
        $db2 = DB::connection('concurrency_test_2');

        $userId = null;
        $walletId = null;

        try {
            $userId = $db->table('users')->insertGetId([
                'name' => 'Concurrency Test',
                'email' => 'concurrency-'.uniqid().'@example.test',
                'password' => Hash::make('password'),
                'is_admin' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $walletId = $db->table('wallets')->insertGetId([
                'user_id' => $userId,
                'balance' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $db->beginTransaction();
            $db->table('wallets')->where('id', $walletId)->lockForUpdate()->first();

            $db2->statement('SET innodb_lock_wait_timeout = 1');
            $db2->beginTransaction();

            try {
                $db2->table('wallets')->where('id', $walletId)->lockForUpdate()->first();
                $db2->rollBack();
                $this->fail('Esperado timeout de lock na segunda conexão.');
            } catch (\Throwable $e) {
                $db2->rollBack();
                $this->assertStringContainsStringIgnoringCase('lock wait timeout', $e->getMessage());
            }
        } finally {
            $db->rollBack();
            if ($walletId !== null) {
                $db->table('wallets')->where('id', $walletId)->delete();
            }
            if ($userId !== null) {
                $db->table('users')->where('id', $userId)->delete();
            }
        }
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // Dados de referência (determinísticos, exigidos pela aplicação).
            SourceDefinitionSeeder::class,
            CreditPackageSeeder::class,
            // Contas e dados de demonstração (idempotentes).
            DemoUsersSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}

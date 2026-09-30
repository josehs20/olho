<?php

namespace Database\Seeders\Support;

/**
 * Resolve as contas de demonstração a partir do ambiente, com fallback local.
 *
 * Compartilhado entre o `DemoUsersSeeder` (que cria as contas) e o
 * `DemoDataSeeder` (que popula dados para elas), evitando duplicar a leitura
 * das variáveis `DEMO_*` / `ADMIN_*`.
 */
final class DemoAccounts
{
    public static function adminName(): string
    {
        return self::envFirst(['DEMO_ADMIN_NAME', 'ADMIN_NAME']) ?? 'Administrador';
    }

    public static function adminEmail(): string
    {
        return self::envFirst(['DEMO_ADMIN_EMAIL', 'ADMIN_EMAIL']) ?? 'admin@example.com';
    }

    public static function userName(): string
    {
        return self::envFirst(['DEMO_USER_NAME']) ?? 'Usuário Demo';
    }

    public static function userEmail(): string
    {
        return self::envFirst(['DEMO_USER_EMAIL']) ?? 'usuario@example.com';
    }

    /**
     * Primeiro valor de ambiente preenchido (ignora vazios e o placeholder CONFIGURE_ME).
     *
     * @param  array<int, string>  $keys
     */
    public static function envFirst(array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = env($key);

            if (is_string($value) && trim($value) !== '' && $value !== 'CONFIGURE_ME') {
                return trim($value);
            }
        }

        return null;
    }
}

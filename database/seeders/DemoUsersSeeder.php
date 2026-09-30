<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use Database\Seeders\Support\DemoAccounts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Cria usuários de demonstração (admin e comum) para testar o login localmente.
 *
 * E-mails e senhas vêm de variáveis de ambiente. Em `local`/`testing` há um
 * fallback conhecido (`password`) para facilitar o acesso; em qualquer outro
 * ambiente a senha é gerada aleatoriamente e impressa no console.
 *
 * Idempotente: reexecutar não duplica usuários nem créditos (ledger usa
 * idempotency key fixa por usuário).
 */
class DemoUsersSeeder extends Seeder
{
    /**
     * Permite sobrescrever as senhas (útil em testes). Por padrão vêm do ambiente.
     */
    public function __construct(
        private readonly ?string $adminPassword = null,
        private readonly ?string $userPassword = null,
    ) {}

    public function run(CreditLedgerService $credits): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'DemoUsersSeeder não deve ser executado em produção. Use php artisan app:create-admin.'
            );
        }

        $demoCredits = max(0, (int) env('DEMO_CREDITS', 500));

        [$adminPassword, $adminPasswordGenerated] = $this->resolvePassword(['DEMO_ADMIN_PASSWORD', 'ADMIN_PASSWORD'], $this->adminPassword);
        [$userPassword, $userPasswordGenerated] = $this->resolvePassword(['DEMO_USER_PASSWORD'], $this->userPassword);

        $admin = $this->upsertUser(
            DemoAccounts::adminName(),
            DemoAccounts::adminEmail(),
            $adminPassword,
            isAdmin: true,
        );

        $user = $this->upsertUser(
            DemoAccounts::userName(),
            DemoAccounts::userEmail(),
            $userPassword,
            isAdmin: false,
        );

        $this->grantCredits($credits, $admin, $demoCredits);
        $this->grantCredits($credits, $user, $demoCredits);

        $this->report([
            ['role' => 'admin', 'email' => $admin->email, 'password' => $adminPassword, 'generated' => $adminPasswordGenerated],
            ['role' => 'comum', 'email' => $user->email, 'password' => $userPassword, 'generated' => $userPasswordGenerated],
        ], $demoCredits);
    }

    /**
     * Cria ou atualiza um usuário com e-mail já verificado.
     */
    private function upsertUser(string $name, string $email, string $password, bool $isAdmin): User
    {
        $email = Str::lower(trim($email));

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = $name;
        $user->password = Hash::make($password);
        $user->email_verified_at = $user->email_verified_at ?? now();
        $user->is_admin = $isAdmin; // Fora do $fillable: atribuição direta impede escalonamento via formulário.
        $user->save();

        return $user;
    }

    /**
     * Concede créditos promocionais via ledger (nunca escrevendo no saldo direto).
     */
    private function grantCredits(CreditLedgerService $credits, User $user, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        $credits->promotional(
            $user,
            $wallet,
            $amount,
            'seed-demo-credits-'.$user->id,
            ['source' => self::class],
        );
    }

    /**
     * @param  array<int, string>  $keys
     * @return array{0: string, 1: bool} Senha e se foi gerada aleatoriamente.
     */
    private function resolvePassword(array $keys, ?string $override = null): array
    {
        if ($override !== null && trim($override) !== '') {
            return [$override, false];
        }

        if ($configured = DemoAccounts::envFirst($keys)) {
            return [$configured, false];
        }

        if (app()->environment(['local', 'testing'])) {
            return ['password', false];
        }

        return [Str::password(16), true];
    }

    /**
     * @param  array<int, array{role: string, email: string, password: string, generated: bool}>  $accounts
     */
    private function report(array $accounts, int $demoCredits): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->info('Usuários de demonstração prontos:');

        // Em ambiente local mostra a senha resolvida; nos demais, só as geradas aleatoriamente.
        $showPassword = app()->environment(['local', 'testing']);

        foreach ($accounts as $account) {
            $line = sprintf('  %-5s %s', $account['role'], $account['email']);

            if ($showPassword || $account['generated']) {
                $line .= ' / '.$account['password'];
            }

            $this->command->line($line);
        }

        if (array_filter($accounts, fn (array $account) => $account['generated']) !== []) {
            $this->command->warn('Senhas geradas aleatoriamente (guarde-as).');
        }

        if ($demoCredits > 0) {
            $this->command->line("Créditos promocionais concedidos por usuário: {$demoCredits}");
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\CreditPackage;
use App\Models\Investigation;
use App\Models\SourceDefinition;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentStatus;
use App\Payments\VerifiedPaymentEvent;
use App\Services\AuditLogger;
use App\Services\CreditLedgerService;
use App\Services\InvestigationService;
use App\Services\PaymentService;
use Database\Seeders\Support\DemoAccounts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Popula o banco com um conjunto coerente de dados para navegar no produto.
 *
 * Usa os serviços reais (ledger de créditos, pagamentos e pipeline de
 * investigação) em vez de escrever nas tabelas diretamente, garantindo que
 * saldo, reservas, resultados, correlação e relatório fiquem consistentes.
 *
 * Idempotente: reexecutar não duplica pagamentos, investigações nem créditos.
 */
class DemoDataSeeder extends Seeder
{
    private const TOP_UP_CREDITS = 500;

    private const MINIMUM_BALANCE = 200;

    public function run(
        CreditLedgerService $credits,
        PaymentService $payments,
        InvestigationService $investigations,
        AuditLogger $audit,
    ): void {
        if (app()->environment('production')) {
            throw new \RuntimeException('DemoDataSeeder não deve ser executado em produção.');
        }

        $this->assertReferenceData();

        $admin = $this->findUser(DemoAccounts::adminEmail());
        $user = $this->findUser(DemoAccounts::userEmail());

        $this->topUpWallet($credits, $user);
        $this->seedPayments($payments, $user);
        $this->seedInvestigations($investigations, $user);
        $this->seedAuditLogs($audit, $admin, $user);

        $this->command?->info(sprintf(
            'Dados de demonstração prontos para %s (%d investigações).',
            $user->email,
            $user->investigations()->count(),
        ));
    }

    private function assertReferenceData(): void
    {
        if (! CreditPackage::query()->where('kind', CreditPackage::KIND_CREDITS)->where('active', true)->exists()) {
            throw new \RuntimeException('Nenhum pacote ativo. Rode o CreditPackageSeeder antes.');
        }

        if (! SourceDefinition::query()->where('active', true)->exists()) {
            throw new \RuntimeException('Nenhuma fonte ativa. Rode o SourceDefinitionSeeder antes.');
        }
    }

    private function findUser(string $email): User
    {
        return User::query()->where('email', Str::lower(trim($email)))->first()
            ?? throw new \RuntimeException(
                "Usuário {$email} não encontrado. Rode o DemoUsersSeeder (php artisan db:seed) antes."
            );
    }

    private function topUpWallet(CreditLedgerService $credits, User $user): void
    {
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        if ($credits->balance($wallet) >= self::MINIMUM_BALANCE) {
            return;
        }

        $credits->promotional(
            $user,
            $wallet,
            self::TOP_UP_CREDITS,
            'seed-demo-topup-'.$user->id,
            ['source' => self::class],
        );
    }

    private function seedPayments(PaymentService $payments, User $user): void
    {
        if ($user->payments()->exists()) {
            return;
        }

        $packages = CreditPackage::query()
            ->where('kind', CreditPackage::KIND_CREDITS)
            ->where('active', true)
            ->orderBy('display_order')
            ->limit(2)
            ->get();

        if ($packages->count() < 2) {
            return;
        }

        // Pedido confirmado: cria payment_event e credita no ledger.
        $paid = $payments->checkout($user, $packages[0]);
        $payments->confirmPayment(new VerifiedPaymentEvent(
            externalEventId: 'seed-demo-paid-'.$paid->id,
            externalId: (string) $paid->external_id,
            status: PaymentStatus::Paid->value,
        ));

        // Pedido em aberto: exercita a tela de PIX copia e cola / simulação.
        $payments->checkout($user, $packages[1]);
    }

    /**
     * @return array<int, array{title: string, input: array<string, string>, modules: array<int, string>}>
     */
    private function scenarios(): array
    {
        return [
            [
                'title' => 'Presença digital — joao.silva',
                'input' => ['name' => 'João Silva', 'username' => 'joao.silva', 'city' => 'São Paulo', 'state' => 'SP'],
                'modules' => ['mock-username', 'maigret', 'sherlock', 'whatsmyname'],
            ],
            [
                'title' => 'Contas vinculadas — maria@example.com',
                'input' => ['email' => 'maria@example.com'],
                'modules' => ['mock-email', 'holehe'],
            ],
            [
                // 'maigret' falha por timeout com o usuário 'timeout';
                // 'mock-web' usa o nome e retorna resultado -> run "partial".
                'title' => 'Falha isolada de fonte',
                'input' => ['name' => 'Empresa XPTO', 'username' => 'timeout'],
                'modules' => ['mock-web', 'maigret'],
            ],
            [
                'title' => 'Busca sem resultados',
                'input' => ['username' => 'not_found'],
                'modules' => ['sherlock'],
            ],
        ];
    }

    private function seedInvestigations(InvestigationService $investigations, User $user): void
    {
        $this->withSynchronousPipeline(function () use ($investigations, $user): void {
            foreach ($this->scenarios() as $scenario) {
                $exists = Investigation::query()
                    ->where('user_id', $user->id)
                    ->where('title', $scenario['title'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                $investigations->create($user, $scenario['input'], $scenario['modules'], $scenario['title']);
            }
        });
    }

    private function seedAuditLogs(AuditLogger $audit, ?User $admin, User $user): void
    {
        if (AuditLog::query()->where('action', 'admin.credit_adjustment')->exists()) {
            return;
        }

        $audit->log($admin, 'admin.credit_adjustment', $user, [
            'amount' => self::TOP_UP_CREDITS,
            'reason' => 'Créditos promocionais de demonstração',
        ], '127.0.0.1');
    }

    /**
     * O pipeline de investigação é assíncrono; aqui roda em linha para a seed
     * terminar com os dados já processados (resultados, correlação e relatório).
     */
    private function withSynchronousPipeline(\Closure $callback): void
    {
        $queue = config('queue.default');
        $cache = config('cache.default');

        config(['queue.default' => 'sync', 'cache.default' => 'array']);

        try {
            $callback();
        } finally {
            config(['queue.default' => $queue, 'cache.default' => $cache]);
        }
    }
}

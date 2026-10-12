<?php

namespace App\Services;

use App\Jobs\RunModuleTestJob;
use App\Models\ModuleTest;
use App\Models\SourceDefinition;
use App\Models\User;
use App\Models\Wallet;
use App\ModuleTests\ModuleTestStatus;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Disparo e consulta de testes individuais de módulo (prompt 47).
 *
 * Responsabilidade restrita a **enfileirar**: monta o registro do teste, valida
 * o módulo contra a whitelist ativa e delega a execução ao worker
 * (`RunModuleTestJob`). Nenhuma ferramenta externa é executada na requisição HTTP
 * — vale o mesmo princípio do fluxo normal (§17).
 */
class ModuleTestService
{
    /**
     * Módulos testáveis (whitelist de `config/module_tests.php`), na ordem de
     * exibição, com o estado de ativação atual. Inativos aparecem marcados para
     * o administrador saber que precisam ser habilitados em Fontes.
     *
     * @return Collection<int, SourceDefinition>
     */
    public function testableDefinitions(): Collection
    {
        $slugs = array_values(array_filter((array) config('module_tests.modules', []), 'is_string'));

        $definitions = SourceDefinition::query()->whereIn('slug', $slugs)->get()->keyBy('slug');

        return collect($slugs)
            ->map(fn (string $slug) => $definitions->get($slug))
            ->filter()
            ->values();
    }

    /**
     * Campo de entrada esperado pela fonte (rotulado no formulário).
     */
    public function inputField(SourceDefinition $definition): string
    {
        return in_array($definition->type, ['email'], true) ? 'email' : 'username';
    }

    /**
     * Cria o registro do teste e enfileira a execução.
     */
    public function queue(User $admin, string $slug, string $target): ModuleTest
    {
        $definition = $this->runnableDefinition($slug);

        $input = $this->input($definition, $target);
        $wallet = $this->wallet($admin);

        $test = ModuleTest::query()->create([
            'user_id' => $admin->id,
            'source_definition_id' => $definition->id,
            'module' => $definition->slug,
            'target' => $input[$this->inputField($definition)],
            'input' => $input,
            'status' => ModuleTestStatus::Pending->value,
            'queued_at' => now(),
            'credits_before' => (int) $wallet->balance,
        ]);

        RunModuleTestJob::dispatch($test->id);

        return $test;
    }

    /**
     * Reexecuta um teste anterior (mesmo módulo e alvo), como um **novo** teste
     * com reserva de crédito independente. A execução anterior permanece
     * registrada e auditável (§14).
     */
    public function rerun(User $admin, ModuleTest $test): ModuleTest
    {
        return $this->queue($admin, (string) $test->module, (string) $test->target);
    }

    public function balance(User $admin): int
    {
        return (int) $this->wallet($admin)->balance;
    }

    /**
     * Entrada no formato do contrato do pipeline (`InvestigationInput`), com a
     * mesma sanitização aplicada por `InvestigationService` (trim + e-mail em
     * minúsculas) — nada inventado para o painel.
     *
     * @return array<string, string>
     */
    public function input(SourceDefinition $definition, string $target): array
    {
        $target = trim($target);

        return $this->inputField($definition) === 'email'
            ? ['email' => mb_strtolower($target)]
            : ['username' => $target];
    }

    /**
     * Mesma validação de módulos do fluxo normal (`InvestigationService::resolveModules`):
     * precisa existir e estar ativo. O slug em si já foi validado contra a
     * whitelist no FormRequest — aqui a checagem é de existência/estado.
     */
    private function runnableDefinition(string $slug): SourceDefinition
    {
        $slugs = (array) config('module_tests.modules', []);

        if (! in_array($slug, $slugs, true)) {
            throw ValidationException::withMessages(['module' => __('Módulo não disponível para teste individual.')]);
        }

        $definition = SourceDefinition::query()->where('slug', $slug)->first();

        if ($definition === null) {
            throw ValidationException::withMessages(['module' => __('Módulo não encontrado.')]);
        }

        if (! $definition->active) {
            throw ValidationException::withMessages([
                'module' => __('Este módulo está inativo. Ative-o em Fontes antes de testar.'),
            ]);
        }

        return $definition;
    }

    private function wallet(User $admin): Wallet
    {
        return Wallet::query()->firstOrCreate(['user_id' => $admin->id], ['balance' => 0]);
    }
}

<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Jobs\StartInvestigationRunJob;
use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\User;
use App\Models\Wallet;
use App\Sources\InvestigationInput;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvestigationService
{
    public function __construct(private readonly InvestigationOrchestrator $orchestrator) {}

    /**
     * Cria uma investigação e o seu primeiro run (snapshot + módulos selecionados).
     * O processamento é assíncrono (StartInvestigationRunJob), fora da requisição HTTP.
     */
    public function create(User $user, array $input, array $moduleSlugs, ?string $title = null): Investigation
    {
        $input = $this->sanitize($input);
        $modules = $this->resolveModules($moduleSlugs);

        // Determina os módulos aplicáveis ao tipo de identificador informado;
        // os não aplicáveis são registrados como limitação no run, não como erro.
        $selection = $this->selectApplicable($modules, $input);

        $this->assertSufficientCredits($user, (int) $selection['applicable']->sum('cost_credits'));

        $investigation = DB::transaction(function () use ($user, $input, $modules, $title) {
            $investigation = Investigation::query()->create([
                'user_id' => $user->id,
                'origin' => Investigation::ORIGIN_ACCOUNT,
                'title' => $title ?: ($input['username'] ?? 'Investigação'),
                'status' => Investigation::STATUS_QUEUED,
                'selected_modules' => $modules->pluck('slug')->all(),
            ]);

            $run = $investigation->runs()->create([
                'run_number' => 1,
                'status' => InvestigationRun::STATUS_PENDING,
                'input_snapshot' => array_merge($input, ['modules' => $modules->pluck('slug')->all()]),
            ]);

            $investigation->current_run_id = $run->id;
            $investigation->save();

            return $investigation;
        });

        StartInvestigationRunJob::dispatch($investigation->current_run_id);

        return $investigation;
    }

    /**
     * Histórico de investigações do usuário.
     */
    public function history(User $user, int $perPage = 15)
    {
        return Investigation::query()
            ->where('user_id', $user->id)
            ->with('currentRun')
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Reexecução controlada (prompt 39 / docs/02).
     *
     * Cria um **novo run** a partir do snapshot da última execução, com reserva
     * de créditos independente. O run anterior (resultados, evidências, ledger)
     * permanece intacto e auditável. Não é permitido enquanto houver execução em
     * andamento para a mesma investigação.
     */
    public function rerun(User $user, Investigation $investigation): InvestigationRun
    {
        $latest = $investigation->runs()->orderByDesc('run_number')->first();

        if ($latest === null) {
            throw new \InvalidArgumentException('A investigação não possui execução para reexecutar.');
        }

        $inProgress = $investigation->runs()
            ->whereIn('status', [InvestigationRun::STATUS_PENDING, InvestigationRun::STATUS_RUNNING])
            ->exists();

        if ($inProgress) {
            throw new \InvalidArgumentException('Já existe uma execução em andamento para esta investigação.');
        }

        $input = $this->sanitize($latest->input_snapshot ?? []);
        $moduleSlugs = $latest->moduleSlugs() ?: ($investigation->selected_modules ?? []);
        $modules = $this->resolveModules($moduleSlugs);

        $selection = $this->orchestrator->split($modules, InvestigationInput::fromArray($input));

        if ($selection['applicable']->isEmpty()) {
            throw new \InvalidArgumentException(
                'Os módulos da execução anterior não são aplicáveis aos dados armazenados.'
            );
        }

        $this->assertSufficientCredits($user, (int) $selection['applicable']->sum('cost_credits'));

        $run = DB::transaction(function () use ($investigation, $input, $modules) {
            $run = $investigation->runs()->create([
                'run_number' => ((int) $investigation->runs()->max('run_number')) + 1,
                'status' => InvestigationRun::STATUS_PENDING,
                'input_snapshot' => array_merge($input, ['modules' => $modules->pluck('slug')->all()]),
            ]);

            $investigation->current_run_id = $run->id;
            $investigation->status = Investigation::STATUS_QUEUED;
            $investigation->save();

            return $run;
        });

        StartInvestigationRunJob::dispatch($run->id);

        return $run;
    }

    /**
     * Cria a investigação avulsa (prompt 41): sem conta, sem créditos e sem
     * execução — o run nasce `pending` e só é despachado após o pagamento.
     *
     * O visitante não escolhe módulos: todos os módulos ativos aplicáveis aos
     * dados informados são usados, e o preço é fixo (`guest_price_cents`).
     *
     * @param  array<string, mixed>  $input
     */
    public function createGuest(array $input, int $priceCents, string $tokenHash, ?string $title = null): Investigation
    {
        $input = $this->sanitize($input);
        $modules = $this->activeModules();
        $this->selectApplicable($modules, $input);

        return DB::transaction(function () use ($input, $modules, $priceCents, $tokenHash, $title) {
            $investigation = Investigation::query()->create([
                'user_id' => null,
                'origin' => Investigation::ORIGIN_GUEST,
                'title' => $title ?: ($input['username'] ?? 'Investigação avulsa'),
                'status' => Investigation::STATUS_DRAFT,
                'selected_modules' => $modules->pluck('slug')->all(),
                'guest_price_cents' => $priceCents,
                'access_token_hash' => $tokenHash,
                'access_token_created_at' => now(),
                'access_token_expires_at' => now()->addDays((int) config('guest.access_token_ttl_days')),
            ]);

            $run = $investigation->runs()->create([
                'run_number' => 1,
                'status' => InvestigationRun::STATUS_PENDING,
                'input_snapshot' => array_merge($input, ['modules' => $modules->pluck('slug')->all()]),
                'metadata' => ['funding' => 'guest_prepaid'],
            ]);

            $investigation->current_run_id = $run->id;
            $investigation->save();

            return $investigation;
        });
    }

    /**
     * Divide os módulos em aplicáveis/não aplicáveis, rejeitando quando nenhum
     * módulo pode atender aos dados informados.
     *
     * @param  Collection<int, SourceDefinition>  $modules
     * @return array{applicable: Collection<int, SourceDefinition>, skipped: Collection<int, SourceDefinition>}
     */
    private function selectApplicable(Collection $modules, array $input): array
    {
        $selection = $this->orchestrator->split($modules, InvestigationInput::fromArray($input));

        if ($selection['applicable']->isEmpty()) {
            throw new \InvalidArgumentException(
                'Os módulos selecionados não são aplicáveis aos dados informados. Informe username, e-mail ou telefone compatível.'
            );
        }

        return $selection;
    }

    /**
     * Todos os módulos ativos — a investigação avulsa não escolhe módulos.
     *
     * @return Collection<int, SourceDefinition>
     */
    private function activeModules(): Collection
    {
        $modules = SourceDefinition::query()->where('active', true)->orderBy('name')->get();

        if ($modules->isEmpty()) {
            throw new \InvalidArgumentException('Nenhum módulo ativo disponível.');
        }

        return $modules;
    }

    private function sanitize(array $input): array
    {
        $fields = ['name', 'username', 'email', 'phone', 'city', 'state'];
        $clean = [];

        foreach ($fields as $field) {
            $value = $input[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            if ($field === 'email' && is_string($value)) {
                $value = mb_strtolower($value);
            }

            $clean[$field] = ($value === '' || $value === null) ? null : $value;
        }

        return $clean;
    }

    /**
     * @param  array<int, mixed>  $slugs
     * @return Collection<int, SourceDefinition>
     */
    private function resolveModules(array $slugs): Collection
    {
        $slugs = array_values(array_unique(array_filter($slugs, 'is_string')));

        if ($slugs === []) {
            throw new \InvalidArgumentException('Selecione ao menos um módulo.');
        }

        $modules = SourceDefinition::query()
            ->whereIn('slug', $slugs)
            ->where('active', true)
            ->get();

        if ($modules->count() !== count($slugs)) {
            throw new \InvalidArgumentException('Um ou mais módulos são inválidos ou inativos.');
        }

        return $modules;
    }

    /**
     * Validação antecipada de créditos (RF-09). A reserva atômica acontece no
     * `StartInvestigationRunJob`, com lock; aqui evitamos criar um run que já
     * nasceria falho.
     */
    private function assertSufficientCredits(User $user, int $cost): void
    {
        if ($cost <= 0) {
            return;
        }

        $balance = (int) (Wallet::query()->where('user_id', $user->id)->value('balance') ?? 0);

        if ($balance < $cost) {
            throw new InsufficientCreditsException($balance, $cost);
        }
    }
}

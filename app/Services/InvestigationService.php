<?php

namespace App\Services;

use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvestigationService
{
    /**
     * Cria uma investigação e o seu primeiro run (snapshot + módulos selecionados).
     * Não executa fontes externas nem reserva créditos (isso é feito por jobs).
     */
    public function create(User $user, array $input, array $moduleSlugs, ?string $title = null): Investigation
    {
        $input = $this->sanitize($input);
        $modules = $this->resolveModules($moduleSlugs, $input);

        return DB::transaction(function () use ($user, $input, $modules, $title) {
            $investigation = Investigation::query()->create([
                'user_id' => $user->id,
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

    private function resolveModules(array $slugs, array $input): Collection
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

        foreach ($modules as $module) {
            $required = $this->requiredInputFor($module->type);

            if ($required !== null && empty($input[$required])) {
                throw new \InvalidArgumentException("O módulo '{$module->name}' exige o campo '{$required}'.");
            }
        }

        return $modules;
    }

    private function requiredInputFor(string $type): ?string
    {
        return match ($type) {
            'username' => 'username',
            'email' => 'email',
            'phone' => 'phone',
            default => null,
        };
    }
}

<?php

namespace App\Services;

use App\Models\InvestigationRun;
use App\Models\SourceResult;
use App\Sources\InvestigationInput;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Correlação explicável (prompt 37).
 *
 * Regras (determinísticas e documentadas em
 * `docs/32-correlacao-explicavel.md`):
 *
 *  - usa **somente** os dados observados e persistidos neste run;
 *  - cada associação guarda fatores, evidências usadas, pontuação interna,
 *    explicação e limitações;
 *  - o valor é um **indicador interno** de correlação, jamais probabilidade de
 *    identidade (`score_kind` + limitações fixas);
 *  - username coincidente **não** infere identidade: só fatores corroborantes
 *    (nome, e-mail, localização) habilitam as faixas exibidas ao usuário.
 */
class CorrelationService
{
    public function persist(InvestigationRun $run): array
    {
        $correlation = $this->correlate($run);

        $metadata = $run->metadata ?? [];
        $metadata['correlation'] = $correlation;
        $run->metadata = $metadata;
        $run->save();

        return $correlation;
    }

    /**
     * Calcula a correlação de forma determinística e explicável.
     *
     * @return array<string, mixed>
     */
    public function correlate(InvestigationRun $run): array
    {
        $input = InvestigationInput::fromArray($run->input_snapshot ?? []);
        $results = $this->eligibleResults($run);

        $factors = $this->evaluateFactors($input, $results);
        $score = min(100, array_sum(array_column($factors, 'weight')));

        $corroborating = collect($factors)->where('corroborating', true)->count();
        $sources = collect($factors)->pluck('sources')->flatten()->filter()->unique()->values()->all();

        return [
            'score' => $score,
            'score_max' => 100,
            'score_kind' => config('correlation.score_kind'),
            'rule_version' => config('correlation.rule_version'),
            'label' => $this->label($score, $corroborating),
            'identity_asserted' => false,
            'factors' => $factors,
            // Compatibilidade com consumidores anteriores (nome + peso).
            'signals' => array_map(
                static fn (array $factor): array => ['name' => $factor['signal'], 'weight' => $factor['weight']],
                $factors,
            ),
            'evidence_ids' => collect($factors)->pluck('evidence_ids')->flatten()->unique()->values()->all(),
            'evidence_count' => collect($factors)->pluck('evidence_ids')->flatten()->unique()->count(),
            'sources_observed' => $sources,
            'explanations' => collect($factors)->pluck('explanation')->values()->all(),
            'limitations' => $this->limitations($factors, $sources),
            'not_evaluated' => $this->notEvaluated(),
            'observed_inputs' => $this->observedInputs($input),
            'observed_results' => $results->count(),
            'evaluated_at' => now()->toISOString(),
        ];
    }

    /**
     * Resultados não duplicados (sinais de correspondência reais). A descoberta
     * consolidada representa o grupo; duplicatas não somam pontos.
     */
    private function eligibleResults(InvestigationRun $run): Collection
    {
        return SourceResult::query()
            ->whereHas('sourceRun', fn ($q) => $q->where('investigation_run_id', $run->id))
            ->with('sourceRun.sourceDefinition')
            ->get()
            ->filter(fn (SourceResult $result) => empty($result->metadata['duplicate_of']))
            ->values();
    }

    /**
     * Constrói os fatores que efetivamente casaram, com evidência e explicação.
     *
     * @return array<int, array<string, mixed>>
     */
    private function evaluateFactors(InvestigationInput $input, Collection $results): array
    {
        $factors = [];

        foreach (config('correlation.signals') as $signal => $definition) {
            if (($definition['evaluated'] ?? true) === false) {
                continue;
            }

            $matched = $this->matches($signal, $input, $results);

            if ($matched->isEmpty()) {
                continue;
            }

            $factors[] = [
                'type' => $definition['type'],
                'signal' => $signal,
                'weight' => (int) $definition['weight'],
                'corroborating' => (bool) ($definition['corroborating'] ?? true),
                'explanation' => $definition['explanation'],
                'limitation' => $definition['limitation'],
                'evidence_ids' => $matched->pluck('id')->values()->all(),
                'evidence_types' => $this->evidenceTypes($definition, $matched),
                'sources' => $this->sources($matched),
                'observed_values' => $matched->pluck('value')->unique()->values()->all(),
            ];
        }

        return $factors;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  Collection<int, SourceResult>  $matched
     * @return array<int, string>
     */
    private function evidenceTypes(array $definition, Collection $matched): array
    {
        $declared = $definition['evidence_types'] ?? [];

        $observed = $matched
            ->flatMap(fn (SourceResult $result) => collect($result->raw_payload['evidence'] ?? [])->pluck('type'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $observed === [] ? $declared : $observed;
    }

    /**
     * @param  Collection<int, SourceResult>  $matched
     * @return array<int, string>
     */
    private function sources(Collection $matched): array
    {
        return $matched
            ->map(fn (SourceResult $result) => $result->sourceRun?->sourceDefinition?->slug ?? $result->sourceRun?->source)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, SourceResult>  $results
     * @return Collection<int, SourceResult>
     */
    private function matches(string $signal, InvestigationInput $input, Collection $results): Collection
    {
        return match ($signal) {
            'username_exact_match' => $this->byValue($input->username, $results),
            'email_exact_match' => $this->byValue($input->email, $results),
            'name_compatible' => $this->byName($input->name, $results),
            'location_compatible' => $this->byLocation($input, $results),
            default => collect(),
        };
    }

    /**
     * @param  Collection<int, SourceResult>  $results
     * @return Collection<int, SourceResult>
     */
    private function byValue(?string $value, Collection $results): Collection
    {
        if ($value === null || trim($value) === '') {
            return collect();
        }

        $normalized = $this->normalize($value);

        return $results
            ->filter(fn (SourceResult $result) => $this->normalize((string) $result->value) === $normalized)
            ->values();
    }

    /**
     * @param  Collection<int, SourceResult>  $results
     * @return Collection<int, SourceResult>
     */
    private function byName(?string $name, Collection $results): Collection
    {
        if ($name === null || trim($name) === '') {
            return collect();
        }

        $normalized = $this->normalize($name);

        return $results->filter(function (SourceResult $result) use ($normalized) {
            if ($this->normalize((string) $result->value) === $normalized) {
                return true;
            }

            $haystack = $this->normalize(trim(($result->title ?? '').' '.($result->description ?? '')));

            return $haystack !== '' && str_contains($haystack, $normalized);
        })->values();
    }

    /**
     * @param  Collection<int, SourceResult>  $results
     * @return Collection<int, SourceResult>
     */
    private function byLocation(InvestigationInput $input, Collection $results): Collection
    {
        $city = $input->city !== null ? $this->normalize($input->city) : null;
        $state = $input->state !== null ? $this->normalize($input->state) : null;

        if (($city === null || $city === '') && ($state === null || $state === '')) {
            return collect();
        }

        return $results->filter(function (SourceResult $result) use ($city, $state) {
            $location = $this->normalize((string) ($result->metadata['location'] ?? ''));

            if ($location === '') {
                return false;
            }

            return ($city !== null && $city !== '' && str_contains($location, $city))
                || ($state !== null && $state !== '' && str_contains($location, $state));
        })->values();
    }

    /**
     * Faixa de exibição. Sem fator corroborante nenhuma faixa de associação é
     * exibida — username coincidente não indica identidade.
     */
    private function label(int $score, int $corroborating): string
    {
        if ($score === 0) {
            return 'sem correspondência observada';
        }

        if ($corroborating === 0) {
            return 'username coincidente — sem corroboração';
        }

        if ($score >= (int) config('correlation.strong_threshold')) {
            return 'correspondência forte por sinais públicos';
        }

        if ($score >= (int) config('correlation.possible_threshold')) {
            return 'possível correspondência';
        }

        return 'baixa correspondência';
    }

    /**
     * @param  array<int, array<string, mixed>>  $factors
     * @param  array<int, string>  $sources
     * @return array<int, string>
     */
    private function limitations(array $factors, array $sources): array
    {
        $limitations = config('correlation.global_limitations');

        foreach ($factors as $factor) {
            if (isset($factor['limitation']) && ! in_array($factor['limitation'], $limitations, true)) {
                $limitations[] = $factor['limitation'];
            }
        }

        if (count($sources) === 1) {
            $limitations[] = 'Todos os sinais vieram de uma única fonte ('.$sources[0].'); não houve corroboração entre fontes independentes.';
        }

        if ($factors === []) {
            $limitations[] = 'Nenhum fator de correlação casou com os dados observados; ausência de correspondência não prova inexistência.';
        }

        return $limitations;
    }

    /**
     * @return array<int, string>
     */
    private function notEvaluated(): array
    {
        return collect(config('correlation.signals'))
            ->filter(fn (array $definition) => ($definition['evaluated'] ?? true) === false)
            ->map(fn (array $definition) => $definition['type'])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string|null>
     */
    private function observedInputs(InvestigationInput $input): array
    {
        return [
            'name' => $input->name,
            'username' => $input->username,
            'email' => $input->email,
            'phone' => $input->phone,
            'city' => $input->city,
            'state' => $input->state,
        ];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(Str::ascii($value)));
    }
}

<?php

namespace App\Services;

use App\Models\InvestigationRun;
use App\Models\SourceResult;
use App\Sources\InvestigationInput;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
     */
    public function correlate(InvestigationRun $run): array
    {
        $input = InvestigationInput::fromArray($run->input_snapshot ?? []);
        $results = $this->eligibleResults($run);

        $signals = $this->evaluateSignals($input, $results);

        $score = min(100, array_sum(array_column($signals, 'weight')));

        return [
            'score' => $score,
            'rule_version' => config('correlation.rule_version'),
            'signals' => array_values($signals),
            'evidence_ids' => $results->pluck('id')->values()->all(),
            'label' => $this->label($score),
        ];
    }

    /**
     * Resultados não duplicados (sinais de correspondência reais).
     */
    private function eligibleResults(InvestigationRun $run): Collection
    {
        return SourceResult::query()
            ->whereHas('sourceRun', fn ($q) => $q->where('investigation_run_id', $run->id))
            ->get()
            ->filter(fn (SourceResult $r) => empty($r->metadata['duplicate_of']))
            ->values();
    }

    /**
     * Sinais que efetivamente casaram.
     */
    private function evaluateSignals(InvestigationInput $input, Collection $results): array
    {
        $signals = [];

        foreach (config('correlation.weights') as $name => $weight) {
            if ($this->signalMatches($name, $input, $results)) {
                $signals[$name] = ['name' => $name, 'weight' => $weight];
            }
        }

        return $signals;
    }

    private function signalMatches(string $name, InvestigationInput $input, Collection $results): bool
    {
        return match ($name) {
            'username_exact_match' => $this->hasValue($input->username, $results),
            'email_exact_match' => $this->hasValue($input->email, $results),
            'name_compatible' => $this->nameMatches($input->name, $results),
            'location_compatible' => $this->locationMatches($input, $results),
            'bio_similar', 'image_reference_compatible' => false,
            default => false,
        };
    }

    private function hasValue(?string $value, Collection $results): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }

        $normalized = $this->normalize($value);

        return $results->contains(fn (SourceResult $r) => $this->normalize($r->value) === $normalized);
    }

    private function nameMatches(?string $name, Collection $results): bool
    {
        if ($name === null || trim($name) === '') {
            return false;
        }

        $normalized = $this->normalize($name);

        return $results->contains(function (SourceResult $r) use ($normalized) {
            if ($this->normalize($r->value) === $normalized) {
                return true;
            }

            return $r->title !== null && str_contains($this->normalize($r->title), $normalized);
        });
    }

    private function locationMatches(InvestigationInput $input, Collection $results): bool
    {
        if ($input->city === null && $input->state === null) {
            return false;
        }

        $city = $input->city !== null ? $this->normalize($input->city) : null;
        $state = $input->state !== null ? $this->normalize($input->state) : null;

        return $results->contains(function (SourceResult $r) use ($city, $state) {
            $location = $this->normalize((string) ($r->metadata['location'] ?? ''));

            if ($location === '') {
                return false;
            }

            return ($city !== null && str_contains($location, $city))
                || ($state !== null && str_contains($location, $state));
        });
    }

    private function label(int $score): string
    {
        if ($score >= (int) config('correlation.strong_threshold')) {
            return 'correspondência forte por sinais públicos';
        }

        if ($score >= (int) config('correlation.possible_threshold')) {
            return 'possível correspondência';
        }

        return 'baixa correspondência';
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(Str::ascii($value)));
    }
}

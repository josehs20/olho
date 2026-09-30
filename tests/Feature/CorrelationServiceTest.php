<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\SourceResult;
use App\Models\User;
use App\Services\CorrelationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorrelationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(array $input, array $results): InvestigationRun
    {
        $user = User::factory()->create();
        $investigation = Investigation::factory()->create(['user_id' => $user->id]);
        $run = InvestigationRun::factory()->create([
            'investigation_id' => $investigation->id,
            'input_snapshot' => $input,
        ]);

        $source = SourceDefinition::factory()->create(['slug' => 's']);
        $sourceRun = $run->sourceRuns()->create(['source_definition_id' => $source->id, 'status' => 'completed']);

        foreach ($results as $result) {
            SourceResult::factory()->create(array_merge([
                'source_run_id' => $sourceRun->id,
                'source_definition_id' => $source->id,
                'entity_type' => 'social_profile',
            ], $result));
        }

        return $run;
    }

    public function test_username_match_scores_40(): void
    {
        $run = $this->makeRun(['username' => 'joao'], [
            ['value' => 'joao', 'url' => 'https://github.com/joao'],
        ]);

        $correlation = app(CorrelationService::class)->correlate($run);

        $this->assertSame(40, $correlation['score']);
        $this->assertContains('username_exact_match', array_column($correlation['signals'], 'name'));
    }

    public function test_username_and_name_match_scores_60(): void
    {
        $run = $this->makeRun(['username' => 'joao', 'name' => 'João Silva'], [
            ['value' => 'joao', 'url' => 'https://github.com/joao', 'title' => 'João Silva'],
        ]);

        $correlation = app(CorrelationService::class)->correlate($run);

        $this->assertSame(60, $correlation['score']);
        $this->assertSame('correspondência forte por sinais públicos', $correlation['label']);
    }

    public function test_email_match_scores_20(): void
    {
        $run = $this->makeRun(['email' => 'a@b.c'], [
            ['value' => 'a@b.c', 'entity_type' => 'email_account', 'url' => 'https://twitter.com'],
        ]);

        $correlation = app(CorrelationService::class)->correlate($run);

        $this->assertSame(20, $correlation['score']);
    }

    public function test_location_match_scores_10(): void
    {
        $run = $this->makeRun(['username' => 'joao', 'city' => 'Belo Horizonte'], [
            ['value' => 'joao', 'url' => 'https://github.com/joao', 'metadata' => ['location' => 'Belo Horizonte']],
        ]);

        $correlation = app(CorrelationService::class)->correlate($run);

        $this->assertSame(50, $correlation['score']);
        $this->assertSame('possível correspondência', $correlation['label']);
    }

    public function test_same_inputs_produce_same_result(): void
    {
        $run = $this->makeRun(['username' => 'joao'], [
            ['value' => 'joao', 'url' => 'https://github.com/joao'],
        ]);

        $a = app(CorrelationService::class)->correlate($run);
        $b = app(CorrelationService::class)->correlate($run);

        // `evaluated_at` registra o instante da avaliação; o resto é determinístico.
        unset($a['evaluated_at'], $b['evaluated_at']);

        $this->assertSame($a, $b);
    }

    public function test_persists_correlation(): void
    {
        $run = $this->makeRun(['username' => 'joao'], [
            ['value' => 'joao', 'url' => 'https://github.com/joao'],
        ]);

        app(CorrelationService::class)->persist($run);

        $run->refresh();

        $this->assertSame(40, $run->metadata['correlation']['score']);
        $this->assertSame('v2', $run->metadata['correlation']['rule_version']);
        $this->assertNotEmpty($run->metadata['correlation']['signals']);
        $this->assertNotEmpty($run->metadata['correlation']['evidence_ids']);
    }

    public function test_positive_case_records_factors_evidence_and_explanation(): void
    {
        $run = $this->makeRun(['username' => 'joao', 'name' => 'João Silva', 'city' => 'Belo Horizonte'], [
            [
                'value' => 'joao',
                'url' => 'https://github.com/joao',
                'title' => 'João Silva',
                'metadata' => ['location' => 'Belo Horizonte, MG'],
                'raw_payload' => ['evidence' => [['type' => 'username_match', 'value' => 'joao']]],
            ],
        ]);

        $correlation = app(CorrelationService::class)->correlate($run);

        $this->assertSame(70, $correlation['score']);
        $this->assertSame('correspondência forte por sinais públicos', $correlation['label']);
        $this->assertFalse($correlation['identity_asserted']);
        $this->assertSame('internal_correlation_indicator', $correlation['score_kind']);

        $this->assertSame(
            ['same_username', 'matching_public_name', 'matching_location'],
            array_column($correlation['factors'], 'type'),
        );

        $usernameFactor = $correlation['factors'][0];
        $this->assertSame(40, $usernameFactor['weight']);
        $this->assertFalse($usernameFactor['corroborating']);
        $this->assertNotEmpty($usernameFactor['explanation']);
        $this->assertNotEmpty($usernameFactor['limitation']);
        $this->assertNotEmpty($usernameFactor['evidence_ids']);
        $this->assertSame(['username_match'], $usernameFactor['evidence_types']);
        $this->assertSame(['s'], $usernameFactor['sources']);

        // O indicador nunca é apresentado como probabilidade de identidade.
        $this->assertContains(
            'O valor exibido é um indicador interno de correlação entre sinais públicos observados; não é probabilidade de identidade nem confirmação de que a mesma pessoa controla as contas.',
            $correlation['limitations'],
        );
        $this->assertContains('matching_image_reference', $correlation['not_evaluated']);
        $this->assertContains('similar_bio', $correlation['not_evaluated']);
    }

    public function test_ambiguous_case_does_not_infer_identity_from_username_alone(): void
    {
        $run = $this->makeRun(['username' => 'joao'], [
            ['value' => 'joao', 'url' => 'https://github.com/joao'],
        ]);

        $correlation = app(CorrelationService::class)->correlate($run);

        $this->assertSame(40, $correlation['score']);
        $this->assertSame('username coincidente — sem corroboração', $correlation['label']);
        $this->assertFalse($correlation['identity_asserted']);
        $this->assertContains(
            'Username é escolhido pelo titular e pode ser reutilizado por pessoas diferentes; isoladamente não indica identidade.',
            $correlation['limitations'],
        );

        // Aviso de fonte única (apenas um adapter observou os sinais).
        $this->assertContains(
            'Todos os sinais vieram de uma única fonte (s); não houve corroboração entre fontes independentes.',
            $correlation['limitations'],
        );
    }

    public function test_negative_case_scores_zero_and_explains_absence(): void
    {
        $run = $this->makeRun(['username' => 'joao', 'name' => 'João Silva'], [
            ['value' => 'outro', 'url' => 'https://github.com/outro', 'title' => 'Outra Pessoa'],
        ]);

        $correlation = app(CorrelationService::class)->correlate($run);

        $this->assertSame(0, $correlation['score']);
        $this->assertSame('sem correspondência observada', $correlation['label']);
        $this->assertSame([], $correlation['factors']);
        $this->assertSame([], $correlation['evidence_ids']);
        $this->assertContains(
            'Nenhum fator de correlação casou com os dados observados; ausência de correspondência não prova inexistência.',
            $correlation['limitations'],
        );
    }

    public function test_duplicated_results_do_not_add_score(): void
    {
        $run = $this->makeRun(['username' => 'joao'], [
            ['value' => 'joao', 'url' => 'https://github.com/joao'],
            ['value' => 'joao', 'url' => 'https://github.com/joao', 'metadata' => ['duplicate_of' => 1]],
        ]);

        $correlation = app(CorrelationService::class)->correlate($run);

        $this->assertSame(40, $correlation['score']);
        $this->assertSame(1, $correlation['observed_results']);
    }

    public function test_uses_only_observed_data_and_is_deterministic(): void
    {
        $run = $this->makeRun(['username' => 'joao', 'name' => 'Maria Souza'], [
            ['value' => 'joao', 'url' => 'https://github.com/joao', 'title' => 'João Silva'],
        ]);

        $first = app(CorrelationService::class)->correlate($run);
        $second = app(CorrelationService::class)->correlate($run);

        // Nome informado não aparece no dado observado => fator não casa.
        $this->assertSame(['same_username'], array_column($first['factors'], 'type'));
        $this->assertSame(40, $first['score']);

        // Determinístico, exceto o instante da avaliação.
        unset($first['evaluated_at'], $second['evaluated_at']);
        $this->assertSame($first, $second);
    }
}

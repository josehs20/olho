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
        $this->assertSame('v1', $run->metadata['correlation']['rule_version']);
        $this->assertNotEmpty($run->metadata['correlation']['signals']);
        $this->assertNotEmpty($run->metadata['correlation']['evidence_ids']);
    }
}

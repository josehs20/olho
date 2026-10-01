<?php

namespace Tests\Feature;

use App\Jobs\BuildHtmlReportJob;
use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\User;
use App\Services\CorrelationService;
use App\Services\InvestigationOrchestrator;
use App\Services\LogSanitizer;
use App\Services\ReportManifestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private function setupReport(): array
    {
        $user = User::factory()->create();
        $investigation = Investigation::factory()->create(['user_id' => $user->id]);
        $run = InvestigationRun::factory()->create([
            'investigation_id' => $investigation->id,
            'input_snapshot' => ['username' => 'joao'],
            'metadata' => [
                'correlation' => [
                    'score' => 40,
                    'rule_version' => 'v1',
                    'signals' => [],
                    'label' => 'possível correspondência',
                ],
            ],
        ]);

        $source = SourceDefinition::factory()->create(['slug' => 's', 'name' => 'Maigret']);
        $sourceRun = $run->sourceRuns()->create(['source_definition_id' => $source->id, 'status' => 'completed']);
        $sourceRun->sourceResults()->create([
            'source_definition_id' => $source->id,
            'entity_type' => 'social_profile',
            'value' => 'joao',
            'url' => 'https://github.com/joao',
            'title' => 'GitHub joao',
            'confidence' => 0.9,
        ]);

        return [$user, $investigation, $run];
    }

    public function test_user_can_view_own_report(): void
    {
        [$user, $investigation, $run] = $this->setupReport();

        $response = $this->actingAs($user)->get("/investigations/{$investigation->id}/runs/{$run->id}/report");

        $response->assertOk();
        $response->assertSee('RESUMO');
        $response->assertSee('ENTRADAS INFORMADAS');
        $response->assertSee('GitHub joao');
        $response->assertSee('possível correspondência');
    }

    public function test_user_cannot_view_another_users_report(): void
    {
        [$user, $investigation, $run] = $this->setupReport();
        $other = User::factory()->create();

        $this->actingAs($other)
            ->get("/investigations/{$investigation->id}/runs/{$run->id}/report")
            ->assertForbidden();
    }

    public function test_report_shows_sources_status_evidence_and_observation_time(): void
    {
        [$user, $investigation, $run] = $this->setupReport();

        $response = $this->actingAs($user)->get("/investigations/{$investigation->id}/runs/{$run->id}/report");

        $response->assertOk();
        // Identificadores pesquisados e fontes consultadas com status.
        $response->assertSee('ENTRADAS INFORMADAS');
        $response->assertSee('username');
        $response->assertSee('joao');
        $response->assertSee('FONTES CONSULTADAS');
        $response->assertSee('Maigret');
        $response->assertSee('s');
        // Descoberta com URL, data/hora de observação e evidência.
        $response->assertSee('GitHub joao');
        $response->assertSee('https://github.com/joao');
        $response->assertSee('possível correspondência');
        // Limitações sempre declaradas.
        $response->assertSee('Este relatório apresenta sinais públicos compatíveis');
    }

    public function test_report_does_not_hide_failed_or_skipped_sources(): void
    {
        [$user, $investigation, $run] = $this->setupReport();

        $definition = SourceDefinition::factory()->create(['slug' => 'sherlock', 'name' => 'Sherlock']);
        $run->sourceRuns()->create([
            'source_definition_id' => $definition->id,
            'source' => 'sherlock',
            'status' => 'timeout',
            'error_code' => 'timeout',
            'error_message_public' => 'A execução excedeu o tempo limite.',
        ]);

        $skipped = SourceDefinition::factory()->create(['slug' => 'holehe', 'name' => 'Holehe']);
        $run->sourceRuns()->create([
            'source_definition_id' => $skipped->id,
            'source' => 'holehe',
            'status' => 'skipped',
            'error_code' => 'invalid_input',
            'error_message_public' => "A fonte 'Holehe' não é aplicável aos dados informados.",
        ]);

        $response = $this->actingAs($user)->get("/investigations/{$investigation->id}/runs/{$run->id}/report");

        $response->assertOk();
        $response->assertSee('FALHAS E LIMITAÇÕES');
        $response->assertSee('fonte indisponível');
        $response->assertSee('A execução excedeu o tempo limite.');
        $response->assertSee('não é aplicável aos dados informados');
        // Aviso obrigatório do módulo de e-mail.
        $response->assertSee('não prova que a pessoa investigada controla aquela conta');
    }

    public function test_report_shows_correlation_factors_with_explanations_and_limitations(): void
    {
        [$user, $investigation, $run] = $this->setupReport();

        app(CorrelationService::class)->persist($run);

        $response = $this->actingAs($user)->get("/investigations/{$investigation->id}/runs/{$run->id}/report");

        $response->assertOk();
        $response->assertSee('Fator');
        $response->assertSee('same_username');
        $response->assertSee('+40');
        $response->assertSee('Username é escolhido pelo titular');
        $response->assertSee('não é probabilidade de identidade');
    }

    public function test_report_manifest_is_persisted_and_freezes_the_run(): void
    {
        [$user, $investigation, $run] = $this->setupReport();

        (new BuildHtmlReportJob($run->id))->handle(
            app(InvestigationOrchestrator::class),
            app(LogSanitizer::class),
            app(ReportManifestBuilder::class),
        );

        $report = $run->refresh()->report;

        $this->assertNotNull($report);
        $this->assertSame(ReportManifestBuilder::VERSION, $report->metadata['version']);
        $this->assertNotEmpty($report->content_hash);
        $this->assertSame('joao', $report->metadata['identifiers']['username']);
        $this->assertSame(1, $report->metadata['summary']['sources_completed']);
        $this->assertSame(1, $report->metadata['summary']['discoveries_total']);
        $this->assertSame('Maigret', $report->metadata['sources'][0]['name']);
        $this->assertSame($report->content_hash, app(ReportManifestBuilder::class)->contentHash($report->metadata));

        // Renomear o módulo depois não altera silenciosamente o relatório emitido.
        SourceDefinition::query()->where('slug', 's')->update(['name' => 'Maigret Renomeado']);

        $this->actingAs($user)
            ->get("/investigations/{$investigation->id}/runs/{$run->id}/report")
            ->assertOk()
            ->assertSee('Maigret')
            ->assertDontSee('Maigret Renomeado');
    }
}

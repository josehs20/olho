<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\User;
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
        $response->assertSee('Resumo');
        $response->assertSee('Entradas informadas');
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
}

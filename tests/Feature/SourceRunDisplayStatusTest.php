<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\SourceRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceRunDisplayStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeSourceRun(string $status, array $results = []): SourceRun
    {
        $user = User::factory()->create();
        $investigation = Investigation::factory()->create(['user_id' => $user->id]);
        $run = InvestigationRun::factory()->create(['investigation_id' => $investigation->id]);
        $source = SourceDefinition::factory()->create(['slug' => 's']);
        $sourceRun = $run->sourceRuns()->create(['source_definition_id' => $source->id, 'status' => $status]);

        foreach ($results as $result) {
            $sourceRun->sourceResults()->create(array_merge([
                'source_definition_id' => $source->id,
                'entity_type' => 'social_profile',
                'value' => 'joao',
            ], $result));
        }

        return $sourceRun;
    }

    public function test_completed_with_strong_confidence_is_found(): void
    {
        $this->assertSame('encontrado', $this->makeSourceRun('completed', [['confidence' => 0.9]])->displayStatus());
    }

    public function test_completed_with_low_confidence_is_possible_match(): void
    {
        $this->assertSame('possível correspondência', $this->makeSourceRun('completed', [['confidence' => 0.5]])->displayStatus());
    }

    public function test_completed_without_results_is_no_result(): void
    {
        $this->assertSame('sem resultado', $this->makeSourceRun('completed')->displayStatus());
    }

    public function test_failed_is_unavailable(): void
    {
        $this->assertSame('fonte indisponível', $this->makeSourceRun('failed')->displayStatus());
    }

    public function test_timeout_is_unavailable(): void
    {
        $this->assertSame('fonte indisponível', $this->makeSourceRun('timeout')->displayStatus());
    }

    public function test_pending_is_not_searched(): void
    {
        $this->assertSame('não pesquisado', $this->makeSourceRun('pending')->displayStatus());
    }
}

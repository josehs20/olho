<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvidenceEscapingTest extends TestCase
{
    use RefreshDatabase;

    public function test_result_title_is_escaped(): void
    {
        $user = User::factory()->create();
        $investigation = Investigation::factory()->create(['user_id' => $user->id]);
        $run = InvestigationRun::factory()->create([
            'investigation_id' => $investigation->id,
            'input_snapshot' => ['username' => 'joao'],
        ]);
        $source = SourceDefinition::factory()->create(['slug' => 's', 'name' => 'Maigret']);
        $sourceRun = $run->sourceRuns()->create(['source_definition_id' => $source->id, 'status' => 'completed']);

        $sourceRun->sourceResults()->create([
            'source_definition_id' => $source->id,
            'entity_type' => 'social_profile',
            'value' => 'joao',
            'url' => 'https://github.com/joao',
            'title' => '<script>alert("xss")</script>',
            'confidence' => 0.9,
        ]);

        $response = $this->actingAs($user)->get('/investigations/'.$investigation->id);

        $response->assertOk();
        $response->assertDontSee('<script>alert("xss")</script>', false);
        $response->assertSee('&lt;script&gt;', false);
    }
}

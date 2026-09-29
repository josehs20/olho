<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\User;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestigationServiceTest extends TestCase
{
    use RefreshDatabase;

    private InvestigationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InvestigationService::class);
    }

    public function test_create_investigation_and_run_with_snapshot(): void
    {
        $user = User::factory()->create();
        SourceDefinition::factory()->create(['slug' => 'maigret-mock', 'type' => 'username']);

        $investigation = $this->service->create($user, ['username' => 'joaosilva', 'name' => 'João'], ['maigret-mock']);

        $this->assertSame(Investigation::STATUS_QUEUED, $investigation->status);
        $this->assertNotNull($investigation->current_run_id);
        $this->assertSame(['maigret-mock'], $investigation->selected_modules);

        $run = $investigation->runs()->first();
        $this->assertSame(InvestigationRun::STATUS_PENDING, $run->status);
        $this->assertSame('joaosilva', $run->input_snapshot['username']);
        $this->assertSame(['maigret-mock'], $run->input_snapshot['modules']);
    }

    public function test_create_requires_at_least_one_module(): void
    {
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($user, ['username' => 'joaosilva'], []);
    }

    public function test_create_rejects_inactive_module(): void
    {
        $user = User::factory()->create();
        SourceDefinition::factory()->create(['slug' => 'inactive', 'type' => 'username', 'active' => false]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($user, ['username' => 'joaosilva'], ['inactive']);
    }

    public function test_create_validates_required_input_per_module(): void
    {
        $user = User::factory()->create();
        SourceDefinition::factory()->create(['slug' => 'holehe-mock', 'type' => 'email']);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($user, ['username' => 'joaosilva'], ['holehe-mock']);
    }

    public function test_history_isolates_between_users(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        SourceDefinition::factory()->create(['slug' => 'm', 'type' => 'username']);

        $this->service->create($userA, ['username' => 'a'], ['m']);
        $this->service->create($userB, ['username' => 'b'], ['m']);

        $historyA = $this->service->history($userA);
        $this->assertSame(1, $historyA->total());
        $this->assertSame('a', $historyA->first()->currentRun->input_snapshot['username']);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\StartInvestigationRunJob;
use App\Models\SourceDefinition;
use App\Models\User;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InvestigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/investigations')->assertRedirect(route('login'));
    }

    public function test_create_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/investigations/create')->assertOk();
    }

    public function test_user_can_create_investigation(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        SourceDefinition::factory()->create(['slug' => 'm', 'type' => 'username']);

        $response = $this->actingAs($user)->post('/investigations', [
            'username' => 'joaosilva',
            'modules' => ['m'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('investigations', ['user_id' => $user->id, 'status' => 'queued']);
        Queue::assertPushed(StartInvestigationRunJob::class);
    }

    public function test_user_cannot_view_another_users_investigation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        SourceDefinition::factory()->create(['slug' => 'm', 'type' => 'username']);

        $investigation = app(InvestigationService::class)->create($userA, ['username' => 'a'], ['m']);

        $this->actingAs($userB)->get('/investigations/'.$investigation->id)->assertForbidden();
    }
}

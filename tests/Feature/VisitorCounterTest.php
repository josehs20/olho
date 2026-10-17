<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitorCounterTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_html_page_view_is_recorded(): void
    {
        $this->get('/')->assertOk();

        $visit = Visit::query()->where('date', now()->toDateString())->first();

        $this->assertNotNull($visit);
        $this->assertSame(1, (int) $visit->page_views);
        $this->assertSame(1, (int) $visit->unique_visitors);
    }

    public function test_admin_navigation_is_not_counted_as_site_visit(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin')->assertOk();

        $this->assertSame(0, Visit::query()->count());
    }

    public function test_admin_dashboard_shows_visitor_counts(): void
    {
        $admin = User::factory()->admin()->create();

        Visit::query()->create([
            'date' => now()->toDateString(),
            'page_views' => 42,
            'unique_visitors' => 7,
        ]);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Visitas ao site')
            ->assertSee('42')
            ->assertSee('7');
    }
}

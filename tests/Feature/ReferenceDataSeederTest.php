<?php

namespace Tests\Feature;

use App\Models\CreditPackage;
use App\Models\SourceDefinition;
use App\Sources\SourceRegistry;
use Database\Seeders\CreditPackageSeeder;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_definitions_cover_every_registered_adapter(): void
    {
        $this->seed(SourceDefinitionSeeder::class);

        $registry = app(SourceRegistry::class);

        foreach ($registry->all() as $source) {
            $this->assertDatabaseHas('source_definitions', [
                'slug' => $source->key(),
                'name' => $source->name(),
                'active' => true,
            ]);
        }

        $this->assertSame(count($registry->all()), SourceDefinition::query()->count());
    }

    public function test_source_types_drive_required_input(): void
    {
        $this->seed(SourceDefinitionSeeder::class);

        $this->assertSame('username', SourceDefinition::query()->where('slug', 'maigret')->value('type'));
        $this->assertSame('username', SourceDefinition::query()->where('slug', 'sherlock')->value('type'));
        $this->assertSame('username', SourceDefinition::query()->where('slug', 'whatsmyname')->value('type'));
        $this->assertSame('email', SourceDefinition::query()->where('slug', 'holehe')->value('type'));
        $this->assertSame('email', SourceDefinition::query()->where('slug', 'mock-email')->value('type'));
        $this->assertSame('web', SourceDefinition::query()->where('slug', 'mock-web')->value('type'));

        $this->assertGreaterThan(0, (int) SourceDefinition::query()->where('slug', 'maigret')->value('cost_credits'));
    }

    public function test_credit_packages_are_created(): void
    {
        $this->seed(CreditPackageSeeder::class);

        $this->assertSame(3, CreditPackage::query()->where('kind', CreditPackage::KIND_CREDITS)->count());
        $this->assertSame(1, CreditPackage::query()->where('kind', CreditPackage::KIND_GUEST_INVESTIGATION)->count());
        $this->assertSame(3, CreditPackage::query()->where('kind', CreditPackage::KIND_CREDITS)->where('active', true)->count());
        $this->assertSame(1, CreditPackage::query()->where('kind', CreditPackage::KIND_GUEST_INVESTIGATION)->where('active', true)->count());
        $this->assertDatabaseHas('credit_packages', ['name' => 'Básico — 100 créditos', 'credits' => 100]);
    }

    public function test_seeders_are_idempotent_and_preserve_admin_toggles(): void
    {
        $this->seed(SourceDefinitionSeeder::class);
        $this->seed(CreditPackageSeeder::class);

        $source = SourceDefinition::query()->where('slug', 'maigret')->firstOrFail();
        $source->active = false;
        $source->save();

        $package = CreditPackage::query()->firstOrFail();
        $package->active = false;
        $package->save();

        $this->seed(SourceDefinitionSeeder::class);
        $this->seed(CreditPackageSeeder::class);

        $this->assertSame(count(app(SourceRegistry::class)->all()), SourceDefinition::query()->count());
        $this->assertSame(3, CreditPackage::query()->where('kind', CreditPackage::KIND_CREDITS)->count());
        $this->assertSame(1, CreditPackage::query()->where('kind', CreditPackage::KIND_GUEST_INVESTIGATION)->count());

        // Reexecutar a seed não reativa o que o admin desativou.
        $this->assertFalse($source->fresh()->active);
        $this->assertFalse($package->fresh()->active);
    }
}

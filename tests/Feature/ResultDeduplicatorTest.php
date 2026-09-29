<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\SourceResult;
use App\Models\User;
use App\Services\ResultDeduplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultDeduplicatorTest extends TestCase
{
    use RefreshDatabase;

    private function createResult(int $sourceRunId, int $sourceDefinitionId, string $url, string $value = 'joao', string $entityType = 'social_profile'): SourceResult
    {
        return SourceResult::factory()->create([
            'source_run_id' => $sourceRunId,
            'source_definition_id' => $sourceDefinitionId,
            'entity_type' => $entityType,
            'value' => $value,
            'url' => $url,
        ]);
    }

    private function makeRun(array $slugs): array
    {
        $user = User::factory()->create();
        $investigation = Investigation::factory()->create(['user_id' => $user->id]);
        $run = InvestigationRun::factory()->create(['investigation_id' => $investigation->id]);

        $sourceRuns = [];
        foreach ($slugs as $slug) {
            $definition = SourceDefinition::factory()->create(['slug' => $slug]);
            $sourceRuns[$slug] = $run->sourceRuns()->create([
                'source_definition_id' => $definition->id,
                'status' => 'completed',
            ]);
        }

        return [$run, $sourceRuns];
    }

    public function test_groups_exact_duplicates_across_sources(): void
    {
        [$run, $sourceRuns] = $this->makeRun(['maigret', 'sherlock']);

        $a = $this->createResult($sourceRuns['maigret']->id, $sourceRuns['maigret']->source_definition_id, 'https://github.com/joao');
        $b = $this->createResult($sourceRuns['sherlock']->id, $sourceRuns['sherlock']->source_definition_id, 'https://github.com/joao');

        app(ResultDeduplicator::class)->deduplicate($run);

        $a->refresh();
        $b->refresh();

        $this->assertSame($a->id, $b->metadata['duplicate_of']);
        $this->assertSame(['maigret', 'sherlock'], $a->metadata['dedup_sources']);
    }

    public function test_groups_near_duplicates(): void
    {
        [$run, $sourceRuns] = $this->makeRun(['maigret', 'sherlock']);

        $a = $this->createResult($sourceRuns['maigret']->id, $sourceRuns['maigret']->source_definition_id, 'https://github.com/joao');
        $b = $this->createResult($sourceRuns['sherlock']->id, $sourceRuns['sherlock']->source_definition_id, 'https://github.com/joao/#fragment');

        app(ResultDeduplicator::class)->deduplicate($run);

        $b->refresh();

        $this->assertSame($a->id, $b->metadata['duplicate_of']);
    }

    public function test_keeps_distinct_items_separate(): void
    {
        [$run, $sourceRuns] = $this->makeRun(['maigret', 'sherlock']);

        $a = $this->createResult($sourceRuns['maigret']->id, $sourceRuns['maigret']->source_definition_id, 'https://github.com/joao');
        $b = $this->createResult($sourceRuns['sherlock']->id, $sourceRuns['sherlock']->source_definition_id, 'https://instagram.com/joao');

        app(ResultDeduplicator::class)->deduplicate($run);

        $a->refresh();
        $b->refresh();

        $this->assertArrayNotHasKey('duplicate_of', $a->metadata ?? []);
        $this->assertArrayNotHasKey('duplicate_of', $b->metadata ?? []);
    }

    public function test_does_not_delete_duplicate_evidence(): void
    {
        [$run, $sourceRuns] = $this->makeRun(['maigret', 'sherlock']);

        $this->createResult($sourceRuns['maigret']->id, $sourceRuns['maigret']->source_definition_id, 'https://github.com/joao');
        $this->createResult($sourceRuns['sherlock']->id, $sourceRuns['sherlock']->source_definition_id, 'https://github.com/joao');

        app(ResultDeduplicator::class)->deduplicate($run);

        $this->assertSame(2, SourceResult::count());
    }

    public function test_canonical_url(): void
    {
        $dedup = app(ResultDeduplicator::class);

        $this->assertSame('https://example.com/path', $dedup->canonicalUrl('https://Example.com/path/'));
        $this->assertSame('https://example.com/path', $dedup->canonicalUrl('https://example.com/path#frag'));
        $this->assertSame('', $dedup->canonicalUrl(null));
    }
}

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

    public function test_canonical_url_normalizes_www_host(): void
    {
        $dedup = app(ResultDeduplicator::class);

        $this->assertSame(
            $dedup->canonicalUrl('https://github.com/joao'),
            $dedup->canonicalUrl('https://www.github.com/joao/'),
        );
    }

    public function test_canonical_url_ignores_scheme_tracking_and_parameter_order(): void
    {
        $dedup = app(ResultDeduplicator::class);

        $this->assertSame(
            'https://example.com/perfil?b=2&id=7',
            $dedup->canonicalUrl('http://example.com/perfil/?id=7&b=2&utm_source=x#bio'),
        );

        $this->assertSame(
            $dedup->canonicalUrl('https://example.com/p?id=7'),
            $dedup->canonicalUrl('https://www.example.com/p?utm_campaign=launch&id=7&fbclid=abc'),
        );
    }

    public function test_key_parts_are_explicit_and_auditable(): void
    {
        [$run, $sourceRuns] = $this->makeRun(['maigret']);

        $result = $this->createResult(
            $sourceRuns['maigret']->id,
            $sourceRuns['maigret']->source_definition_id,
            'https://www.github.com/Joao',
        );

        $dedup = app(ResultDeduplicator::class);
        $explained = $dedup->explain($result->toSourceResult());

        $this->assertSame(ResultDeduplicator::KEY_VERSION, $explained['version']);
        $this->assertSame('social_profile', $explained['parts']['entity_type']);
        $this->assertSame('github.com', $explained['parts']['platform']);
        $this->assertSame('joao', $explained['parts']['identifier']);
        $this->assertSame('https://github.com/joao', $explained['parts']['url']);
        $this->assertSame($explained['hash'], $dedup->dedupHash($result->toSourceResult()));
    }

    public function test_platform_falls_back_to_metadata_when_url_is_absent(): void
    {
        $dedup = app(ResultDeduplicator::class);

        $holehe = new \App\Sources\SourceResult(
            source: 'holehe',
            entityType: 'email_account',
            value: 'alvo@example.test',
            metadata: ['domain' => 'GitHub.com', 'site' => 'github'],
        );

        $this->assertSame('github.com', $dedup->platform($holehe));

        // Mesmo username, plataformas diferentes e sem URL: não são a mesma descoberta.
        $a = $dedup->dedupHash(new \App\Sources\SourceResult(source: 's', entityType: 'social_profile', value: 'joao', metadata: ['platform' => 'github.com']));
        $b = $dedup->dedupHash(new \App\Sources\SourceResult(source: 's', entityType: 'social_profile', value: 'joao', metadata: ['platform' => 'instagram.com']));

        $this->assertNotSame($a, $b);
    }

    public function test_consolidated_discovery_lists_all_observations(): void
    {
        [$run, $sourceRuns] = $this->makeRun(['maigret', 'sherlock']);

        $representative = $this->createResult($sourceRuns['maigret']->id, $sourceRuns['maigret']->source_definition_id, 'https://github.com/joao');
        $duplicate = $this->createResult($sourceRuns['sherlock']->id, $sourceRuns['sherlock']->source_definition_id, 'https://www.github.com/joao/');

        foreach ([$representative, $duplicate] as $result) {
            $result->evidence()->create(['type' => 'username_match', 'value' => 'joao']);
        }

        app(ResultDeduplicator::class)->deduplicate($run);

        $representative->refresh();
        $observations = $representative->metadata['dedup_observations'];

        $this->assertSame(2, $representative->metadata['dedup_group_size']);
        $this->assertCount(2, $observations);
        $this->assertSame(['maigret', 'sherlock'], collect($observations)->pluck('source')->all());
        $this->assertSame(['username_match'], $observations[0]['evidence']);
        $this->assertSame(ResultDeduplicator::KEY_VERSION, $representative->metadata['dedup_key_version']);
        $this->assertSame($representative->dedup_hash, $duplicate->fresh()->metadata['duplicate_of_hash']);
    }

    public function test_deduplication_converges_when_run_twice(): void
    {
        [$run, $sourceRuns] = $this->makeRun(['maigret', 'sherlock']);

        $this->createResult($sourceRuns['maigret']->id, $sourceRuns['maigret']->source_definition_id, 'https://github.com/joao');
        $this->createResult($sourceRuns['sherlock']->id, $sourceRuns['sherlock']->source_definition_id, 'https://github.com/joao');

        $dedup = app(ResultDeduplicator::class);
        $dedup->deduplicate($run);
        $dedup->deduplicate($run);

        $representatives = SourceResult::query()->whereNotNull('metadata->dedup_sources')->get();

        $this->assertCount(1, $representatives);
        $this->assertCount(2, $representatives->first()->metadata['dedup_observations']);
        $this->assertSame(2, SourceResult::count());
    }

    public function test_legacy_hash_version_is_recomputed(): void
    {
        [$run, $sourceRuns] = $this->makeRun(['maigret']);

        $legacy = $this->createResult($sourceRuns['maigret']->id, $sourceRuns['maigret']->source_definition_id, 'https://github.com/joao');
        $legacy->dedup_hash = hash('sha256', 'chave-antiga-v1');
        $legacy->metadata = ['site' => 'GitHub'];
        $legacy->save();

        app(ResultDeduplicator::class)->deduplicate($run);

        $legacy->refresh();

        $this->assertSame(ResultDeduplicator::KEY_VERSION, $legacy->metadata['dedup_key_version']);
        $this->assertSame('github.com', $legacy->metadata['platform']);
        $this->assertSame(
            app(ResultDeduplicator::class)->dedupHash($legacy->toSourceResult()),
            $legacy->dedup_hash,
        );
        $this->assertNotSame(hash('sha256', 'chave-antiga-v1'), $legacy->dedup_hash);
    }

    public function test_groups_www_and_non_www_discoveries(): void
    {
        [$run, $sourceRuns] = $this->makeRun(['maigret', 'sherlock']);

        $a = $this->createResult($sourceRuns['maigret']->id, $sourceRuns['maigret']->source_definition_id, 'https://github.com/joao');
        $b = $this->createResult($sourceRuns['sherlock']->id, $sourceRuns['sherlock']->source_definition_id, 'https://www.github.com/joao');

        app(ResultDeduplicator::class)->deduplicate($run);

        $a->refresh();
        $b->refresh();

        $this->assertSame($a->id, $b->metadata['duplicate_of']);
        $this->assertSame(['maigret', 'sherlock'], $a->metadata['dedup_sources']);
    }
}

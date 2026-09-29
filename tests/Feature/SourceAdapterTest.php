<?php

namespace Tests\Feature;

use App\Sources\Exceptions\SourceTimeoutException;
use App\Sources\Exceptions\SourceUnavailableException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\Mocks\MockEmailSource;
use App\Sources\Mocks\MockUsernameSource;
use App\Sources\Mocks\MockWebSource;
use App\Sources\SourceErrorMapper;
use App\Sources\SourceRegistry;
use App\Sources\SourceRunStatus;
use Tests\TestCase;

class SourceAdapterTest extends TestCase
{
    public function test_registry_contains_mock_sources(): void
    {
        $registry = app(SourceRegistry::class);

        $this->assertInstanceOf(MockUsernameSource::class, $registry->get('mock-username'));
        $this->assertInstanceOf(MockEmailSource::class, $registry->get('mock-email'));
        $this->assertInstanceOf(MockWebSource::class, $registry->get('mock-web'));
    }

    public function test_registry_throws_for_unknown_source(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(SourceRegistry::class)->get('unknown');
    }

    public function test_registry_filters_supporting_sources(): void
    {
        $sources = app(SourceRegistry::class)->supporting(new InvestigationInput(username: 'joao'));

        $this->assertContains('mock-username', array_map(fn ($s) => $s->key(), $sources));
        $this->assertContains('mock-web', array_map(fn ($s) => $s->key(), $sources));
        $this->assertNotContains('mock-email', array_map(fn ($s) => $s->key(), $sources));
    }

    public function test_username_source_supports_username(): void
    {
        $source = new MockUsernameSource;

        $this->assertTrue($source->supports(new InvestigationInput(username: 'joao')));
        $this->assertFalse($source->supports(new InvestigationInput(email: 'a@b.c')));
    }

    public function test_username_source_returns_deterministic_results(): void
    {
        $results = (new MockUsernameSource)->execute(new InvestigationContext(new InvestigationInput(username: 'joao')));

        $this->assertCount(2, $results);
        $this->assertSame('social_profile', $results[0]->entityType);
        $this->assertSame('joao', $results[0]->value);
    }

    public function test_username_source_returns_empty(): void
    {
        $results = (new MockUsernameSource)->execute(new InvestigationContext(new InvestigationInput(username: 'empty')));

        $this->assertSame([], $results);
    }

    public function test_username_source_throws_timeout(): void
    {
        $this->expectException(SourceTimeoutException::class);

        (new MockUsernameSource)->execute(new InvestigationContext(new InvestigationInput(username: 'timeout')));
    }

    public function test_username_source_throws_error(): void
    {
        $this->expectException(SourceUnavailableException::class);

        (new MockUsernameSource)->execute(new InvestigationContext(new InvestigationInput(username: 'error')));
    }

    public function test_error_mapper_maps_exceptions(): void
    {
        $this->assertSame('timeout', SourceErrorMapper::toErrorCode(new SourceTimeoutException('t')));
        $this->assertSame(SourceRunStatus::Timeout, SourceErrorMapper::toRunStatus(new SourceTimeoutException('t')));

        $this->assertSame('unavailable', SourceErrorMapper::toErrorCode(new SourceUnavailableException('e')));
        $this->assertSame(SourceRunStatus::Failed, SourceErrorMapper::toRunStatus(new SourceUnavailableException('e')));

        $this->assertSame('error', SourceErrorMapper::toErrorCode(new \RuntimeException('x')));
        $this->assertSame(SourceRunStatus::Failed, SourceErrorMapper::toRunStatus(new \RuntimeException('x')));
    }

    public function test_email_and_web_sources_execute(): void
    {
        $email = new MockEmailSource;
        $this->assertTrue($email->supports(new InvestigationInput(email: 'a@b.c')));
        $emailResults = $email->execute(new InvestigationContext(new InvestigationInput(email: 'a@b.c')));
        $this->assertCount(1, $emailResults);
        $this->assertSame('email_account', $emailResults[0]->entityType);

        $web = new MockWebSource;
        $this->assertTrue($web->supports(new InvestigationInput(name: 'João')));
        $webResults = $web->execute(new InvestigationContext(new InvestigationInput(name: 'João')));
        $this->assertCount(1, $webResults);
        $this->assertSame('web_page', $webResults[0]->entityType);
    }

    public function test_normalize_maps_raw_to_result(): void
    {
        $result = (new MockUsernameSource)->normalize([
            'entity_type' => 'social_profile',
            'value' => 'joao',
            'url' => 'https://example.com/joao',
        ]);

        $this->assertSame('mock-username', $result->source);
        $this->assertSame('joao', $result->value);
    }
}

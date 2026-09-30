<?php

namespace Tests\Unit;

use App\Sources\Adapters\Maigret\MaigretReportParser;
use PHPUnit\Framework\TestCase;

class MaigretReportParserTest extends TestCase
{
    private MaigretReportParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new MaigretReportParser;
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = __DIR__.'/../Fixtures/maigret/report_python_simple.json';

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_parses_maigret_report_into_contract_results(): void
    {
        $results = $this->parser->parse($this->fixture());

        // A entrada sem URL válida é descartada.
        $this->assertCount(2, $results);

        $github = $results[0];

        $this->assertSame('maigret', $github->source);
        $this->assertSame('social_profile', $github->entityType);
        $this->assertSame('python', $github->value);
        $this->assertSame('https://github.com/python', $github->url);
        $this->assertSame('GitHub', $github->title);
        $this->assertSame(0.8, $github->confidence);
        $this->assertSame([['type' => 'public_profile_url', 'value' => 'https://github.com/python']], $github->evidence);
        $this->assertSame('GitHub', $github->metadata['site']);
        $this->assertSame(['business', 'coding', 'networking'], $github->metadata['tags']);
        $this->assertSame(200, $github->metadata['http_status']);
        $this->assertSame(10, $github->metadata['rank']);
        $this->assertSame('maigret', $github->metadata['tool']);
    }

    public function test_skips_entries_without_a_valid_profile_url(): void
    {
        $result = $this->parser->toSourceResult('SemUrl', ['username' => 'python', 'url_user' => 'not-a-url']);

        $this->assertNull($result);
    }

    public function test_ignores_malformed_report_structure(): void
    {
        $results = $this->parser->parse([
            'Scam' => 'not-an-array',
            'GitHub' => ['url_user' => 'https://github.com/python'],
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('GitHub', $results[0]->title);
    }
}

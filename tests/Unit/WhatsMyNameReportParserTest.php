<?php

namespace Tests\Unit;

use App\Sources\Adapters\WhatsMyName\WhatsMyNameReportParser;
use PHPUnit\Framework\TestCase;

class WhatsMyNameReportParserTest extends TestCase
{
    private WhatsMyNameReportParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new WhatsMyNameReportParser;
    }

    public function test_parses_found_entries_into_contract_results(): void
    {
        $results = $this->parser->parse([
            [
                'site' => 'GitHub',
                'url' => 'https://github.com/python',
                'category' => 'coding',
                'http_status' => 200,
                'username' => 'python',
                'method' => 'GET',
            ],
        ]);

        $this->assertCount(1, $results);

        $github = $results[0];

        $this->assertSame('whatsmyname', $github->source);
        $this->assertSame('social_profile', $github->entityType);
        $this->assertSame('python', $github->value);
        $this->assertSame('https://github.com/python', $github->url);
        $this->assertSame('GitHub', $github->title);
        $this->assertSame(0.8, $github->confidence);
        $this->assertSame([['type' => 'public_profile_url', 'value' => 'https://github.com/python']], $github->evidence);
        $this->assertSame('GitHub', $github->metadata['site']);
        $this->assertSame('coding', $github->metadata['category']);
        $this->assertSame('GET', $github->metadata['method']);
        $this->assertSame(200, $github->metadata['http_status']);
        $this->assertSame('whatsmyname', $github->metadata['tool']);
    }

    public function test_discards_invalid_output_entries(): void
    {
        $results = $this->parser->parse([
            'not-an-array',
            ['site' => '', 'url' => 'https://x.test/user'],          // sem site
            ['site' => 'X', 'url' => 'not-a-url'],                    // URL inválida
            ['site' => 'X'],                                          // sem URL
        ]);

        $this->assertSame([], $results);
    }

    public function test_falls_back_to_url_when_username_is_absent(): void
    {
        $result = $this->parser->toSourceResult(['site' => 'X', 'url' => 'https://x.test/u']);

        $this->assertNotNull($result);
        $this->assertSame('https://x.test/u', $result->value);
        $this->assertArrayNotHasKey('http_status', $result->metadata);
    }
}

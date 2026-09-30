<?php

namespace Tests\Unit;

use App\Sources\Adapters\Sherlock\SherlockReportParser;
use PHPUnit\Framework\TestCase;

class SherlockReportParserTest extends TestCase
{
    private SherlockReportParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new SherlockReportParser;
    }

    private function csvFixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/sherlock/report_python.csv');
    }

    public function test_parses_sherlock_csv_into_contract_results(): void
    {
        $results = $this->parser->parseCsv($this->csvFixture());

        // A linha com `exists = Available` (username livre) é descartada.
        $this->assertCount(4, $results);

        $github = $results[0];

        $this->assertSame('sherlock', $github->source);
        $this->assertSame('social_profile', $github->entityType);
        $this->assertSame('python', $github->value);
        $this->assertSame('https://www.github.com/python', $github->url);
        $this->assertSame('GitHub', $github->title);
        $this->assertSame(0.8, $github->confidence);
        $this->assertSame([['type' => 'public_profile_url', 'value' => 'https://www.github.com/python']], $github->evidence);
        $this->assertSame('GitHub', $github->metadata['site']);
        $this->assertSame('sherlock', $github->metadata['tool']);
        $this->assertSame('Claimed', $github->metadata['exists']);
        $this->assertSame(200, $github->metadata['http_status']);
    }

    public function test_parses_partial_stdout_found_lines(): void
    {
        $output = "\n[+] GitHub: https://www.github.com/python\n"
            .'[+] Telegram: https://t.me/python'."\n"
            ."[*] Search completed with 2 results\n";

        $results = $this->parser->parseFoundLines($output, 'python');

        $this->assertCount(2, $results);
        $this->assertSame('GitHub', $results[0]->title);
        $this->assertSame('https://t.me/python', $results[1]->url);
        $this->assertSame('python', $results[1]->value);
    }

    public function test_skips_rows_without_valid_url_or_not_claimed(): void
    {
        $this->assertNull($this->parser->toSourceResult(['name' => 'X', 'url_user' => 'nope']));
        $this->assertNull($this->parser->toSourceResult([
            'name' => 'X',
            'url_user' => 'https://x.test/user',
            'exists' => 'Available',
        ]));
    }
}

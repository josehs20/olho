<?php

namespace Tests\Unit;

use App\Sources\Adapters\Holehe\HoleheReportParser;
use PHPUnit\Framework\TestCase;

class HoleheReportParserTest extends TestCase
{
    private HoleheReportParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new HoleheReportParser;
    }

    private function csvFixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/holehe/holehe_results.csv');
    }

    public function test_parses_only_rows_where_the_email_is_registered(): void
    {
        $results = $this->parser->parseCsv($this->csvFixture(), 'alvo@example.test');

        // Apenas `exists = True`; domínio inválido é descartado.
        $this->assertCount(2, $results);

        $github = $results[0];

        $this->assertSame('holehe', $github->source);
        $this->assertSame('email_account', $github->entityType);
        $this->assertSame('alvo@example.test', $github->value);
        $this->assertSame('https://github.com', $github->url);
        $this->assertSame('github.com', $github->title);
        $this->assertSame('github', $github->metadata['site']);
        $this->assertSame('register', $github->metadata['method']);
        $this->assertSame('public_signup_check', $github->metadata['signal']);

        // Sinal técnico fraco: não prova controle da conta.
        $this->assertSame(0.5, $github->confidence);
        $this->assertSame(
            [['type' => 'email_registration_indicator', 'value' => 'github.com']],
            $github->evidence,
        );
    }

    public function test_never_keeps_sensitive_recovery_columns(): void
    {
        $results = $this->parser->parseCsv($this->csvFixture(), 'alvo@example.test');
        $serialized = json_encode($results, JSON_THROW_ON_ERROR);

        // Colunas de recuperação de conta nunca entram no domínio.
        $this->assertStringNotContainsString('pa***@example.test', $serialized);
        $this->assertStringNotContainsString('+5511***1234', $serialized);
        $this->assertStringNotContainsString('secret@example.test', $serialized);

        foreach ($results as $result) {
            $this->assertArrayNotHasKey('emailrecovery', $result->metadata);
            $this->assertArrayNotHasKey('phoneNumber', $result->metadata);
        }
    }

    public function test_rejects_rows_without_usable_domain(): void
    {
        $this->assertNull($this->parser->toSourceResult(['exists' => 'True', 'domain' => 'not-a-domain'], 'a@b.test'));
        $this->assertNull($this->parser->toSourceResult(['exists' => 'True', 'domain' => ''], 'a@b.test'));
        $this->assertNull($this->parser->toSourceResult(['exists' => 'False', 'domain' => 'github.com'], 'a@b.test'));
    }
}

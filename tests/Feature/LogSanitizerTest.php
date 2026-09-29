<?php

namespace Tests\Feature;

use App\Services\LogSanitizer;
use Tests\TestCase;

class LogSanitizerTest extends TestCase
{
    public function test_sanitizes_sensitive_array_keys(): void
    {
        $sanitizer = app(LogSanitizer::class);

        $result = $sanitizer->sanitize([
            'user_id' => 1,
            'password' => 'secret123',
            'api_key' => 'abc',
            'nested' => ['token' => 'tok123'],
        ]);

        $this->assertSame(1, $result['user_id']);
        $this->assertSame('[REDACTED]', $result['password']);
        $this->assertSame('[REDACTED]', $result['api_key']);
        $this->assertSame('[REDACTED]', $result['nested']['token']);
    }

    public function test_sanitizes_pix_key_in_string(): void
    {
        $sanitizer = app(LogSanitizer::class);

        $result = $sanitizer->sanitize('FAKE_PIX|key=minha-chave-secreta|txid=123');

        $this->assertStringNotContainsString('minha-chave-secreta', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function test_leaves_safe_data_untouched(): void
    {
        $sanitizer = app(LogSanitizer::class);

        $this->assertSame('ok', $sanitizer->sanitize('ok'));
        $this->assertSame(123, $sanitizer->sanitize(123));
    }
}

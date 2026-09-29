<?php

namespace App\Services;

/**
 * Sanitiza dados antes de registrar em logs (remove segredos e conteúdo sensível).
 */
class LogSanitizer
{
    private const SENSITIVE_KEYS = [
        'password', 'senha', 'secret', 'token', 'api_key', 'apikey',
        'pix_key', 'authorization', 'cookie', 'credential',
    ];

    public function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->sanitizeArray($value);
        }

        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        return $value;
    }

    private function sanitizeArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = '[REDACTED]';

                continue;
            }

            $data[$key] = $this->sanitize($value);
        }

        return $data;
    }

    private function sanitizeString(string $value): string
    {
        $value = preg_replace('/PIX_KEY=[^|&\s]+/i', 'PIX_KEY=[REDACTED]', $value) ?? $value;
        $value = preg_replace('/\bkey=[^|&\s]+/i', 'key=[REDACTED]', $value) ?? $value;

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = mb_strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}

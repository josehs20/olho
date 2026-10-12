<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Acesso centralizado às configurações administrativas (chave/valor).
 *
 * Todas as leituras passam por aqui e são memorizadas em cache (e em memória no
 * escopo da requisição). Escritas invalidam o cache. Os valores nunca são
 * logados: quem registra eventos deve usar o `LogSanitizer`, que redige chaves
 * sensíveis (`token`, `secret`, `api_key`, ...).
 */
class SettingsService
{
    /** Chave de cache da coleção completa. */
    private const CACHE_KEY = 'settings.all';

    /**
     * Memória local do processo para evitar ler o cache a cada chamada.
     *
     * @var array<string, mixed>|null
     */
    private ?array $memo = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        return $this->memo = Cache::rememberForever(self::CACHE_KEY, function (): array {
            // `get()` aplica os casts do model (decifra o valor).
            return Setting::query()
                ->get(['key', 'value'])
                ->mapWithKeys(fn (Setting $setting): array => [$setting->key => $setting->value])
                ->all();
        });
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function getString(string $key, ?string $default = null): ?string
    {
        $value = $this->get($key, $default);

        return $value === null ? null : (string) $value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return ($value === null || $value === '') ? $default : (int) $value;
    }

    public function set(string $key, mixed $value): void
    {
        // O cast `encrypted` do `Setting` só é aplicado pela serialização do
        // modelo (Eloquent): uma atribuição em massa via query builder (`update`)
        // escreveria o valor em texto puro. Por isso a escrita é feita pelo
        // modelo hidratado (`save()`), não pelo builder (prompt 48: o
        // `sessionid` do Instagram nunca fica legível no banco).
        $setting = Setting::query()->firstOrNew(['key' => $key]);
        $setting->value = $value === null ? null : (string) $value;
        $setting->save();

        $this->flush();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $setting = Setting::query()->firstOrNew(['key' => (string) $key]);
            $setting->value = $value === null ? null : (string) $value;
            $setting->save();
        }

        $this->flush();
    }

    public function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();

        $this->flush();
    }

    /**
     * Limpa o cache (chamado após qualquer escrita e nos testes).
     */
    public function flush(): void
    {
        $this->memo = null;
        Cache::forget(self::CACHE_KEY);
    }
}

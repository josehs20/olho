<?php

namespace App\Sources\Adapters\WhatsMyName;

use App\Sources\Exceptions\SourceUnavailableException;
use Illuminate\Filesystem\Filesystem;

/**
 * Dataset oficial do WhatsMyName (`wmn-data.json`, CC BY-SA 4.0).
 *
 * Modo de execução atual do projeto upstream (verificado no prompt 34): os
 * checkers embutidos foram removidos em maio/2023 e o repositório mantém apenas
 * o dataset + o schema — as ferramentas externas é que fazem as verificações.
 * Por isso o adapter **pinned** o dataset na imagem e executa as checagens
 * seguindo o `wmn-data-schema.json` publicado, exatamente como fazem as
 * integrações conhecidas (SpiderFoot, transformações Maltego).
 */
class WhatsMyNameDataset
{
    public function __construct(private readonly Filesystem $files) {}

    /**
     * Carrega e valida o dataset; dados ausentes ou malformados são falha
     * estruturada da fonte (`unavailable`), não erro do run.
     *
     * @return array{revision: ?string, license: array<int, string>, authors: array<int, string>, sites: array<int, array<string, mixed>>}
     */
    public function load(string $path, ?string $revision = null): array
    {
        if (trim($path) === '' || ! $this->files->exists($path)) {
            throw new SourceUnavailableException('O dataset do WhatsMyName não está disponível nesta instalação.');
        }

        $decoded = json_decode((string) $this->files->get($path), true);

        if (! is_array($decoded) || ! isset($decoded['sites']) || ! is_array($decoded['sites'])) {
            throw new SourceUnavailableException('A saída do WhatsMyName está em formato inválido.');
        }

        $sites = array_values(array_filter($decoded['sites'], fn (mixed $site): bool => $this->isUsable($site)));

        if ($sites === []) {
            throw new SourceUnavailableException('A saída do WhatsMyName não contém sites verificáveis.');
        }

        return [
            'revision' => $revision ?: $this->revisionFromFile($path),
            'license' => $this->stringList($decoded['license'] ?? []),
            'authors' => $this->stringList($decoded['authors'] ?? []),
            'sites' => $sites,
        ];
    }

    /**
     * Seleciona os sites a verificar: nunca inclui categorias excluídas
     * (conteúdo adulto) e respeita o limite de disparos por execução.
     *
     * @param  array<int, array<string, mixed>>  $sites
     * @param  array<int, string>  $categories  vazio = todas as permitidas
     * @param  array<int, string>  $excludedCategories
     * @param  array<int, string>|null  $names  lista explícita de sites (opcional)
     * @return array<int, array<string, mixed>>
     */
    public function select(array $sites, array $categories, array $excludedCategories, ?array $names, int $max): array
    {
        $selected = array_values(array_filter($sites, function (array $site) use ($categories, $excludedCategories, $names): bool {
            $name = (string) ($site['name'] ?? '');
            $category = (string) ($site['cat'] ?? '');

            if (is_array($names) && $names !== [] && ! in_array($name, $names, true)) {
                return false;
            }

            if (in_array($category, $excludedCategories, true)) {
                return false;
            }

            return $categories === [] || in_array($category, $categories, true);
        }));

        return $max > 0 ? array_slice($selected, 0, $max) : $selected;
    }

    private function isUsable(mixed $site): bool
    {
        if (! is_array($site)
            || ! is_string($site['name'] ?? null)
            || ! is_string($site['uri_check'] ?? null)
            || ! isset($site['e_code'], $site['m_code'])
            || ! is_numeric($site['e_code'])
            || ! is_numeric($site['m_code'])) {
            return false;
        }

        // O placeholder pode estar na URL de verificação ou no corpo do POST
        // (ex.: sites com API GraphQL, como o AniList).
        return str_contains($site['uri_check'], '{account}')
            || (is_string($site['post_body'] ?? null) && str_contains($site['post_body'], '{account}'));
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private function revisionFromFile(string $path): ?string
    {
        $file = dirname($path).'/REVISION';

        if (! $this->files->exists($file)) {
            return null;
        }

        $revision = trim((string) $this->files->get($file));

        return $revision !== '' ? $revision : null;
    }
}

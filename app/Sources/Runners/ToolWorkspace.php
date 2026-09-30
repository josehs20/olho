<?php

namespace App\Sources\Runners;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use SplFileInfo;

/**
 * Diretório de trabalho isolado por execução de ferramenta (docs/21).
 *
 * Cada execução recebe um diretório próprio (0700), usado como `cwd` e `HOME`
 * do processo, e é removido ao final — inclusive quando a ferramenta falha.
 * Compartilhado pelos adapters reais (Maigret, Sherlock).
 */
class ToolWorkspace
{
    public function __construct(private readonly Filesystem $files) {}

    public function create(string $basePath): string
    {
        $path = rtrim($basePath, '/').'/'.Str::uuid()->toString();

        $this->files->ensureDirectoryExists($path, 0700, true);

        return $path;
    }

    /**
     * Primeiro arquivo do diretório cujo nome casa com o padrão (ex.: relatório
     * gerado pela ferramenta).
     */
    public function file(string $path, string $pattern): ?SplFileInfo
    {
        foreach ($this->files->files($path) as $file) {
            if (preg_match($pattern, $file->getFilename()) === 1) {
                return $file;
            }
        }

        return null;
    }

    public function cleanup(string $path): void
    {
        $this->files->deleteDirectory($path);
    }
}

<?php

namespace App\Sources\Runners;

use Illuminate\Support\Str;

/**
 * Leitura da saída textual das ferramentas, expondo apenas informação pública
 * (sem caminhos, comandos, credenciais ou detalhes internos).
 *
 * O aviso público sai **em português**: as ferramentas escrevem em inglês e esse
 * texto chega ao painel de testes, ao histórico da investigação e ao relatório
 * (ver `ToolWarningsTranslator`).
 */
final class ToolOutput
{
    /**
     * Avisos públicos da execução (linhas `[!] ...`), deduplicados, traduzidos e
     * limitados.
     *
     * @return array<int, string>
     */
    public static function publicWarnings(string $stdout, string $stderr = '', int $limit = 10): array
    {
        $warnings = [];

        foreach (preg_split('/\R/', $stdout."\n".$stderr) ?: [] as $line) {
            // Remove códigos ANSI antes de avaliar o prefixo.
            $clean = trim(preg_replace('/\e\[[0-9;]*m/', '', $line) ?? $line);

            if (str_starts_with($clean, '[!]')) {
                $warnings[] = Str::limit(ToolWarningsTranslator::translate(trim(substr($clean, 3))), 200, '');
            }
        }

        return array_slice(array_values(array_unique(array_filter($warnings))), 0, $limit);
    }
}

<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Pdo\Mysql;
use RuntimeException;
use Throwable;

/**
 * Dump lógico do banco MySQL/MariaDB para `database/backups/init.sql`.
 *
 * O destino é exatamente o caminho que o Dockerfile restaura no boot
 * (`/var/www/html/database/backups/init.sql`), então o arquivo gerado precisa
 * ser aplicável por `mariadb -u root <banco> < init.sql`:
 *
 *  - sem `CREATE DATABASE`/`USE` (o dump é aplicado no banco alvo);
 *  - `DROP TABLE IF EXISTS` antes de cada tabela (restauração idempotente);
 *  - `SET FOREIGN_KEY_CHECKS=0`/`UNIQUE_CHECKS=0` durante a carga;
 *  - `SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'` (mesma decisão do `mysqldump`:
 *    preserva ids iguais a zero sem cair no modo estrito do MySQL 8);
 *  - UTF-8 (`utf8mb4`) e valores escapados pelo próprio driver (PDO).
 *
 * Dados efêmeros de infraestrutura (`sessions`, `cache`, `jobs`, ...) não são
 * copiados: a estrutura é recriada, mas sem sessões antigas (tokens em claro),
 * cache obsoleto ou jobs pendentes que seriam reexecutados no deploy.
 *
 * A implementação é em PHP/PDO de propósito: a imagem de desenvolvimento não
 * instala `mysqldump`/`mariadb-dump`, e assim o painel funciona em qualquer
 * ambiente, sem colocar credenciais em linha de comando ou em processo filho.
 * A base é lida em *streaming* (consulta sem buffer), então o consumo de
 * memória não cresce com o volume de linhas.
 */
class DatabaseBackup
{
    /** Caminho relativo usado pelo Dockerfile (e exibido no painel). */
    public const RELATIVE_PATH = 'database/backups/init.sql';

    /** Máximo de linhas por `INSERT` (mantém o arquivo legível/diffável). */
    private const MAX_ROWS_PER_INSERT = 200;

    /** Teto aproximado de bytes por `INSERT`. */
    private const MAX_INSERT_BYTES = 262144;

    /** Tipos que precisam ser gravados como literal hexadecimal. */
    private const BINARY_TYPES = [
        'binary', 'varbinary',
        'tinyblob', 'blob', 'mediumblob', 'longblob',
        'bit',
        'geometry', 'point', 'linestring', 'polygon',
        'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection',
    ];

    /**
     * Tabelas de infraestrutura: a estrutura é restaurada, os dados não.
     *
     * `sessions` guarda payloads de sessão em claro (risco de sequestro de
     * sessão se o dump for versionado), e cache/fila são estados descartáveis.
     */
    private const SKIP_DATA_TABLES = [
        'cache',
        'cache_locks',
        'sessions',
        'jobs',
        'job_batches',
        'failed_jobs',
    ];

    public function path(): string
    {
        return database_path('backups/init.sql');
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function size(): ?int
    {
        if (! $this->exists()) {
            return null;
        }

        $size = filesize($this->path());

        return $size === false ? null : $size;
    }

    public function modifiedAt(): ?Carbon
    {
        if (! $this->exists()) {
            return null;
        }

        $timestamp = filemtime($this->path());

        return $timestamp === false ? null : Carbon::createFromTimestamp($timestamp);
    }

    /**
     * Gera o dump e substitui `init.sql` de forma atômica.
     *
     * O arquivo definitivo só é tocado quando o dump termina por completo: em
     * caso de falha, o temporário é removido e o dump anterior (se houver)
     * permanece intacto.
     *
     * @return array{path:string, bytes:int, tables:int, rows:int, duration_ms:int}
     */
    public function dump(): array
    {
        $startedAt = microtime(true);

        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException(sprintf(
                'O dump só é suportado para MySQL/MariaDB (conexão atual: %s).',
                $driver,
            ));
        }

        $directory = dirname($this->path());
        File::ensureDirectoryExists($directory);

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException(sprintf(
                'O diretório %s não é gravável pelo processo web. Ajuste as permissões do container.',
                self::RELATIVE_PATH,
            ));
        }

        $temporary = $directory.'/.init-'.bin2hex(random_bytes(8)).'.tmp';
        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo temporário do dump.');
        }

        $pdo = $connection->getPdo();
        $tables = 0;
        $rows = 0;
        $snapshot = false;
        $completed = false;

        try {
            // Snapshot consistente: workers seguem gravando enquanto lemos.
            $snapshot = $this->beginSnapshot($pdo);

            $this->writeHeader($handle, $connection);

            // Tabelas primeiro; views depois (uma view depende das tabelas).
            $objects = $this->objects($connection);

            foreach ($objects as $object) {
                if (strtoupper((string) $object->type) !== 'VIEW') {
                    $rows += $this->dumpTable($handle, $connection, (string) $object->name);
                    $tables++;
                }
            }

            foreach ($objects as $object) {
                if (strtoupper((string) $object->type) === 'VIEW') {
                    $this->dumpView($handle, $connection, (string) $object->name);
                }
            }

            fwrite($handle, "\n-- Fim do dump.\n");
            fwrite($handle, "SET SQL_MODE=@OLD_SQL_MODE;\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n");
            fwrite($handle, "SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;\n");

            fclose($handle);
            $completed = true;
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            @unlink($temporary);

            throw $exception;
        } finally {
            if ($snapshot) {
                try {
                    $pdo->exec($completed ? 'COMMIT' : 'ROLLBACK');
                } catch (Throwable) {
                    // A conexão é encerrada ao final da requisição; nada a fazer.
                }
            }
        }

        // Troca atômica: `init.sql` nunca fica truncado por um dump interrompido.
        if (! @rename($temporary, $this->path())) {
            @unlink($temporary);

            throw new RuntimeException('Não foi possível gravar o dump em '.self::RELATIVE_PATH.'.');
        }

        @chmod($this->path(), 0640);

        $bytes = filesize($this->path());

        return [
            'path' => $this->path(),
            'bytes' => $bytes === false ? 0 : (int) $bytes,
            'tables' => $tables,
            'rows' => $rows,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * @param  resource  $handle
     */
    private function writeHeader($handle, Connection $connection): void
    {
        $database = (string) $connection->getDatabaseName();
        $version = $connection->getServerVersion();
        $generatedAt = now()->utc()->format('Y-m-d H:i:s');

        fwrite($handle, <<<SQL
-- ============================================================================
-- Investigação Pública — dump lógico do banco
-- Gerado em: {$generatedAt} UTC
-- Servidor: {$version}
-- Banco: {$database}
-- Restauração: mariadb -u root "{$database}" < init.sql
-- ============================================================================

SET NAMES utf8mb4;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;


SQL);
    }

    /**
     * @return array<int, object{name:string, type:string}>
     */
    private function objects(Connection $connection): array
    {
        /** @var array<int, object{name:string, type:string}> $objects */
        $objects = $connection->select(
            'select table_name as name, table_type as type'
            .' from information_schema.tables'
            .' where table_schema = ?'
            .' order by table_name',
            [$connection->getDatabaseName()],
        );

        return $objects;
    }

    /**
     * @param  resource  $handle
     */
    private function dumpTable($handle, Connection $connection, string $table): int
    {
        $quoted = $this->quoteIdentifier($table);

        $create = $connection->selectOne('show create table '.$quoted);
        $statement = $create?->{'Create Table'} ?? null;

        if (! is_string($statement) || $statement === '') {
            throw new RuntimeException(sprintf('Não foi possível ler a estrutura da tabela %s.', $table));
        }

        $statement = $this->normalizeCreateTable($statement);

        fwrite($handle, "-- ----------------------------------------------------------------------------\n");
        fwrite($handle, '-- Tabela: '.$table."\n");
        fwrite($handle, "-- ----------------------------------------------------------------------------\n");
        fwrite($handle, 'DROP TABLE IF EXISTS '.$quoted.";\n");
        fwrite($handle, $statement.";\n\n");

        if (in_array($table, self::SKIP_DATA_TABLES, true)) {
            fwrite($handle, "-- Dados omitidos: tabela efêmera (sessões/cache/fila).\n\n");

            return 0;
        }

        return $this->dumpRows($handle, $connection, $table, $quoted);
    }

    /**
     * @param  resource  $handle
     */
    private function dumpView($handle, Connection $connection, string $view): void
    {
        $quoted = $this->quoteIdentifier($view);
        $row = $connection->selectOne('show create view '.$quoted);
        $statement = $row?->{'Create View'} ?? null;

        if (! is_string($statement) || $statement === '') {
            throw new RuntimeException(sprintf('Não foi possível ler a definição da view %s.', $view));
        }

        // O `DEFINER` do servidor de origem pode não existir no destino da
        // restauração (Railway/MariaDB); a view é recriada pelo usuário atual.
        $statement = preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/i', '', $statement) ?? $statement;

        fwrite($handle, 'DROP VIEW IF EXISTS '.$quoted.";\n");
        fwrite($handle, $statement.";\n\n");
    }

    /**
     * @param  resource  $handle
     */
    private function dumpRows($handle, Connection $connection, string $table, string $quotedTable): int
    {
        $columns = $connection->select(
            'select column_name as name, data_type as type, extra as extra'
            .' from information_schema.columns'
            .' where table_schema = ? and table_name = ?'
            .' order by ordinal_position',
            [$connection->getDatabaseName(), $table],
        );

        $insertable = [];

        foreach ($columns as $column) {
            $extra = strtoupper((string) ($column->extra ?? ''));

            // `DEFAULT_GENERATED` é apenas um default (ex.: timestamp); só as
            // colunas VIRTUAL/STORED realmente não podem receber INSERT.
            if (str_contains($extra, 'VIRTUAL GENERATED') || str_contains($extra, 'STORED GENERATED')) {
                continue;
            }

            $insertable[] = $column;
        }

        if ($insertable === []) {
            return 0;
        }

        $columnNames = array_map(fn (object $column): string => (string) $column->name, $insertable);
        $columnSql = implode(', ', array_map($this->quoteIdentifier(...), $columnNames));

        // Ordena pela PK simples quando existir: dump determinístico, bom para
        // diff no git, sem ordenar por colunas JSON/binárias.
        $primaryKey = $this->primaryKey($connection, $table);
        $orderSql = ($primaryKey !== null && in_array($primaryKey, $columnNames, true))
            ? ' order by '.$this->quoteIdentifier($primaryKey)
            : '';

        $pdo = $connection->getPdo();
        $bufferedAttribute = $this->bufferedQueryAttribute();
        $buffered = true;

        if ($bufferedAttribute !== null) {
            try {
                $buffered = (bool) $pdo->getAttribute($bufferedAttribute);
            } catch (Throwable) {
                $buffered = true;
            }

            $pdo->setAttribute($bufferedAttribute, false);
        }

        $rows = 0;

        try {
            $statement = $pdo->query('select '.$columnSql.' from '.$quotedTable.$orderSql);

            if ($statement === false) {
                throw new RuntimeException(sprintf('Não foi possível ler os dados da tabela %s.', $table));
            }

            try {
                $batch = [];
                $batchBytes = 0;

                while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $values = [];

                    foreach ($insertable as $column) {
                        $values[] = $this->literal(
                            $pdo,
                            $row[(string) $column->name] ?? null,
                            (string) $column->type,
                        );
                    }

                    $tuple = '  ('.implode(', ', $values).')';
                    $batch[] = $tuple;
                    $batchBytes += strlen($tuple);
                    $rows++;

                    if (count($batch) >= self::MAX_ROWS_PER_INSERT || $batchBytes >= self::MAX_INSERT_BYTES) {
                        $this->writeInsert($handle, $quotedTable, $columnSql, $batch);
                        $batch = [];
                        $batchBytes = 0;
                    }
                }

                if ($batch !== []) {
                    $this->writeInsert($handle, $quotedTable, $columnSql, $batch);
                }
            } finally {
                $statement->closeCursor();
            }
        } finally {
            if ($bufferedAttribute !== null) {
                $pdo->setAttribute($bufferedAttribute, $buffered);
            }
        }

        if ($rows > 0) {
            fwrite($handle, "\n");
        }

        return $rows;
    }

    /**
     * @param  resource  $handle
     * @param  array<int, string>  $tuples
     */
    private function writeInsert($handle, string $quotedTable, string $columnSql, array $tuples): void
    {
        fwrite($handle, 'INSERT INTO '.$quotedTable.' ('.$columnSql.') VALUES'.PHP_EOL);
        fwrite($handle, implode(','.PHP_EOL, $tuples).';'.PHP_EOL);
    }

    /**
     * Literal SQL seguro para um valor lido da própria base.
     */
    private function literal(PDO $pdo, mixed $value, string $type): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (in_array(strtolower($type), self::BINARY_TYPES, true)) {
            return '0x'.bin2hex((string) $value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // Notação independente do locale e com round-trip garantido.
            return sprintf('%.17G', $value);
        }

        $quoted = $pdo->quote((string) $value);

        if ($quoted === false) {
            throw new RuntimeException('Não foi possível escapar um valor do dump.');
        }

        return $quoted;
    }

    private function primaryKey(Connection $connection, string $table): ?string
    {
        $columns = $connection->select(
            'select column_name as name'
            .' from information_schema.statistics'
            ." where table_schema = ? and table_name = ? and index_name = 'PRIMARY'"
            .' order by seq_in_index',
            [$connection->getDatabaseName(), $table],
        );

        return count($columns) === 1 ? (string) $columns[0]->name : null;
    }

    private function beginSnapshot(PDO $pdo): bool
    {
        try {
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

            return true;
        } catch (Throwable) {
            // Sem snapshot o dump continua válido, apenas sem isolamento
            // perfeito em relação a escritas concorrentes.
            return false;
        }
    }

    /**
     * Remove opções específicas do MySQL 8 que o MariaDB (Railway) recusaria.
     */
    private function normalizeCreateTable(string $statement): string
    {
        $normalized = preg_replace(
            '/\s*\/\*!\d+\s+DEFAULT ENCRYPTION=\'(?:Y|N)\'\s*\*\//i',
            '',
            $statement,
        );

        return $normalized ?? $statement;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /**
     * Constante de leitura sem buffer do PDO MySQL.
     *
     * O PHP 8.5 moveu as constantes para `Pdo\Mysql` e descontinuou as antigas;
     * a checagem em ordem mantém compatibilidade com PHP 8.3/8.4.
     */
    private function bufferedQueryAttribute(): ?int
    {
        if (defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY')) {
            return Mysql::ATTR_USE_BUFFERED_QUERY;
        }

        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            return PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
        }

        return null;
    }
}

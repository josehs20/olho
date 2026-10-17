<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminDatabaseDumpRequest;
use App\Services\AuditLogger;
use App\Services\DatabaseBackup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Backup do banco pelo painel admin.
 *
 * O dump é gravado em `database/backups/init.sql` — o mesmo caminho que o
 * Dockerfile restaura no boot (`mariadb ... < /var/www/html/database/backups/init.sql`).
 * O download é apenas a entrega do arquivo já gravado; a geração é síncrona e
 * limitada por throttle porque percorre a base inteira.
 */
class BackupController extends Controller
{
    public function __construct(
        private readonly DatabaseBackup $backup,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        return view('admin.backup.index', [
            'relativePath' => DatabaseBackup::RELATIVE_PATH,
            'path' => $this->backup->path(),
            'exists' => $this->backup->exists(),
            'size' => $this->backup->size(),
            'modifiedAt' => $this->backup->modifiedAt(),
        ]);
    }

    /**
     * Gera o dump (e, opcionalmente, já devolve o download).
     */
    public function dump(AdminDatabaseDumpRequest $request): RedirectResponse|BinaryFileResponse
    {
        // A base inteira é percorrida: bases maiores podem passar do
        // `max_execution_time` padrão do PHP-FPM (60s no container).
        @set_time_limit(300);

        try {
            $result = $this->backup->dump();
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.backup.index')
                ->with('error', __('Não foi possível gerar o dump: :message', [
                    'message' => $exception->getMessage(),
                ]));
        }

        $this->audit->log($request->user(), 'admin.database_dump', null, [
            'path' => DatabaseBackup::RELATIVE_PATH,
            'bytes' => $result['bytes'],
            'tables' => $result['tables'],
            'rows' => $result['rows'],
            'duration_ms' => $result['duration_ms'],
        ], $request->ip());

        if ($request->boolean('download')) {
            return $this->downloadFile($request);
        }

        return redirect()
            ->route('admin.backup.index')
            ->with('status', __('Dump gerado em :path (:tables tabelas, :rows linhas, :size).', [
                'path' => DatabaseBackup::RELATIVE_PATH,
                'tables' => number_format($result['tables'], 0, ',', '.'),
                'rows' => number_format($result['rows'], 0, ',', '.'),
                'size' => $this->formatBytes($result['bytes']),
            ]));
    }

    public function download(Request $request): RedirectResponse|BinaryFileResponse
    {
        return $this->downloadFile($request);
    }

    private function downloadFile(Request $request): RedirectResponse|BinaryFileResponse
    {
        if (! $this->backup->exists()) {
            return redirect()
                ->route('admin.backup.index')
                ->with('error', __('Nenhum dump disponível. Gere o dump antes de baixar.'));
        }

        $this->audit->log($request->user(), 'admin.database_dump_download', null, [
            'path' => DatabaseBackup::RELATIVE_PATH,
            'bytes' => $this->backup->size(),
        ], $request->ip());

        return response()->download($this->backup->path(), 'init.sql', [
            'Content-Type' => 'application/sql; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', '.').' KB';
        }

        return number_format($bytes / (1024 * 1024), 1, ',', '.').' MB';
    }
}

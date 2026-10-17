<?php

namespace App\Http\Middleware;

use App\Services\VisitorCounter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Contador de visitantes do site (painel admin).
 *
 * Conta apenas carregamentos de página HTML bem-sucedidos (2xx): exclui JSON
 * de polling, healthcheck (text/plain), assets (servidos pelo nginx) e a
 * navegação do próprio painel admin (`/admin*`). A contagem é não crítica —
 * qualquer falha é registrada em log e nunca derruba a renderização.
 */
class TrackVisitor
{
    public function __construct(private readonly VisitorCounter $counter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if ($this->shouldCount($request, $response)) {
                $this->counter->record($request);
            }
        } catch (Throwable $e) {
            Log::warning('Falha ao registrar visitante', ['error' => $e->getMessage()]);
        }

        return $response;
    }

    private function shouldCount(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        // A navegação do operador no admin não é visita ao site.
        if ($request->is('admin') || $request->is('admin/*')) {
            return false;
        }

        // Somente páginas renderizadas com sucesso (2xx).
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;
        }

        return str_contains((string) $response->headers->get('Content-Type', ''), 'text/html');
    }
}

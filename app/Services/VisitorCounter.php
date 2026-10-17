<?php

namespace App\Services;

use App\Models\Visit;
use Illuminate\Http\Request;

/**
 * Contador de visitantes do site (painel admin).
 *
 * Registra apenas agregados diários (`page_views` + `unique_visitors`) sem
 * coletar IP, user-agent, URL ou qualquer identificador: a unicidade do
 * visitante é marcada na sessão (chave por dia), nunca por um dado pessoal.
 */
class VisitorCounter
{
    /** Prefixo da chave de sessão que marca o visitante único do dia. */
    private const SESSION_PREFIX = 'visits.seen.';

    /**
     * Registra um carregamento de página. Nunca deve derrubar a requisição:
     * o chamador (middleware) captura qualquer falha.
     */
    public function record(Request $request): void
    {
        $date = now()->toDateString();
        $sessionKey = self::SESSION_PREFIX.$date;

        $isNewVisitor = ! $request->hasSession() || ! $request->session()->has($sessionKey);

        if ($isNewVisitor && $request->hasSession()) {
            $request->session()->put($sessionKey, true);
        }

        $visit = Visit::query()->firstOrCreate(['date' => $date]);
        $visit->increment('page_views');

        if ($isNewVisitor) {
            $visit->increment('unique_visitors');
        }
    }

    /**
     * Totais para o dashboard: visões/visitantes de hoje e acumulados.
     *
     * @return array{page_views:int, unique_visitors:int, today_views:int, today_unique:int}
     */
    public function totals(): array
    {
        $today = Visit::query()->where('date', now()->toDateString())->first();

        return [
            'page_views' => (int) Visit::query()->sum('page_views'),
            'unique_visitors' => (int) Visit::query()->sum('unique_visitors'),
            'today_views' => (int) ($today->page_views ?? 0),
            'today_unique' => (int) ($today->unique_visitors ?? 0),
        ];
    }
}

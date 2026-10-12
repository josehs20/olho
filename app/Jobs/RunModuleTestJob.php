<?php

namespace App\Jobs;

use App\Services\ModuleTestRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Executa **um** teste individual de módulo no worker já existente (prompt 47
 * §4/§11).
 *
 * Fila, worker e retry são os mesmos das investigações normais: nada é executado
 * durante a requisição HTTP — a tela apenas enfileira e acompanha o status. O job
 * carrega somente o id do teste; toda a lógica vive em `ModuleTestRunner`.
 */
class RunModuleTestJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [10, 60];

    /**
     * Teto do job. O adapter já respeita o timeout da própria fonte
     * (`source_definitions.timeout`, 60s nas ferramentas OSINT) e converte o
     * estouro em status `TIMEOUT`; a folga garante que esse tempo limite seja o
     * observado antes de o worker matar o job (§10: nunca aumentar o timeout da
     * execução, apenas protegê-la de um kill silencioso).
     */
    public int $timeout = 90;

    public function __construct(public int $moduleTestId) {}

    public function uniqueId(): string
    {
        return 'module-test-'.$this->moduleTestId;
    }

    public function handle(ModuleTestRunner $runner): void
    {
        $runner->run($this->moduleTestId);
    }

    /**
     * Worker interrompeu a execução (timeout/kill) ou o job esgotou as tentativas:
     * encerra o teste e resolve a reserva pendente, para não deixar uma execução
     * `running` eterna e um crédito reservado sem desfecho.
     */
    public function failed(Throwable $e): void
    {
        app(ModuleTestRunner::class)->markInterrupted($this->moduleTestId, $e);
    }
}

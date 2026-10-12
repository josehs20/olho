<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminModuleTestRequest;
use App\Models\ModuleTest;
use App\Models\SourceDefinition;
use App\Services\AuditLogger;
use App\Services\ModuleTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Painel admin de testes individuais de módulo (prompt 47).
 *
 * Área restrita a administradores (middleware `admin` + `authorize()` dos
 * requests). O painel é de diagnóstico da operação: o histórico é compartilhado
 * entre administradores — ninguém que não seja admin alcança estes registros.
 *
 * O controller **não executa nada**: enfileira o teste (`ModuleTestService`) e
 * acompanha o status (a ferramenta externa roda no worker, como na investigação
 * normal).
 */
class ModuleTestController extends Controller
{
    public function __construct(
        private readonly ModuleTestService $tests,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Formulário de teste + histórico dos testes realizados (§8).
     */
    public function index(Request $request): View
    {
        $module = $request->string('module')->toString();

        $history = ModuleTest::query()
            ->with(['sourceDefinition', 'user'])
            ->when($module !== '', fn ($query) => $query->where('module', $module))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // Opções do formulário: whitelist de módulos testáveis com o campo de
        // entrada esperado por cada adapter (contrato `supports()`), o custo
        // vigente e o estado de ativação — nada é validado/adivinhado na view.
        $moduleOptions = $this->tests->testableDefinitions()
            ->map(fn (SourceDefinition $definition) => [
                'slug' => $definition->slug,
                'name' => $definition->name,
                'input' => $this->tests->inputField($definition),
                'cost' => (int) $definition->cost_credits,
                'active' => (bool) $definition->active,
            ])
            ->values();

        return view('admin.tests.index', [
            'moduleOptions' => $moduleOptions,
            'history' => $history,
            'filter' => $module,
            'balance' => $this->tests->balance($request->user()),
        ]);
    }

    /**
     * Enfileira a execução e devolve imediatamente para a tela de acompanhamento
     * (§11: nenhuma requisição HTTP espera o módulo terminar).
     */
    public function store(AdminModuleTestRequest $request): RedirectResponse
    {
        $test = $this->tests->queue(
            $request->user(),
            $request->string('module')->toString(),
            $request->string('target')->toString(),
        );

        // Auditoria de quem disparou qual consulta externa (prompt 47 §12).
        $this->audit->log($request->user(), 'admin.module_test_run', $test, [
            'module' => $test->module,
            'target' => $test->target,
            'credits_cost' => (int) $test->credits_cost,
        ], $request->ip());

        return redirect()
            ->route('admin.tests.show', $test)
            ->with('status', __('Teste enfileirado. A execução começará no worker.'));
    }

    /**
     * Detalhes/log do teste (§6) com tempos por etapa (§13).
     *
     * Carrega a execução real e seus **resultados com evidências**: é o mesmo
     * `source_results` da investigação normal, então a tela mostra o que o módulo
     * encontrou, não apenas a contagem.
     */
    public function show(ModuleTest $test): View
    {
        $test->load([
            'sourceDefinition',
            'user',
            'creditReservation',
            'investigation',
            'sourceRun.sourceResults.evidence',
        ]);

        return view('admin.tests.show', [
            'test' => $test,
            'status' => $test->statusEnum(),
        ]);
    }

    /**
     * Estado atual do teste para o acompanhamento automático da tela (§11).
     *
     * Mecanismo mínimo: o mesmo polling já usado no acompanhamento do PIX.
     * Devolve apenas estado público — nunca stdout/stderr, que só aparecem no
     * detalhe sanitizado.
     */
    public function status(ModuleTest $test): JsonResponse
    {
        $status = $test->statusEnum();

        return response()->json([
            'test' => [
                'id' => $test->id,
                'module' => $test->module,
                'status' => $status->value,
                'label' => $status->label(),
                'terminal' => $status->isTerminal(),
                'started_at' => $test->started_at?->toISOString(),
                'finished_at' => $test->finished_at?->toISOString(),
                'duration_ms' => $test->durationMs(),
                'result_count' => (int) $test->result_count,
                'evidence_count' => (int) $test->evidence_count,
                'exit_code' => $test->exit_code,
                'error_code' => $test->error_code,
                'error_message' => $test->error_message_public,
                'credits_before' => $test->credits_before,
                'credits_cost' => (int) $test->credits_cost,
                'credits_after' => $test->credits_after,
                'timings' => $test->timings(),
            ],
        ]);
    }

    /**
     * Reexecuta o mesmo módulo/alvo como um **novo** teste (§8), com reserva de
     * crédito independente e o histórico anterior preservado.
     */
    public function rerun(Request $request, ModuleTest $test): RedirectResponse
    {
        $rerun = $this->tests->rerun($request->user(), $test);

        $this->audit->log($request->user(), 'admin.module_test_rerun', $rerun, [
            'module' => $rerun->module,
            'target' => $rerun->target,
            'previous_test_id' => $test->id,
        ], $request->ip());

        return redirect()
            ->route('admin.tests.show', $rerun)
            ->with('status', __('Teste reenfileirado (#:id).', ['id' => $test->id]));
    }
}

<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">
   {{ __('Teste #:id — :module', ['id' => $test->id, 'module' => $test->sourceDefinition?->name ?? $test->module]) }}
  </h2>
 </x-slot>

 <div class="py-12">
  <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
  @include('admin.partials.nav')

  @if (session('status'))
   <p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>
  @endif

  <p class="mb-4">
   <a href="{{ route('admin.tests.index') }}" class="text-signal-300 underline decoration-signal-500/40">{{ __('← Histórico de testes') }}</a>
  </p>

  <div class="overflow-hidden surface"
       data-test-id="{{ $test->id }}"
       data-status-url="{{ route('admin.tests.status', $test) }}"
       data-terminal="{{ $status->isTerminal() ? '1' : '0' }}">

   <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line/40 p-6">
    <div class="flex items-center gap-3">
     @include('admin.tests.partials.status', ['status' => $status])
     <span class="text-sm text-mist-400">{{ $test->sourceDefinition?->name ?? $test->module }}</span>
    </div>
    <div class="text-right text-sm text-mist-300">
     <span class="text-xs text-mist-500">{{ __('Início') }}:</span>
     {{ $test->started_at?->format('d/m/Y H:i:s') ?? '—' }}
     <br>
     <span class="text-xs text-mist-500" data-role="duration-label">
      {{ $status->isTerminal() ? __('Duração') : __('Tempo decorrido') }}:
     </span>
     <span data-role="duration">{{ \App\Models\ModuleTest::formatDuration($test->durationMs()) }}</span>
    </div>
   </div>

   <dl class="divide-y divide-gray-200 text-sm">
    <div class="flex flex-wrap gap-2 px-6 py-3">
     <dt class="w-56 text-mist-500">{{ __('ID da execução') }}</dt>
     <dd class="flex-1">#{{ $test->id }}</dd>
    </div>
    <div class="flex flex-wrap gap-2 px-6 py-3">
     <dt class="w-56 text-mist-500">{{ __('Módulo') }}</dt>
     <dd class="flex-1">{{ $test->module }} · {{ $test->sourceDefinition?->type ?? '—' }}
      <span class="text-xs text-mist-500">{{ __('(custo configurado: :cost créditos)', ['cost' => (int) ($test->sourceDefinition?->cost_credits ?? 0)]) }}</span>
     </dd>
    </div>
    <div class="flex flex-wrap gap-2 px-6 py-3">
     <dt class="w-56 text-mist-500">{{ __('Alvo') }}</dt>
     <dd class="flex-1 break-all">{{ $test->target }}</dd>
    </div>
    <div class="flex flex-wrap gap-2 px-6 py-3">
     <dt class="w-56 text-mist-500">{{ __('Solicitado por') }}</dt>
     <dd class="flex-1">{{ $test->user?->email ?? '—' }}</dd>
    </div>
    <div class="flex flex-wrap gap-2 px-6 py-3">
     <dt class="w-56 text-mist-500">{{ __('Enfileirado em') }}</dt>
     <dd class="flex-1">{{ $test->queued_at?->format('d/m/Y H:i:s') ?? $test->created_at?->format('d/m/Y H:i:s') }}</dd>
    </div>
    <div class="flex flex-wrap gap-2 px-6 py-3">
     <dt class="w-56 text-mist-500">{{ __('Término') }}</dt>
     <dd class="flex-1" data-role="finished-at">{{ $test->finished_at?->format('d/m/Y H:i:s') ?? '—' }}</dd>
    </div>
    <div class="flex flex-wrap gap-2 px-6 py-3">
     <dt class="w-56 text-mist-500">{{ __('Resultados') }}</dt>
     <dd class="flex-1">
      <span data-role="result-count">{{ (int) $test->result_count }}</span>
      {{ __('findings') }} ·
      <span data-role="evidence-count">{{ (int) $test->evidence_count }}</span>
      {{ __('evidências') }}
     </dd>
    </div>
    <div class="flex flex-wrap gap-2 px-6 py-3">
     <dt class="w-56 text-mist-500">{{ __('Exit code') }}</dt>
     <dd class="flex-1" data-role="exit-code">{{ $test->exit_code ?? '—' }}</dd>
    </div>
    <div class="flex flex-wrap gap-2 px-6 py-3">
     <dt class="w-56 text-mist-500">{{ __('Erro') }}</dt>
     <dd class="flex-1" data-role="error">
      @if ($test->error_code)
       <span class="text-rose-300">{{ $test->error_code }}</span> — {{ $test->error_message_public }}
      @else
       —
      @endif
     </dd>
    </div>
   </dl>
  </div>

  {{-- Resultados encontrados (§6): o que o módulo realmente achou. São as linhas
       de `source_results` gravadas pela execução real (mesma normalização da
       investigação normal), com a evidência que sustenta cada achado. --}}
  @if ($test->sourceRun)
   @php($findings = $test->sourceRun->sourceResults)
   <div class="mt-6 overflow-hidden surface">
    <h3 class="flex flex-wrap items-center justify-between gap-2 border-b border-line/40 px-6 py-3 text-sm font-semibold text-mist-200">
     <span>{{ __('Resultados encontrados') }}</span>
     <span class="text-xs font-normal text-mist-500">
      {{ $findings->count() }} {{ __('resultado(s)') }} ·
      {{ (int) $test->evidence_count }} {{ __('evidências') }}
     </span>
    </h3>

    @php($adapterMetrics = $test->sourceRun->metrics['adapter'] ?? [])
    @if (isset($adapterMetrics['sites_checked']))
     {{-- Panorama da varredura: sem ele, "0 resultados" e "a ferramenta não
          verificou nada" ficam idênticos na tela (foi assim que o bug do
          `--timeout` do Holehe passou por resultado vazio). --}}
     <p class="border-b border-line/40 px-6 py-2 text-xs text-mist-500" data-role="adapter-scan">
      {{ __('Serviços verificados') }}:
      <span class="text-mist-300">{{ (int) $adapterMetrics['sites_checked'] }}</span>
      · {{ __('sem resposta (bloqueio/erro)') }}:
      <span class="text-mist-300">{{ (int) ($adapterMetrics['sites_unavailable'] ?? 0) }}</span>
     </p>
    @endif

    @forelse ($findings as $result)
     <div class="border-b border-line/40 px-6 py-4 last:border-b-0">
      <div class="flex flex-wrap items-center justify-between gap-2">
       <p class="font-medium text-mist-100">{{ $result->title ?? $result->value }}</p>
       <span class="rounded bg-ink-700/70 px-2 py-0.5 text-xs text-mist-300">{{ $result->entity_type }}</span>
      </div>
      <p class="mt-1 text-sm text-mist-400">
       {{ __('Alvo') }}: <span class="text-mist-100">{{ $result->value }}</span>
       · {{ __('Confiança') }}:
       {{ $result->confidence !== null ? number_format((float) $result->confidence, 2, ',', '.') : '—' }}
      </p>
      @if ($result->url)
       <a href="{{ $result->url }}" target="_blank" rel="noopener noreferrer"
          class="mt-1 block break-all text-sm text-signal-300 underline decoration-signal-500/40 hover:text-signal-300">{{ $result->url }}</a>
      @endif
      @if ($result->description)
       <p class="mt-1 text-sm text-mist-300">{{ $result->description }}</p>
      @endif
      {{-- Captura de imagem (prompt 48 §6): a foto pública registrada como
           evidência, renderizada só quando a fonte devolveu HTTPS — a URL vem
           de fora e não é tratada como confiável além disso. --}}
      @php($avatarEvidence = $result->evidence->first(fn ($evidence) => $evidence->type === 'avatar' && str_starts_with(strtolower((string) $evidence->value), 'https://')))
      @php($chips = $result->evidence->reject(fn ($evidence) => $avatarEvidence !== null && $avatarEvidence->is($evidence)))
      @if ($avatarEvidence)
       <a href="{{ $avatarEvidence->value }}" target="_blank" rel="noopener noreferrer"
          class="mt-2 inline-block" title="{{ __('Abrir imagem em nova aba') }}">
        <img src="{{ $avatarEvidence->value }}"
             alt="{{ __('Imagem pública do perfil') }} {{ $result->value }}"
             loading="lazy" referrerpolicy="no-referrer"
             class="h-28 w-28 rounded-lg border border-line/40 bg-ink-700/40 object-cover" />
       </a>
      @elseif ($result->sourceDefinition?->slug === 'instagram')
       {{-- Prompt 48 §6: sem imagem, a ausência é dita — nunca foto fictícia. --}}
       <p class="mt-2 text-sm text-mist-400">{{ __('Imagem pública não encontrada') }}</p>
      @endif
      @if ($chips->isNotEmpty())
       <div class="mt-2 flex flex-wrap gap-1">
        @foreach ($chips as $evidence)
         <span class="rounded bg-ink-700/70 px-2 py-0.5 text-xs text-mist-300">{{ $evidence->type }}: {{ $evidence->value }}</span>
        @endforeach
       </div>
      @endif
      <p class="mt-1 text-xs text-mist-500">
       {{ __('Observado em') }} {{ $result->observed_at?->format('d/m/Y H:i:s') ?? '—' }}
      </p>
     </div>
    @empty
     <p class="px-6 py-3 text-sm text-mist-400">
      {{ __('Nenhum resultado gravado nesta execução — a consulta foi executada, mas não houve correspondência.') }}
     </p>
    @endforelse
   </div>
  @endif

  {{-- Consumo de créditos (§7): saldo antes, custo aplicado e saldo depois. --}}
  <div class="mt-6 overflow-hidden surface">
   <h3 class="border-b border-line/40 px-6 py-3 text-sm font-semibold text-mist-200">{{ __('Créditos') }}</h3>
   <dl class="divide-y divide-gray-200 text-sm">
    <div class="flex flex-wrap gap-2 px-6 py-3">
     <dt class="w-56 text-mist-500">{{ __('Saldo antes') }}</dt>
     <dd class="flex-1" data-role="credits-before">{{ $test->credits_before ?? '—' }}</dd>
    </div>
    <div class="flex flex-wrap gap-2 px-6 py-3">
     <dt class="w-56 text-mist-500">{{ __('Custo aplicado') }}</dt>
     <dd class="flex-1">
      <span data-role="credits-cost">{{ (int) $test->credits_cost }}</span> {{ __('créditos') }}
      @if ((int) $test->credits_cost === 0)
       <span class="text-xs text-mist-500">{{ __('(consulta não executada — nenhum crédito cobrado)') }}</span>
      @endif
     </dd>
    </div>
    <div class="flex flex-wrap gap-2 px-6 py-3">
     <dt class="w-56 text-mist-500">{{ __('Saldo depois') }}</dt>
     <dd class="flex-1" data-role="credits-after">{{ $test->credits_after ?? '—' }}</dd>
    </div>
    <div class="flex flex-wrap gap-2 px-6 py-3">
     <dt class="w-56 text-mist-500">{{ __('Reserva') }}</dt>
     <dd class="flex-1">
      @if ($test->creditReservation)
       #{{ $test->creditReservation->id }} · {{ $test->creditReservation->status }} · {{ (int) $test->creditReservation->amount }} {{ __('créditos') }}
      @else
       {{ __('sem reserva (módulo sem custo ou consulta não executada)') }}
      @endif
     </dd>
    </div>
   </dl>
   <p class="px-6 py-3 text-xs text-mist-500">
    {{ __('Regra vigente: paga-se pela consulta executada, independentemente de ter havido resultado. Módulo não aplicável ao dado ou não executado não consome créditos.') }}
   </p>
  </div>

  @php($timings = $test->timings())

  {{-- Tempos por etapa (§13). Etapas não medidas não são exibidas. --}}
  @if ($timings !== [])
   <div class="mt-6 overflow-hidden surface">
    <h3 class="border-b border-line/40 px-6 py-3 text-sm font-semibold text-mist-200">{{ __('Tempo por etapa') }}</h3>
    <dl class="divide-y divide-gray-200 text-sm">
     @foreach ([
      'queue_ms' => __('Espera na fila'),
      'job_ms' => __('Job no worker'),
      'adapter_ms' => __('Adapter (inclui a ferramenta)'),
      'process_ms' => __('Processo externo'),
      'persist_ms' => __('Persistência de resultados'),
      'total_ms' => __('Total (enfileiramento → encerramento)'),
     ] as $stage => $label)
      @if (array_key_exists($stage, $timings))
       <div class="flex flex-wrap gap-2 px-6 py-3">
        <dt class="w-56 text-mist-500">{{ $label }}</dt>
        <dd class="flex-1">
         {{ \App\Models\ModuleTest::formatDuration($timings[$stage]) }}
         <span class="text-xs text-mist-500">{{ $timings[$stage] }} ms</span>
        </dd>
       </div>
      @endif
     @endforeach
    </dl>
   </div>
  @endif

  @if ($test->warnings() !== [])
   <div class="mt-6 overflow-hidden surface">
    <h3 class="border-b border-line/40 px-6 py-3 text-sm font-semibold text-mist-200">{{ __('Avisos do módulo') }}</h3>
    <ul class="list-inside list-disc space-y-1 px-6 py-3 text-sm text-amber-200">
     @foreach ($test->warnings() as $warning)
      <li>{{ $warning }}</li>
     @endforeach
    </ul>
   </div>
  @endif

  {{-- Saída resumida das ferramentas (§6): já sanitizada pelo `LogSanitizer` e
       truncada no limite de `config/module_tests.php`. --}}
  @if ($test->stdout || $test->stderr)
   <div class="mt-6 overflow-hidden surface">
    <h3 class="border-b border-line/40 px-6 py-3 text-sm font-semibold text-mist-200">{{ __('Saída resumida') }}</h3>

    @if ($test->stdout)
     <div class="px-6 py-3">
      <p class="mb-1 text-xs text-mist-500">{{ __('stdout') }}</p>
      <pre class="max-h-64 overflow-auto rounded bg-ink-950/60 p-3 text-xs text-mist-300">{{ $test->stdout }}</pre>
     </div>
    @endif

    @if ($test->stderr)
     <div class="px-6 py-3">
      <p class="mb-1 text-xs text-mist-500">{{ __('stderr') }}</p>
      <pre class="max-h-64 overflow-auto rounded bg-ink-950/60 p-3 text-xs text-rose-200">{{ $test->stderr }}</pre>
     </div>
    @endif
   </div>
  @endif

  {{-- Rastreabilidade: aponta para a execução real onde os resultados e as
       evidências deste teste foram gravados. --}}
  <div class="mt-6 overflow-hidden surface">
   <h3 class="border-b border-line/40 px-6 py-3 text-sm font-semibold text-mist-200">{{ __('Execução real') }}</h3>
   <dl class="divide-y divide-gray-200 text-sm">
    <div class="flex flex-wrap gap-2 px-6 py-3">
     <dt class="w-56 text-mist-500">{{ __('Investigação auxiliar') }}</dt>
     <dd class="flex-1">
      @if ($test->investigation)
       <a href="{{ route('admin.investigations.show', $test->investigation) }}" class="text-signal-300 underline decoration-signal-500/40">
        {{ $test->investigation->title }} (#{{ $test->investigation->id }})
       </a>
      @else
       — {{ __('(nenhuma consulta chegou a ser criada)') }}
      @endif
     </dd>
    </div>
    <div class="flex flex-wrap gap-2 px-6 py-3">
     <dt class="w-56 text-mist-500">{{ __('Run / fonte') }}</dt>
     <dd class="flex-1">
      {{ $test->investigation_run_id ? '#'.$test->investigation_run_id : '—' }}
      /
      {{ $test->source_run_id ? '#'.$test->source_run_id : '—' }}
      @if ($test->sourceRun)
       · {{ $test->sourceRun->status }}
       @if ((int) $test->sourceRun->attempts > 0)
        · {{ $test->sourceRun->attempts }} {{ __('tentativa(s) de consulta') }}
       @endif
      @endif
     </dd>
    </div>
   </dl>
   <div class="flex flex-wrap items-center gap-3 px-6 py-4">
    <form method="POST" action="{{ route('admin.tests.rerun', $test) }}">
     @csrf
     <button type="submit"
      class="inline-flex items-center justify-center rounded-md bg-ink-700 px-4 py-2 text-sm font-semibold text-white hover:border-signal-500/50">
      {{ __('EXECUTAR NOVAMENTE') }}
     </button>
    </form>
    <span class="text-xs text-mist-500">{{ __('Cria um novo teste, com nova reserva de crédito; este registro é preservado no histórico.') }}</span>
   </div>
  </div>
  </div>
 </div>

  @push('scripts')
   <script>
    // Acompanhamento do teste (§11): a ferramenta roda no worker, então a tela
    // consulta o estado atual (mesmo mecanismo de polling do PIX) e recarrega
    // quando o teste chega a um estado terminal — momento em que tempos por
    // etapa, saída resumida e créditos já estão consolidados.
    (function () {
     var root = document.querySelector('[data-test-id]');

     if (! root) {
      return;
     }

     var statusUrl = root.getAttribute('data-status-url');
     var isTerminal = root.getAttribute('data-terminal') === '1';

     if (! statusUrl || isTerminal) {
      return;
     }

     var startedAt = @json($test->started_at?->toISOString());
     var durationNode = root.querySelector('[data-role="duration"]');

     var pad = function (value) {
      return value < 10 ? '0' + value : '' + value;
     };

     var formatElapsed = function () {
      if (! startedAt || ! durationNode) {
       return;
      }

      var elapsed = Date.now() - new Date(startedAt).getTime();

      if (elapsed < 1000) {
       durationNode.textContent = Math.max(0, Math.round(elapsed)) + ' ms';
       return;
      }

      var seconds = Math.floor(elapsed / 1000);
      var hours = Math.floor(seconds / 3600);

      durationNode.textContent = pad(hours) + ':' + pad(Math.floor((seconds % 3600) / 60)) + ':' + pad(seconds % 60);
     };

     formatElapsed();

     var attempts = 0;
     var timer = window.setInterval(function () {
      attempts++;
      formatElapsed();

      fetch(statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
       .then(function (response) { return response.ok ? response.json() : null; })
       .then(function (data) {
        if (data && data.test && data.test.terminal) {
         window.clearInterval(timer);
         window.location.reload();
        }
       })
       .catch(function () {});

      // ~5 minutos de acompanhamento: o job tem teto de 90s, então isso cobre
      // fila + execução com folga antes de o operador atualizar manualmente.
      if (attempts >= 100) {
       window.clearInterval(timer);
      }
     }, 3000);
    })();
   </script>
  @endpush
</x-app-layout>

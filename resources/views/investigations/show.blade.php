@php
    /*
     * Página de resultados da investigação (fluxo autenticado).
     *
     * Seções bem definidas: Parâmetros de Entrada, Status Geral, Modo de
     * Execução (Mock/Real), Resultados por Módulo e Evidências / Enriquecimento.
     * O modo de execução vem congelado no run (`execution_mode`), com fallback
     * para o modo atual do ambiente — nunca inventado. O polling (partial)
     * mantém a tela sincronizada enquanto o status é de processamento.
     */
    $run = $investigation->currentRun ?: $investigation->runs->sortByDesc('run_number')->first();
    $input = $run?->input_snapshot ?? [];
    $moduleSlugs = $run?->moduleSlugs() ?? [];
    $inputFields = collect($input)->except('modules')->filter(fn ($value) => is_scalar($value) && $value !== null && $value !== '');

    $inputLabels = [
        'name' => __('Nome'),
        'username' => __('Username'),
        'email' => __('E-mail'),
        'phone' => __('Telefone'),
        'city' => __('Cidade'),
        'state' => __('UF'),
    ];

    // Modo de execução congelado no run; fallback para o modo atual do ambiente.
    $executionMode = $run?->metadata['execution_mode'] ?? null;
    $executionModeValue = is_array($executionMode) ? ($executionMode['mode'] ?? null) : null;
    $executionModeLabel = is_array($executionMode) && ! empty($executionMode['label'])
        ? $executionMode['label']
        : \App\Sources\OsintMode::label();

    $executionTone = match ($executionModeValue) {
        'real' => 'success',
        'mock' => 'warning',
        default => 'violet',
    };

    $executionNote = match ($executionModeValue) {
        'real' => __('Esta execução usou as integrações reais (Maigret, Sherlock, WhatsMyName, Holehe e enriquecimento). Nenhum dado simulado.'),
        'mock' => __('Esta execução usou os adapters determinísticos (mock), sem consultar a rede — os dados são de teste.'),
        default => __('Cada fonte seguiu o seu próprio driver configurado no ambiente.'),
    };

    // Resultados por módulo (run atual).
    $sourceRuns = $run?->sourceRuns ?? collect();

    // Perfis enriquecidos (Instagram/instagrapi): foto, nome e bio em destaque.
    $enrichedProfiles = $sourceRuns
        ->filter(fn ($sourceRun) => $sourceRun->source === 'instagram')
        ->flatMap(fn ($sourceRun) => $sourceRun->sourceResults
            ->reject(fn ($result) => ! empty($result->metadata['duplicate_of'])))
        ->values();

    $discoveriesTotal = (int) ($progress['discoveries_total'] ?? 0);
    $completedSources = (int) ($progress['completed_sources'] ?? 0);
    $isProcessing = in_array($investigation->status, ['queued', 'running'], true);
    $backUrl = route('investigations.index');
@endphp

<x-app-layout>
 <x-slot name="header">
  <div class="flex flex-wrap items-start justify-between gap-4">
   <div class="min-w-0">
   <p class="eyebrow">{{ __('Investigação') }}</p>
   <h2 class="mt-1 truncate text-2xl font-semibold tracking-tight text-mist-50">{{ $investigation->title }}</h2>
   <p class="mt-1 font-mono text-[0.6875rem] text-mist-500">
    {{ __('criada em') }} {{ $investigation->created_at?->format('d/m/Y H:i') }}
   </p>
   </div>

   <div class="flex flex-col items-start gap-2 sm:items-end">
   <x-status-badge :status="$investigation->status" />
   <x-badge :tone="$executionTone">
    <span class="inline-flex items-center gap-1.5">
    <span class="h-1.5 w-1.5 rounded-full {{ $executionTone === 'success' ? 'bg-emerald-300' : ($executionTone === 'warning' ? 'bg-amber-300' : 'bg-violet-300') }}" aria-hidden="true"></span>
    {{ $executionModeLabel }}
    </span>
   </x-badge>
   </div>
  </div>
 </x-slot>

 <div class="py-10">
  <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">

  @if (session('status'))
   <x-alert tone="success">{{ session('status') }}</x-alert>
  @endif

  @if ($errors->any())
   <x-alert tone="danger">
   <ul class="list-inside list-disc space-y-1">
    @foreach ($errors->all() as $error)
    <li>{{ $error }}</li>
    @endforeach
   </ul>
   </x-alert>
  @endif

  {{-- ============================================================= --}}
  {{-- Status Geral (indicadores)                                      --}}
  {{-- ============================================================= --}}
  <section>
   <x-section-heading
    :eyebrow="__('Status geral')"
    :title="__('Panorama da execução')"
    :description="$isProcessing
     ? __('A investigação está em processamento. Esta tela se atualiza sozinha — não é necessário recarregar.')
     : __('Resumo do que foi pesquisado e encontrado nesta execução.')" />

   <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <x-stat :label="__('Status')" :value="__((string) $investigation->status)" :accent="$isProcessing" />
    <x-stat :label="__('Descobertas')" :value="$discoveriesTotal" :hint="__('sinais públicos observados')" />
    <x-stat :label="__('Fontes concluídas')" :value="$completedSources.' / '.$sourceRuns->count()" />
    <x-stat
    :label="__('Créditos')"
    :value="$run ? $run->credits_consumed.' / '.$run->credits_reserved : '—'"
    :hint="__('consumidos / reservados')" />
   </div>
  </section>

  {{-- ============================================================= --}}
  {{-- Modo de Execução (Mock/Real)                                    --}}
  {{-- ============================================================= --}}
  <x-card :eyebrow="__('Modo de execução')" :title="$executionModeLabel">
   <div class="flex flex-wrap items-center gap-3">
   <x-badge :tone="$executionTone">
    @switch($executionModeValue)
     @case('real') {{ __('Integração real') }} @break
     @case('mock') {{ __('Mock (simulado)') }} @break
     @default {{ __('Automático (por fonte)') }}
    @endswitch
   </x-badge>
   @if ($run && $run->credits_reserved > 0)
    <span class="font-mono text-[0.6875rem] text-mist-500">
    {{ __('execução') }} #{{ $run->run_number }} · {{ __((string) $run->status) }}
    </span>
   @endif
   </div>
   <p class="mt-3 text-sm leading-relaxed text-mist-400">{{ $executionNote }}</p>
  </x-card>

  {{-- ============================================================= --}}
  {{-- Parâmetros de Entrada                                          --}}
  {{-- ============================================================= --}}
  <x-card :eyebrow="__('Parâmetros de entrada')" :title="__('O que foi pesquisado')"
     :subtitle="__('Identificadores informados e módulos aplicados nesta execução.')">
   @if ($inputFields->isEmpty())
   <p class="text-sm text-mist-400">{{ __('Nenhum parâmetro de entrada registrado.') }}</p>
   @else
   <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
    @foreach ($inputFields as $key => $value)
    <div class="surface-2 px-4 py-3">
     <dt class="text-[0.6875rem] font-semibold uppercase tracking-wider text-mist-500">
     {{ $inputLabels[$key] ?? $key }}
     </dt>
     <dd class="mt-1 break-all text-sm text-mist-100">{{ $value }}</dd>
    </div>
    @endforeach
   </dl>
   @endif

   @if ($moduleSlugs !== [])
   <div class="mt-4">
    <p class="text-[0.6875rem] font-semibold uppercase tracking-wider text-mist-500">{{ __('Módulos aplicados') }}</p>
    <div class="mt-2 flex flex-wrap gap-1.5">
     @foreach ($moduleSlugs as $slug)
     <x-badge>{{ $slug }}</x-badge>
     @endforeach
    </div>
   </div>
   @endif
  </x-card>

  {{-- ============================================================= --}}
  {{-- Resultados por Módulo                                          --}}
  {{-- ============================================================= --}}
  <x-card :eyebrow="__('Resultados')" :title="__('Resultados por módulo')"
     :subtitle="__('Somente dados retornados pelas fontes. Nada é simulado ou preenchido artificialmente.')">
   @if ($sourceRuns->isEmpty())
   @if ($isProcessing)
    <x-skeleton :lines="3" height="h-12" />
    <p class="mt-3 text-sm text-mist-400">{{ __('As fontes aparecem aqui assim que a execução começar.') }}</p>
   @else
    <x-empty-state
    :title="__('Nenhuma fonte foi executada nesta investigação.')"
    :description="__('O run não registrou execuções de módulo.')" />
   @endif
   @else
   <div class="space-y-5">
    @foreach ($sourceRuns as $sourceRun)
    @php
     $name = $sourceRun->sourceDefinition?->name ?? $sourceRun->source;
     $slug = $sourceRun->source;
    @endphp
    <div class="border-t border-line/70 pt-5 first:border-t-0 first:pt-0">
     <x-source-status :run="$sourceRun" />

     @if ($sourceRun->status === 'completed' && $sourceRun->sourceResults->isNotEmpty())
     <div class="mt-3 grid gap-3 sm:grid-cols-2">
      @foreach ($sourceRun->sourceResults as $result)
      @continue(! empty($result->metadata['duplicate_of']))
      @include('investigations.partials.result-card', ['result' => $result, 'sourceSlug' => $slug])
      @endforeach
     </div>
     @elseif ($sourceRun->status === 'completed')
     <div class="mt-3">
      <x-empty-state
      :title="__('Nenhuma correspondência encontrada em :source.', ['source' => $name])"
      :description="__('A fonte foi consultada, mas não retornou resultados para este identificador.')" />
     </div>
     @elseif (in_array($sourceRun->status, ['failed', 'timeout'], true))
     <p class="mt-3 text-sm text-rose-200">
      {{ __('Fonte indisponível:') }}
      {{ $sourceRun->error_message_public ?: $sourceRun->displayStatus() }}
     </p>
     @elseif ($sourceRun->status === 'skipped')
     <p class="mt-3 text-sm text-mist-400">{{ __('Módulo não aplicável aos dados informados.') }}</p>
     @else
     <p class="mt-3 text-sm text-mist-400">{{ __('Aguardando processamento…') }}</p>
     @endif
    </div>
    @endforeach
   </div>
   @endif
  </x-card>

  {{-- ============================================================= --}}
  {{-- Evidências / Enriquecimento                                    --}}
  {{-- ============================================================= --}}
  <x-card :eyebrow="__('Evidências e enriquecimento')" :title="__('Perfis enriquecidos')"
     :subtitle="__('Metadados públicos consolidados a partir do username confirmado pelas fontes.')">
   @if ($enrichedProfiles->isEmpty())
   @if ($isProcessing)
    <x-skeleton :lines="2" height="h-16" />
    <p class="mt-3 text-sm text-mist-400">{{ __('O enriquecimento de perfil aparece aqui quando concluído.') }}</p>
   @else
    <x-empty-state
    :title="__('Nenhum perfil enriquecido nesta execução.')"
    :description="__('O enriquecimento depende de um username confirmado e da integração do Instagram. Uma correspondência de username não confirma identidade.')" />
   @endif
   @else
   <div class="grid gap-4 sm:grid-cols-2">
    @foreach ($enrichedProfiles as $profile)
    @php
     $avatar = $profile->evidence->first(
      fn ($evidence) => $evidence->type === 'avatar' && str_starts_with(strtolower((string) $evidence->value), 'https://')
     );
    @endphp
    <div class="surface-2 flex gap-4 p-4">
     @if ($avatar)
     <a href="{{ $avatar->value }}" target="_blank" rel="noopener noreferrer" class="shrink-0">
      <img src="{{ $avatar->value }}"
        alt="{{ __('Imagem pública do perfil') }} {{ $profile->value }}"
        loading="lazy" referrerpolicy="no-referrer"
        class="h-20 w-20 rounded-xl border border-line/40 bg-ink-700/40 object-cover" />
     </a>
     @else
     <span class="flex h-20 w-20 shrink-0 items-center justify-center rounded-xl border border-dashed border-line text-mist-500" aria-hidden="true">
      <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
       <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.25a7.5 7.5 0 0 1 15 0" />
      </svg>
     </span>
     @endif

     <div class="min-w-0">
      <p class="truncate font-medium text-mist-100">{{ $profile->title ?: $profile->value }}</p>
      <p class="mt-0.5 font-mono text-[0.6875rem] text-mist-500">@{{ $profile->value }}</p>

      @if ($profile->description)
      <p class="mt-2 line-clamp-3 text-sm text-mist-300">{{ $profile->description }}</p>
      @else
      <p class="mt-2 text-sm text-mist-500">{{ __('Bio pública não disponível.') }}</p>
      @endif

      @if ($profile->url)
      <a href="{{ $profile->url }}" target="_blank" rel="noopener noreferrer nofollow"
        class="mt-2 inline-block break-all text-xs text-signal-300 underline decoration-signal-500/40">
       {{ $profile->url }}
      </a>
      @endif
     </div>
    </div>
    @endforeach
   </div>
   @endif

   <p class="mt-4 text-xs leading-relaxed text-mist-500">
   {{ __('Metadados públicos obtidos com a conta de serviço. Uma correspondência de username não confirma identidade.') }}
   </p>
  </x-card>

  {{-- ============================================================= --}}
  {{-- Execuções (histórico auditável) + relatório                    --}}
  {{-- ============================================================= --}}
  <x-card :eyebrow="__('Execuções')" :title="__('Histórico de execuções')">
   <ul class="divide-y divide-line/70">
   @foreach ($investigation->runs as $historyRun)
    <li class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0">
    <div>
     <p class="text-sm font-medium text-mist-100">{{ __('Run') }} #{{ $historyRun->run_number }}</p>
     <p class="mt-0.5 font-mono text-[0.6875rem] text-mist-500">
     {{ __('créditos') }} {{ $historyRun->credits_consumed }} / {{ $historyRun->credits_reserved }}
     </p>
    </div>
    <div class="flex items-center gap-4">
     <x-status-badge :status="$historyRun->status" />
     @if ($historyRun->report)
     <a href="{{ route('investigations.report', [$investigation, $historyRun]) }}"
       class="text-sm text-signal-300 underline decoration-signal-500/40 hover:text-signal-300">
      {{ __('Relatório') }}
     </a>
     @endif
    </div>
    </li>
   @endforeach
   </ul>

   @if (in_array($investigation->status, ['completed', 'partial', 'failed', 'cancelled', 'expired'], true))
   <form method="POST" action="{{ route('investigations.rerun', $investigation) }}" class="mt-5 border-t border-line/70 pt-5">
    @csrf
    <x-btn type="submit" variant="primary">{{ __('Reexecutar investigação') }}</x-btn>
    <span class="ml-2 text-xs text-mist-500">
     {{ __('Cria um novo run com custo independente; o run anterior permanece auditável.') }}
    </span>
   </form>
   @endif
  </x-card>

  <a href="{{ $backUrl }}" class="inline-block text-sm text-mist-300 transition hover:text-mist-100">
  &larr; {{ __('Voltar para minhas investigações') }}
  </a>
  </div>
 </div>

 @include('investigations.partials.polling')
</x-app-layout>

<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">
  {{ $investigation->title }}
  </h2>
 </x-slot>

 <div class="py-12">
  <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
  <div class="overflow-hidden surface">
   <div class="p-6">
    <div class="flex items-center justify-between">
    <p class="text-sm text-mist-400">
     {{ __('Status') }}:
     <span class="font-semibold">{{ __((string) $investigation->status) }}</span>
    </p>
    <p class="text-sm text-mist-400">{{ $investigation->created_at->format('d/m/Y H:i') }}</p>
    </div>

    @if (session('status'))
    <p class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-800">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
    <ul class="mt-4 rounded-md bg-rose-950/40 p-3 text-sm text-red-700">
     @foreach ($errors->all() as $error)
      <li>{{ $error }}</li>
     @endforeach
    </ul>
    @endif

    @if (in_array($investigation->status, ['completed', 'partial', 'failed', 'cancelled', 'expired'], true))
    <form method="POST" action="{{ route('investigations.rerun', $investigation) }}" class="mt-4">
     @csrf
     <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
      {{ __('Reexecutar investigação') }}
     </button>
     <span class="ml-2 text-xs text-mist-400">
      {{ __('Cria um novo run com custo independente; o run anterior permanece auditável.') }}
     </span>
    </form>
    @endif

    <h3 class="mt-6 text-lg font-semibold text-mist-100">{{ __('Execuções') }}</h3>
    <ul class="mt-3 divide-y divide-gray-200">
    @foreach ($investigation->runs as $run)
     <li class="flex items-center justify-between py-3">
      <div>
      <p class="text-sm font-medium text-mist-100">{{ __('Run') }} #{{ $run->run_number }}</p>
      <p class="text-sm text-mist-400">{{ __('Status') }}: {{ __((string) $run->status) }}</p>
      </div>
      <div class="flex items-center gap-4">
      <p class="text-sm text-mist-400">
       {{ __('Créditos') }}: {{ $run->credits_consumed }} / {{ $run->credits_reserved }}
      </p>
      @if ($run->report)
       <a href="{{ route('investigations.report', [$investigation, $run]) }}" class="text-sm text-signal-300 underline decoration-signal-500/40 hover:text-signal-300">
        {{ __('Relatório') }}
       </a>
      @endif
      </div>
     </li>
    @endforeach
    </ul>

    <h3 class="mt-6 text-lg font-semibold text-mist-100">{{ __('Entradas') }}</h3>
    <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
    @foreach ($investigation->runs->last()->input_snapshot ?? [] as $key => $value)
     @if (is_scalar($value))
      <dt class="font-medium text-mist-400">{{ $key }}</dt>
      <dd class="text-mist-100">{{ $value ?? '—' }}</dd>
     @endif
    @endforeach
    </dl>

    <h3 class="mt-6 text-lg font-semibold text-mist-100">{{ __('Resultados') }}</h3>
    @foreach ($investigation->runs as $run)
    @foreach ($run->sourceRuns as $sourceRun)
     <div class="mt-4 border-t border-line/70 pt-4">
      <div class="flex items-center justify-between">
      <p class="font-medium text-mist-100">{{ $sourceRun->sourceDefinition?->name ?? $sourceRun->source_definition_id }}</p>
      <span class="text-sm text-mist-400">{{ $sourceRun->displayStatus() }}</span>
      </div>

      @foreach ($sourceRun->sourceResults as $result)
      <div class="mt-3 rounded-md bg-ink-850/60 p-3">
       <p class="font-medium text-mist-100">{{ $result->title ?? $result->value }}</p>
       @if ($result->url)
        <a href="{{ $result->url }}" target="_blank" rel="noopener noreferrer" class="break-all text-sm text-signal-300 underline decoration-signal-500/40 hover:text-signal-300">{{ $result->url }}</a>
       @endif
       <p class="mt-1 text-xs text-mist-400">{{ $result->observed_at?->format('d/m/Y H:i') }}</p>
       <div class="mt-2 flex flex-wrap gap-1">
        @foreach ($result->evidence as $evidence)
        <span class="rounded bg-ink-700/70 px-2 py-0.5 text-xs text-mist-300">
         {{ $evidence->type }}: {{ $evidence->value }}
        </span>
        @endforeach
       </div>
      </div>
      @endforeach
     </div>
    @endforeach
    @endforeach

    <a href="{{ route('investigations.index') }}" class="mt-6 inline-block text-sm text-mist-300 hover:text-mist-100">
    &larr; {{ __('Voltar') }}
    </a>
   </div>
  </div>
  </div>
 </div>
</x-app-layout>

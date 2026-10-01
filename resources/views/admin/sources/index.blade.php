<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">{{ __('Fontes') }}</h2>
 </x-slot>

 <div class="py-12">
  <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
  @include('admin.partials.nav')

  @if (session('status'))
   <p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>
  @endif

  @if ($errors->has('cost_credits'))
   <p class="mb-4 text-sm text-red-400">{{ $errors->first('cost_credits') }}</p>
  @endif

  <p class="mb-4 text-xs text-mist-500">
   {{ __('Custo em créditos por requisição, aplicado às próximas execuções. Execuções já finalizadas mantêm o custo que foi cobrado na época. Zero créditos faz o módulo executar sem consumo.') }}
  </p>

  <div class="overflow-hidden surface">
   <table class="min-w-full divide-y divide-gray-200 text-sm">
    <thead class="bg-ink-850/60">
    <tr>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Nome') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Slug') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Tipo') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Créditos por requisição') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Ativo') }}</th>
     <th class="px-4 py-2"></th>
    </tr>
    </thead>
    <tbody class="divide-y divide-gray-200">
    @foreach ($sources as $source)
     @php
        $lastChange = $lastCostChanges->get($source->id);
        $failedRow = $errors->has('cost_credits') && (string) old('source_id') === (string) $source->id;
        $costValue = $failedRow ? old('cost_credits') : $source->cost_credits;
     @endphp
     <tr>
      <td class="px-4 py-3">{{ $source->name }}</td>
      <td class="px-4 py-3">{{ $source->slug }}</td>
      <td class="px-4 py-3">{{ $source->type }}</td>
      <td class="px-4 py-3">
       <form method="POST" action="{{ route('admin.sources.update-cost', $source) }}" class="flex items-center gap-2">
        @csrf
        @method('PATCH')
        <input type="hidden" name="source_id" value="{{ $source->id }}">
        <input
         type="number"
         name="cost_credits"
         min="0"
         max="1000"
         step="1"
         inputmode="numeric"
         value="{{ $costValue }}"
         aria-label="{{ __('Créditos por requisição') }} — {{ $source->name }}"
         class="field w-24 !py-1.5 text-sm"
        >
        <button type="submit" class="text-signal-300 underline decoration-signal-500/40 hover:text-signal-300">
         {{ __('Salvar') }}
        </button>
       </form>
       @if ($lastChange)
        <p class="mt-1 text-xs text-mist-500">
         {{ $lastChange->created_at?->format('d/m/Y H:i') }} · {{ $lastChange->user?->email ?? '—' }}:
         {{ $lastChange->metadata['previous_cost_credits'] ?? '—' }} → {{ $lastChange->metadata['new_cost_credits'] ?? '—' }}
        </p>
       @endif
      </td>
      <td class="px-4 py-3">{{ $source->active ? __('Sim') : __('Não') }}</td>
      <td class="px-4 py-3 text-right">
      <form method="POST" action="{{ route('admin.sources.toggle', $source) }}">
       @csrf
       <button type="submit" class="text-signal-300 underline decoration-signal-500/40 hover:text-signal-300">
        {{ $source->active ? __('Desativar') : __('Ativar') }}
       </button>
      </form>
      </td>
     </tr>
    @endforeach
    </tbody>
   </table>
  </div>
  </div>
 </div>
</x-app-layout>

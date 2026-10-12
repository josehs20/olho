<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">{{ __('Testes de módulos') }}</h2>
 </x-slot>

 <div class="py-12">
  <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
  @include('admin.partials.nav')

  @if (session('status'))
   <p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>
  @endif

  <p class="mb-4 text-xs text-mist-500">
   {{ __('Executa um módulo por vez, com o adapter, o worker e as regras de crédito reais. Nenhum outro módulo, correlação ou relatório é disparado. Créditos disponíveis na sua conta: :balance.', ['balance' => $balance]) }}
  </p>

  <form method="POST" action="{{ route('admin.tests.store') }}" class="overflow-hidden surface">
   @csrf

   <div class="space-y-6 p-6">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
     <div>
      <label for="module" class="block text-sm font-medium text-mist-300">{{ __('Módulo') }}</label>
      <select id="module" name="module" class="field mt-1">
       @foreach ($moduleOptions as $option)
        <option
         value="{{ $option['slug'] }}"
         data-input="{{ $option['input'] }}"
         @selected(old('module') === $option['slug'])
        >{{ $option['name'] }}@unless ($option['active']) — {{ __('inativo') }}@endunless ({{ $option['cost'] }} {{ __('créditos') }})</option>
       @endforeach
      </select>
      @error('module')
       <p class="mt-1 text-sm text-rose-300">{{ $message }}</p>
      @enderror
     </div>

     <div>
      <label for="target" id="target-label" class="block text-sm font-medium text-mist-300">{{ __('Username') }}</label>
      <input
       id="target"
       name="target"
       type="text"
       value="{{ old('target') }}"
       autocomplete="off"
       spellcheck="false"
       placeholder="{{ __('exemplo') }}"
       class="field mt-1"
      >
      @error('target')
       <p class="mt-1 text-sm text-rose-300">{{ $message }}</p>
      @enderror
     </div>
    </div>

    <button type="submit"
     class="inline-flex items-center justify-center rounded-md bg-ink-700 px-4 py-2 text-sm font-semibold text-white hover:border-signal-500/50">
     {{ __('EXECUTAR TESTE') }}
    </button>
   </div>
  </form>

  <div class="mt-8 flex flex-wrap items-center justify-between gap-2">
   <h3 class="text-sm font-semibold text-mist-200">{{ __('Histórico') }}</h3>

   <form method="GET" action="{{ route('admin.tests.index') }}" class="flex items-center gap-2">
    <label for="filter-module" class="text-xs text-mist-500">{{ __('Módulo') }}</label>
    <select id="filter-module" name="module" class="field !py-1 text-xs" onchange="this.form.submit()">
     <option value="">{{ __('Todos') }}</option>
     @foreach ($moduleOptions as $option)
      <option value="{{ $option['slug'] }}" @selected($filter === $option['slug'])>{{ $option['name'] }}</option>
     @endforeach
    </select>
   </form>
  </div>

  <div class="mt-3 overflow-hidden surface">
   <table class="min-w-full divide-y divide-gray-200 text-sm">
    <thead class="bg-ink-850/60">
    <tr>
     <th class="px-3 py-2 text-left text-mist-400">{{ __('ID') }}</th>
     <th class="px-3 py-2 text-left text-mist-400">{{ __('Módulo') }}</th>
     <th class="px-3 py-2 text-left text-mist-400">{{ __('Alvo') }}</th>
     <th class="px-3 py-2 text-left text-mist-400">{{ __('Status') }}</th>
     <th class="px-3 py-2 text-left text-mist-400">{{ __('Duração') }}</th>
     <th class="px-3 py-2 text-left text-mist-400">{{ __('Resultados') }}</th>
     <th class="px-3 py-2 text-left text-mist-400">{{ __('Créditos') }}</th>
     <th class="px-3 py-2 text-left text-mist-400">{{ __('Data') }}</th>
     <th class="px-3 py-2"></th>
    </tr>
    </thead>
    <tbody class="divide-y divide-gray-200">
    @forelse ($history as $test)
     <tr>
      <td class="px-3 py-3 text-mist-400">#{{ $test->id }}</td>
      <td class="px-3 py-3">{{ $test->sourceDefinition?->name ?? $test->module }}</td>
      <td class="px-3 py-3 break-all">{{ $test->target }}</td>
      <td class="px-3 py-3">@include('admin.tests.partials.status', ['status' => $test->statusEnum()])</td>
      <td class="px-3 py-3">{{ \App\Models\ModuleTest::formatDuration($test->durationMs()) }}</td>
      <td class="px-3 py-3">{{ (int) $test->result_count }}</td>
      <td class="px-3 py-3">
       {{ (int) $test->credits_cost }}
       @if ((int) $test->credits_cost === 0)
        <span class="text-xs text-mist-500">{{ __('(sem consumo)') }}</span>
       @endif
      </td>
      <td class="px-3 py-3 text-mist-400">{{ $test->created_at?->format('d/m/Y H:i') }}</td>
      <td class="px-3 py-3 text-right">
       <div class="flex items-center justify-end gap-3">
        <a href="{{ route('admin.tests.show', $test) }}" class="text-signal-300 underline decoration-signal-500/40 hover:text-signal-300">
         {{ __('Ver detalhes') }}
        </a>
        <form method="POST" action="{{ route('admin.tests.rerun', $test) }}">
         @csrf
         <button type="submit" class="text-signal-300 underline decoration-signal-500/40 hover:text-signal-300">
          {{ __('Executar novamente') }}
         </button>
        </form>
       </div>
      </td>
     </tr>
    @empty
     <tr>
      <td colspan="9" class="px-3 py-6 text-center text-mist-500">{{ __('Nenhum teste executado ainda.') }}</td>
     </tr>
    @endforelse
    </tbody>
   </table>
  </div>

  <div class="mt-4">
   {{ $history->links() }}
  </div>
  </div>
 </div>

 @push('scripts')
  <script>
   // O campo de entrada acompanha o módulo selecionado (username ou e-mail),
   // usando o tipo declarado pela própria fonte — nenhuma regra paralela.
   (function () {
    var select = document.getElementById('module');
    var label = document.getElementById('target-label');
    var input = document.getElementById('target');

    if (! select || ! label || ! input) {
     return;
    }

    var usernameLabel = @json(__('Username'));
    var emailLabel = @json(__('E-mail'));
    var usernamePlaceholder = @json(__('exemplo'));
    var emailPlaceholder = @json(__('exemplo@email.com'));

    var sync = function () {
     var option = select.options[select.selectedIndex];
     var field = option ? option.getAttribute('data-input') : 'username';
     var isEmail = field === 'email';

     label.textContent = isEmail ? emailLabel : usernameLabel;
     input.type = isEmail ? 'email' : 'text';
     input.placeholder = isEmail ? emailPlaceholder : usernamePlaceholder;
    };

    select.addEventListener('change', sync);
    sync();
   })();
  </script>
 @endpush
</x-app-layout>

<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">
  {{ __('Nova investigação') }}
  </h2>
 </x-slot>

 <div class="py-12">
  <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
  <form method="POST" action="{{ route('investigations.store') }}" class="overflow-hidden surface">
   @csrf

   <div class="space-y-6 p-6">
    <div>
    <label for="title" class="block text-sm font-medium text-mist-300">{{ __('Título (opcional)') }}</label>
    <input id="title" name="title" type="text" value="{{ old('title') }}"
     class="field mt-1">
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
     <label for="name" class="block text-sm font-medium text-mist-300">{{ __('Nome') }}</label>
     <input id="name" name="name" type="text" value="{{ old('name') }}"
      class="field mt-1">
    </div>

    <div>
     <label for="username" class="block text-sm font-medium text-mist-300">{{ __('Username') }}</label>
     <input id="username" name="username" type="text" value="{{ old('username') }}"
      class="field mt-1">
     @error('username')
      <p class="mt-1 text-sm text-rose-300">{{ $message }}</p>
     @enderror
    </div>

    <div>
     <label for="email" class="block text-sm font-medium text-mist-300">{{ __('E-mail') }}</label>
     <input id="email" name="email" type="email" value="{{ old('email') }}"
      class="field mt-1">
    </div>

    <div>
     <label for="phone" class="block text-sm font-medium text-mist-300">{{ __('Telefone') }}</label>
     <input id="phone" name="phone" type="text" value="{{ old('phone') }}"
      class="field mt-1">
    </div>

    <div>
     <label for="city" class="block text-sm font-medium text-mist-300">{{ __('Cidade') }}</label>
     <input id="city" name="city" type="text" value="{{ old('city') }}"
      class="field mt-1">
    </div>

    <div>
     <label for="state" class="block text-sm font-medium text-mist-300">{{ __('UF') }}</label>
     <input id="state" name="state" type="text" maxlength="2" value="{{ old('state') }}"
      class="field mt-1">
    </div>
    </div>

    <div>
    <p class="text-sm font-medium text-mist-300">{{ __('Módulos') }}</p>
    @if ($modules->isEmpty())
     <p class="mt-1 text-sm text-mist-400">{{ __('Nenhum módulo disponível.') }}</p>
    @else
     @php
      $oldModules = old('modules', []);
      $moduleSlugs = $modules->pluck('slug')->all();
      $selectedModules = is_array($oldModules)
          ? array_values(array_intersect($moduleSlugs, $oldModules))
          : [];
     @endphp
     <div class="mt-2 space-y-2"
      x-data="{ all: @js($moduleSlugs), selected: @js($selectedModules) }">
      <label class="flex items-center gap-2 border-b border-line/70 pb-2">
       <input type="checkbox" x-ref="selectAll" class="rounded border-line text-signal-300"
        x-effect="$el.indeterminate = selected.length > 0 && selected.length < all.length"
        :checked="all.length > 0 && selected.length === all.length"
        x-on:change="selected = $event.target.checked ? [...all] : []">
       <span class="text-sm font-medium text-mist-200">{{ __('Selecionar todos os módulos') }}</span>
      </label>
      @foreach ($modules as $module)
      <label class="flex items-center gap-2">
       <input type="checkbox" name="modules[]" value="{{ $module->slug }}" x-model="selected"
        @checked(in_array($module->slug, $selectedModules))
        class="rounded border-line text-signal-300">
       <span class="text-sm text-mist-300">
        {{ $module->name }}
        <span class="text-mist-500">({{ $module->cost_credits }} {{ __('créditos') }})</span>
       </span>
      </label>
      @endforeach
     </div>
    @endif
    @error('modules')
     <p class="mt-1 text-sm text-rose-300">{{ $message }}</p>
    @enderror
    </div>

    <button type="submit"
    class="inline-flex w-full items-center justify-center rounded-md bg-ink-700 px-4 py-2 text-sm font-semibold text-white hover:border-signal-500/50">
    {{ __('Criar investigação') }}
    </button>
   </div>
  </form>
  </div>
 </div>
</x-app-layout>

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
     class="mt-1 block w-full rounded-md border-line focus:border-signal-400 focus:ring-signal-400 sm:text-sm">
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
     <label for="name" class="block text-sm font-medium text-mist-300">{{ __('Nome') }}</label>
     <input id="name" name="name" type="text" value="{{ old('name') }}"
      class="mt-1 block w-full rounded-md border-line focus:border-signal-400 focus:ring-signal-400 sm:text-sm">
    </div>

    <div>
     <label for="username" class="block text-sm font-medium text-mist-300">{{ __('Username') }}</label>
     <input id="username" name="username" type="text" value="{{ old('username') }}"
      class="mt-1 block w-full rounded-md border-line focus:border-signal-400 focus:ring-signal-400 sm:text-sm">
     @error('username')
      <p class="mt-1 text-sm text-rose-300">{{ $message }}</p>
     @enderror
    </div>

    <div>
     <label for="email" class="block text-sm font-medium text-mist-300">{{ __('E-mail') }}</label>
     <input id="email" name="email" type="email" value="{{ old('email') }}"
      class="mt-1 block w-full rounded-md border-line focus:border-signal-400 focus:ring-signal-400 sm:text-sm">
    </div>

    <div>
     <label for="phone" class="block text-sm font-medium text-mist-300">{{ __('Telefone') }}</label>
     <input id="phone" name="phone" type="text" value="{{ old('phone') }}"
      class="mt-1 block w-full rounded-md border-line focus:border-signal-400 focus:ring-signal-400 sm:text-sm">
    </div>

    <div>
     <label for="city" class="block text-sm font-medium text-mist-300">{{ __('Cidade') }}</label>
     <input id="city" name="city" type="text" value="{{ old('city') }}"
      class="mt-1 block w-full rounded-md border-line focus:border-signal-400 focus:ring-signal-400 sm:text-sm">
    </div>

    <div>
     <label for="state" class="block text-sm font-medium text-mist-300">{{ __('UF') }}</label>
     <input id="state" name="state" type="text" maxlength="2" value="{{ old('state') }}"
      class="mt-1 block w-full rounded-md border-line focus:border-signal-400 focus:ring-signal-400 sm:text-sm">
    </div>
    </div>

    <div>
    <p class="text-sm font-medium text-mist-300">{{ __('Módulos') }}</p>
    @if ($modules->isEmpty())
     <p class="mt-1 text-sm text-mist-400">{{ __('Nenhum módulo disponível.') }}</p>
    @else
     <div class="mt-2 space-y-2">
      @foreach ($modules as $module)
      <label class="flex items-center gap-2">
       <input type="checkbox" name="modules[]" value="{{ $module->slug }}"
        @checked(in_array($module->slug, old('modules', [])))
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

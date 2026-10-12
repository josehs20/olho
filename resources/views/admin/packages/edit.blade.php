<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">
  {{ $package->isGuestKind() ? __('Editar preço da investigação avulsa') : __('Editar pacote') }}
  </h2>
 </x-slot>

 <div class="py-12">
  <div class="mx-auto max-w-lg sm:px-6 lg:px-8">
  @include('admin.partials.nav')

  @if ($package->isGuestKind())
   <p class="mb-4 text-sm text-mist-400">
    {{ __('Consulta avulsa, sem cadastro: preço fixo pago via PIX, sem consumo de créditos. O novo valor vale para as próximas investigações — as já criadas mantêm o preço registrado.') }}
   </p>
  @endif

  <form method="POST" action="{{ route('admin.packages.update', $package) }}" class="space-y-4 surface p-6">
   @csrf
   @method('PUT')
   <div>
    <label class="block text-sm font-medium text-mist-300">{{ __('Nome') }}</label>
    <input type="text" name="name" value="{{ old('name', $package->name) }}" class="field mt-1">
    @error('name') <p class="mt-1 text-sm text-rose-300">{{ $message }}</p> @enderror
   </div>

   @unless ($package->isGuestKind())
    <div>
    <label class="block text-sm font-medium text-mist-300">{{ __('Créditos') }}</label>
    <input type="number" name="credits" value="{{ old('credits', $package->credits) }}" class="field mt-1">
    @error('credits') <p class="mt-1 text-sm text-rose-300">{{ $message }}</p> @enderror
    </div>
   @endunless

   <div>
    <label class="block text-sm font-medium text-mist-300">{{ __('Preço em centavos') }}</label>
    <input type="number" name="price_cents" value="{{ old('price_cents', $package->price_cents) }}" class="field mt-1">
    @error('price_cents') <p class="mt-1 text-sm text-rose-300">{{ $message }}</p> @enderror
   </div>

   <label class="flex items-center gap-2 text-sm text-mist-300">
    <input type="checkbox" name="active" value="1" @checked(old('active', $package->active)) class="rounded border-line">
    {{ __('Ativo') }}
   </label>

   <button type="submit" class="rounded-md bg-ink-700 px-4 py-2 text-sm text-white">{{ __('Salvar') }}</button>
  </form>
  </div>
 </div>
</x-app-layout>

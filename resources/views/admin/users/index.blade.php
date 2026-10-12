<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">{{ __('Usuários') }}</h2>
 </x-slot>

 <div class="py-12">
  <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
  @include('admin.partials.nav')

  @if (session('status'))
   <p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>
  @endif

  <form method="GET" class="mb-4">
   <input type="text" name="q" value="{{ request('q') }}" placeholder="{{ __('Buscar nome ou e-mail') }}"
    class="field w-64">
   <button type="submit" class="ml-2 rounded-md bg-ink-700 px-3 py-1.5 text-sm text-white">{{ __('Buscar') }}</button>
  </form>

  <div class="overflow-hidden surface">
   <table class="min-w-full divide-y divide-gray-200 text-sm">
    <thead class="bg-ink-850/60">
    <tr>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Nome') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('E-mail') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Saldo') }}</th>
     <th class="px-4 py-2 text-left text-mist-400">{{ __('Ajuste de créditos') }}</th>
    </tr>
    </thead>
    <tbody class="divide-y divide-gray-200">
    @foreach ($users as $user)
     <tr>
      <td class="px-4 py-3">{{ $user->name }}</td>
      <td class="px-4 py-3">{{ $user->email }}</td>
      <td class="px-4 py-3">{{ $user->wallet?->balance ?? 0 }}</td>
      <td class="px-4 py-3">
      <form method="POST" action="{{ route('admin.users.adjust-credits', $user) }}" class="flex items-center gap-2">
       @csrf
       <input type="number" name="amount" required placeholder="{{ __('Valor') }}"
        class="field w-24">
       <input type="text" name="reason" required placeholder="{{ __('Motivo') }}"
        class="field flex-1 min-w-0">
       <button type="submit" class="rounded-md bg-ink-700 px-3 py-1.5 text-white">{{ __('Ajustar') }}</button>
      </form>
      </td>
     </tr>
    @endforeach
    </tbody>
   </table>
  </div>

  <div class="mt-4">{{ $users->links() }}</div>
  </div>
 </div>
</x-app-layout>

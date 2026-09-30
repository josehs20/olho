<x-app-layout>
 <x-slot name="header">
  <h2 class="text-xl font-semibold leading-tight text-mist-100">
  {{ __('Minhas investigações') }}
  </h2>
 </x-slot>

 <div class="py-12">
  <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
  <div class="mb-6">
   <a href="{{ route('investigations.create') }}"
    class="inline-flex items-center rounded-md bg-ink-700 px-4 py-2 text-sm font-semibold text-white hover:border-signal-500/50">
    {{ __('Nova investigação') }}
   </a>
  </div>

  <div class="overflow-hidden surface">
   @if ($investigations->isEmpty())
    <div class="p-6 text-mist-100">{{ __('Nenhuma investigação ainda.') }}</div>
   @else
    <ul class="divide-y divide-gray-200">
    @foreach ($investigations as $investigation)
     <li class="p-6">
      <a href="{{ route('investigations.show', $investigation) }}" class="flex items-center justify-between">
      <div>
       <p class="font-medium text-mist-100">{{ $investigation->title }}</p>
       <p class="text-sm text-mist-400">
        {{ $investigation->currentRun?->input_snapshot['username'] ?? '—' }}
        · {{ $investigation->created_at->diffForHumans() }}
       </p>
      </div>
      <span class="rounded-full px-3 py-1 text-xs font-semibold bg-ink-800/70 text-mist-300">
       {{ __((string) $investigation->status) }}
      </span>
      </a>
     </li>
    @endforeach
    </ul>
   @endif
  </div>

  <div class="mt-6">
   {{ $investigations->links() }}
  </div>
  </div>
 </div>
</x-app-layout>

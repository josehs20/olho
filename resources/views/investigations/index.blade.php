<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            {{ __('Minhas investigações') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            <div class="mb-6">
                <a href="{{ route('investigations.create') }}"
                    class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-700">
                    {{ __('Nova investigação') }}
                </a>
            </div>

            <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                @if ($investigations->isEmpty())
                    <div class="p-6 text-gray-900">{{ __('Nenhuma investigação ainda.') }}</div>
                @else
                    <ul class="divide-y divide-gray-200">
                        @foreach ($investigations as $investigation)
                            <li class="p-6">
                                <a href="{{ route('investigations.show', $investigation) }}" class="flex items-center justify-between">
                                    <div>
                                        <p class="font-medium text-gray-900">{{ $investigation->title }}</p>
                                        <p class="text-sm text-gray-500">
                                            {{ $investigation->currentRun?->input_snapshot['username'] ?? '—' }}
                                            · {{ $investigation->created_at->diffForHumans() }}
                                        </p>
                                    </div>
                                    <span class="rounded-full px-3 py-1 text-xs font-semibold bg-gray-100 text-gray-700">
                                        {{ $investigation->status }}
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

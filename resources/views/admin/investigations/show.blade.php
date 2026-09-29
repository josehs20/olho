<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Investigação') }} #{{ $investigation->id }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
            @include('admin.partials.nav')

            <div class="rounded-lg bg-white p-6 shadow-sm">
                <p class="text-sm text-gray-500">{{ __('Usuário') }}: {{ $investigation->user?->email }} · {{ $investigation->status }}</p>

                <h3 class="mt-4 text-lg font-semibold">{{ __('Execuções') }}</h3>
                @foreach ($investigation->runs as $run)
                    <div class="mt-4 border-t border-gray-100 pt-4">
                        <p class="font-medium">{{ __('Run') }} #{{ $run->run_number }} — {{ $run->status }}</p>
                        @foreach ($run->sourceRuns as $sourceRun)
                            <div class="mt-2 flex items-center justify-between text-sm">
                                <span>{{ $sourceRun->sourceDefinition?->name ?? $sourceRun->source_definition_id }}</span>
                                <span class="text-gray-500">
                                    {{ $sourceRun->status }}
                                    @if ($sourceRun->error_code) ({{ $sourceRun->error_code }}) @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>

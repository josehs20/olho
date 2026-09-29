<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            {{ $investigation->title }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
            <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-gray-500">
                            {{ __('Status') }}:
                            <span class="font-semibold">{{ $investigation->status }}</span>
                        </p>
                        <p class="text-sm text-gray-500">{{ $investigation->created_at->format('d/m/Y H:i') }}</p>
                    </div>

                    <h3 class="mt-6 text-lg font-semibold text-gray-900">{{ __('Execuções') }}</h3>
                    <ul class="mt-3 divide-y divide-gray-200">
                        @foreach ($investigation->runs as $run)
                            <li class="flex items-center justify-between py-3">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ __('Run') }} #{{ $run->run_number }}</p>
                                    <p class="text-sm text-gray-500">{{ __('Status') }}: {{ $run->status }}</p>
                                </div>
                                <p class="text-sm text-gray-500">
                                    {{ __('Créditos') }}: {{ $run->credits_consumed }} / {{ $run->credits_reserved }}
                                </p>
                            </li>
                        @endforeach
                    </ul>

                    <h3 class="mt-6 text-lg font-semibold text-gray-900">{{ __('Entradas') }}</h3>
                    <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        @foreach ($investigation->runs->last()->input_snapshot ?? [] as $key => $value)
                            @if (is_scalar($value))
                                <dt class="font-medium text-gray-500">{{ $key }}</dt>
                                <dd class="text-gray-900">{{ $value ?? '—' }}</dd>
                            @endif
                        @endforeach
                    </dl>

                    <h3 class="mt-6 text-lg font-semibold text-gray-900">{{ __('Resultados') }}</h3>
                    @foreach ($investigation->runs as $run)
                        @foreach ($run->sourceRuns as $sourceRun)
                            <div class="mt-4 border-t border-gray-100 pt-4">
                                <div class="flex items-center justify-between">
                                    <p class="font-medium text-gray-900">{{ $sourceRun->sourceDefinition?->name ?? $sourceRun->source_definition_id }}</p>
                                    <span class="text-sm text-gray-500">{{ $sourceRun->displayStatus() }}</span>
                                </div>

                                @foreach ($sourceRun->sourceResults as $result)
                                    <div class="mt-3 rounded-md bg-gray-50 p-3">
                                        <p class="font-medium text-gray-900">{{ $result->title ?? $result->value }}</p>
                                        @if ($result->url)
                                            <a href="{{ $result->url }}" target="_blank" rel="noopener noreferrer" class="break-all text-sm text-indigo-600 hover:underline">{{ $result->url }}</a>
                                        @endif
                                        <p class="mt-1 text-xs text-gray-500">{{ $result->observed_at?->format('d/m/Y H:i') }}</p>
                                        <div class="mt-2 flex flex-wrap gap-1">
                                            @foreach ($result->evidence as $evidence)
                                                <span class="rounded bg-gray-200 px-2 py-0.5 text-xs text-gray-700">
                                                    {{ $evidence->type }}: {{ $evidence->value }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    @endforeach

                    <a href="{{ route('investigations.index') }}" class="mt-6 inline-block text-sm text-gray-600 hover:text-gray-900">
                        &larr; {{ __('Voltar') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

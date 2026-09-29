<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ __('Relatório') }} — {{ $investigation->title }}
            </h2>
            <a href="{{ route('investigations.show', $investigation) }}" class="print:hidden text-sm text-gray-600 hover:text-gray-900">
                &larr; {{ __('Voltar') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-4xl sm:px-6 lg:px-8 print:max-w-none">
            <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg print:shadow-none">
                <div class="p-8">
                    <div class="flex items-start justify-between border-b border-gray-200 pb-6">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-900">{{ $investigation->title }}</h1>
                            <p class="mt-1 text-sm text-gray-500">
                                {{ __('Investigação') }} #{{ $investigation->id }} · {{ __('Run') }} #{{ $run->run_number }}
                            </p>
                        </div>
                        <p class="text-sm text-gray-500">{{ now()->format('d/m/Y H:i') }}</p>
                    </div>

                    <section class="mt-6">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('Resumo') }}</h2>
                        <dl class="mt-2 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="font-medium text-gray-500">{{ __('Status') }}</dt>
                                <dd class="text-gray-900">{{ $run->status }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium text-gray-500">{{ __('Créditos') }}</dt>
                                <dd class="text-gray-900">{{ $run->credits_consumed }} / {{ $run->credits_reserved }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium text-gray-500">{{ __('Correlação') }}</dt>
                                <dd class="text-gray-900">{{ $correlation['score'] ?? '—' }} — {{ $correlation['label'] ?? '—' }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section class="mt-8">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('Entradas informadas') }}</h2>
                        <dl class="mt-2 grid grid-cols-2 gap-2 text-sm">
                            @foreach ($run->input_snapshot ?? [] as $key => $value)
                                @if (is_scalar($value))
                                    <dt class="font-medium text-gray-500">{{ $key }}</dt>
                                    <dd class="text-gray-900">{{ $value ?? '—' }}</dd>
                                @endif
                            @endforeach
                        </dl>
                    </section>

                    <section class="mt-8">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('Módulos executados') }}</h2>
                        <ul class="mt-2 list-inside list-disc text-sm text-gray-900">
                            @foreach ($run->sourceRuns as $sourceRun)
                                <li>{{ $sourceRun->sourceDefinition?->name ?? $sourceRun->source_definition_id }}</li>
                            @endforeach
                        </ul>
                    </section>

                    <section class="mt-8">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('Resultados') }}</h2>
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
                    </section>

                    <section class="mt-8">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('Correlação') }}</h2>
                        @if ($correlation)
                            <p class="mt-2 text-sm text-gray-900">
                                {{ __('Score') }}: {{ $correlation['score'] }} ·
                                {{ __('Regra') }}: {{ $correlation['rule_version'] }} ·
                                {{ $correlation['label'] }}
                            </p>
                            <ul class="mt-2 list-inside list-disc text-sm text-gray-700">
                                @foreach ($correlation['signals'] ?? [] as $signal)
                                    <li>{{ $signal['name'] }} (+{{ $signal['weight'] }})</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-2 text-sm text-gray-500">{{ __('Correlação não disponível.') }}</p>
                        @endif
                    </section>

                    <section class="mt-8">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('Falhas e limitações') }}</h2>
                        @foreach ($run->sourceRuns->whereIn('status', ['failed', 'timeout']) as $sourceRun)
                            <p class="mt-2 text-sm text-gray-700">
                                <strong>{{ $sourceRun->sourceDefinition?->name ?? $sourceRun->source_definition_id }}</strong>:
                                {{ $sourceRun->error_message_public ?? $sourceRun->error_code ?? '—' }}
                            </p>
                        @endforeach

                        <p class="mt-4 text-sm text-gray-600">
                            {{ __('Este relatório apresenta sinais públicos compatíveis e não constitui confirmação de identidade. Uma correspondência possível não é identidade confirmada.') }}
                        </p>
                    </section>

                    <p class="mt-8 border-t border-gray-200 pt-4 text-xs text-gray-400">
                        {{ __('Relatório gerado em') }} {{ now()->format('d/m/Y H:i:s') }}.
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>


@php
    $summary = $manifest['summary'] ?? [];
    $sources = $manifest['sources'] ?? [];
    $discoveries = $manifest['discoveries'] ?? [];
    $correlation = $manifest['correlation'] ?? ($correlation ?? null);
    $limitations = $manifest['limitations'] ?? [];
    $failedSources = $manifest['sources_failed'] ?? [];
    $manifestHash = $run->report?->content_hash;
    $duration = static fn (?int $ms) => $ms === null ? '—' : number_format($ms / 1000, 2, ',', '.').' s';
@endphp

<x-dynamic-component :component="$layout ?? 'app-layout'">
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold leading-tight text-mist-100">
                {{ __('Relatório') }} — {{ $investigation->title }}
            </h2>
            <a href="{{ $backUrl ?? route('investigations.show', $investigation) }}" class="print:hidden text-sm text-mist-300 hover:text-mist-100">
                &larr; {{ __('Voltar') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-4xl sm:px-6 lg:px-8 print:max-w-none">
            <div class="overflow-hidden surface print:shadow-none">
                <div class="p-8">
                    {{-- Cabeçalho: identificação do run e do relatório --}}
                    <div class="flex items-start justify-between border-b border-line pb-6">
                        <div>
                            <h1 class="text-2xl font-bold text-mist-100">{{ $investigation->title }}</h1>
                            <p class="mt-1 text-sm text-mist-400">
                                {{ __('Investigação') }} #{{ $investigation->id }} · {{ __('Run') }} #{{ $run->run_number }}
                                · <x-status-badge :status="$run->status" class="align-middle" />
                            </p>
                            <p class="mt-1 text-sm text-mist-400">
                                {{ __('Início') }}: {{ $run->started_at?->format('d/m/Y H:i:s') ?? '—' }}
                                · {{ __('Conclusão') }}: {{ $run->finished_at?->format('d/m/Y H:i:s') ?? '—' }}
                            </p>
                        </div>
                        <div class="text-right text-sm text-mist-400">
                            <p>{{ __('Relatório gerado em') }} {{ $run->report?->generated_at?->format('d/m/Y H:i:s') ?? now()->format('d/m/Y H:i:s') }}</p>
                            @if ($manifestHash)
                                <p class="text-xs text-mist-500">{{ __('Manifesto') }} {{ $manifest['version'] ?? '—' }} · {{ substr($manifestHash, 0, 12) }}</p>
                            @endif
                        </div>
                    </div>

                    {{-- Resumo em indicadores (mesmos dados do manifesto) --}}
                    <section class="mt-6">
                        <h2 class="text-lg font-semibold text-mist-50">{{ __('Resumo') }}</h2>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <x-stat :label="__('Status')" :value="__((string) $run->status)" />
                            <x-stat :label="__('Descobertas')" :value="$summary['discoveries_total'] ?? 0" :hint="($summary['discoveries_consolidated'] ?? 0) > 0 ? $summary['discoveries_consolidated'].' '.__('consolidadas entre fontes') : null" />
                            <x-stat :label="__('Resultados observados')" :value="$summary['results_total'] ?? 0" />
                            <x-stat :label="__('Fontes')" :value="($summary['sources_completed'] ?? 0).' '.__('concluídas')" :hint="(($summary['sources_failed'] ?? 0) + ($summary['sources_timeout'] ?? 0)).' '.__('com falha/timeout').' · '.($summary['sources_skipped'] ?? 0).' '.__('não aplicáveis')" />
                            <x-stat :label="__('Créditos') " :value="$run->credits_consumed.' / '.$run->credits_reserved" :hint="__('consumidos / reservados')" />
                            <x-stat :label="__('Correlação')" :value="($correlation['score'] ?? '—').(isset($correlation['score_max']) ? '/'.$correlation['score_max'] : '')" :hint="$correlation['label'] ?? '—'" />
                        </div>
                    </section>

                    {{-- Identificadores pesquisados --}}
                    <section class="mt-8">
                        <h2 class="text-lg font-semibold text-mist-100">{{ __('Entradas informadas') }}</h2>
                        <dl class="mt-2 grid grid-cols-2 gap-2 text-sm">
                            @forelse ($manifest['identifiers'] ?? [] as $key => $value)
                                <dt class="font-medium text-mist-400">{{ $key }}</dt>
                                <dd class="text-mist-100">{{ $value }}</dd>
                            @empty
                                <dd class="text-mist-400">{{ __('Nenhum identificador registrado.') }}</dd>
                            @endforelse
                        </dl>
                    </section>

                    {{-- Fontes consultadas e status de cada uma --}}
                    <section class="mt-8">
                        <h2 class="text-lg font-semibold text-mist-100">{{ __('Fontes consultadas') }}</h2>
                        <div class="mt-3 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-mist-400">
                                        <th class="py-2 pr-3">{{ __('Módulo') }}</th>
                                        <th class="py-2 pr-3">{{ __('Status') }}</th>
                                        <th class="py-2 pr-3">{{ __('Versão') }}</th>
                                        <th class="py-2 pr-3">{{ __('Resultados') }}</th>
                                        <th class="py-2 pr-3">{{ __('Tentativas') }}</th>
                                        <th class="py-2 pr-3">{{ __('Duração') }}</th>
                                        <th class="py-2">{{ __('Observação') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($sources as $source)
                                        <tr class="border-b border-line/70 align-top text-mist-100">
                                            <td class="py-2 pr-3">
                                                <span class="font-medium">{{ $source['name'] }}</span>
                                                <span class="block text-xs text-mist-400">{{ $source['slug'] }} · {{ $source['type'] ?? '—' }}</span>
                                            </td>
                                            <td class="py-2 pr-3">{{ $source['status_label'] ?? '—' }}</td>
                                            <td class="py-2 pr-3">{{ $source['tool_version'] ?? '—' }}</td>
                                            <td class="py-2 pr-3">{{ $source['result_count'] ?? 0 }}</td>
                                            <td class="py-2 pr-3">{{ $source['attempts'] ?? 0 }}</td>
                                            <td class="py-2 pr-3">{{ $duration($source['duration_ms'] ?? null) }}</td>
                                            <td class="py-2 text-xs text-mist-300">
                                                {{ $source['error_message_public'] ?? '—' }}
                                                @foreach ($source['warnings'] ?? [] as $warning)
                                                    <span class="block">{{ $warning }}</span>
                                                @endforeach
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>

                    {{-- Descobertas consolidadas (todas as fontes observadoras) --}}
                    <section class="mt-8">
                        <h2 class="text-lg font-semibold text-mist-100">{{ __('Descobertas') }}</h2>
                        @forelse ($discoveries as $discovery)
                            <div class="mt-4 rounded-md bg-ink-850/60 p-4">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <p class="font-medium text-mist-100">{{ $discovery['title'] ?? $discovery['value'] }}</p>
                                        <p class="text-xs text-mist-400">
                                            {{ $discovery['entity_type'] ?? '—' }}
                                            @if (! empty($discovery['platform']))
                                                · {{ $discovery['platform'] }}
                                            @endif
                                            · {{ __('observado em') }} {{ \Illuminate\Support\Carbon::parse($discovery['observed_at'] ?? now())->format('d/m/Y H:i:s') }}
                                            @if (($discovery['confidence'] ?? null) !== null)
                                                · {{ __('confiança') }}: {{ number_format((float) $discovery['confidence'], 2, ',', '.') }}
                                            @endif
                                        </p>
                                    </div>
                                    <span class="rounded bg-ink-900/70 px-2 py-0.5 text-xs text-mist-300">
                                        {{ count($discovery['sources'] ?? []) > 1
                                            ? __('consolidada em :count fontes', ['count' => count($discovery['sources'])])
                                            : __('fonte única') }}
                                    </span>
                                </div>

                                @if (! empty($discovery['url']) && str_starts_with($discovery['url'], 'http'))
                                    <a href="{{ $discovery['url'] }}" target="_blank" rel="noopener noreferrer" class="mt-2 block break-all text-sm text-signal-300 underline decoration-signal-500/40 hover:text-signal-300">{{ $discovery['url'] }}</a>
                                @endif

                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($discovery['evidence'] ?? [] as $evidence)
                                        <span class="rounded bg-ink-700/70 px-2 py-0.5 text-xs text-mist-300">
                                            {{ $evidence['type'] }}: {{ $evidence['value'] }}
                                        </span>
                                    @endforeach
                                </div>

                                <div class="mt-3 text-xs text-mist-300">
                                    <span class="font-medium">{{ __('Fontes observadoras') }}:</span>
                                    {{ implode(', ', $discovery['sources'] ?? []) }}
                                    @if (count($discovery['observations'] ?? []) > 1)
                                        <ul class="mt-1 list-inside list-disc">
                                            @foreach ($discovery['observations'] as $observation)
                                                <li>
                                                    {{ $observation['source'] ?? '—' }} —
                                                    {{ $observation['url'] ?? '—' }}
                                                    ({{ \Illuminate\Support\Carbon::parse($observation['observed_at'] ?? now())->format('d/m/Y H:i') }})
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="mt-2 text-sm text-mist-400">{{ __('Nenhuma descoberta registrada neste run.') }}</p>
                        @endforelse
                    </section>

                    {{-- Correlação explicável: fatores, evidências e limitações --}}
                    <section class="mt-8">
                        <h2 class="text-lg font-semibold text-mist-100">{{ __('Correlação') }}</h2>
                        @if ($correlation)
                            <p class="mt-2 text-sm text-mist-100">
                                {{ __('Indicador interno') }}:
                                {{ $correlation['score'] ?? '—' }}{{ isset($correlation['score_max']) ? '/'.$correlation['score_max'] : '' }}
                                · {{ __('Regra') }}: {{ $correlation['rule_version'] ?? '—' }}
                                · {{ $correlation['label'] ?? '—' }}
                            </p>
                            <p class="mt-1 text-xs text-mist-400">
                                {{ __('Este indicador não é probabilidade de identidade nem confirmação de que a mesma pessoa controla as contas.') }}
                            </p>

                            @if (! empty($correlation['factors']))
                                <div class="mt-3 overflow-x-auto">
                                    <table class="min-w-full text-sm">
                                        <thead>
                                            <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-mist-400">
                                                <th class="py-2 pr-3">{{ __('Fator') }}</th>
                                                <th class="py-2 pr-3">{{ __('Peso') }}</th>
                                                <th class="py-2 pr-3">{{ __('Explicação') }}</th>
                                                <th class="py-2 pr-3">{{ __('Evidências') }}</th>
                                                <th class="py-2 pr-3">{{ __('Fontes') }}</th>
                                                <th class="py-2">{{ __('Limitação') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($correlation['factors'] as $factor)
                                                <tr class="border-b border-line/70 align-top text-mist-100">
                                                    <td class="py-2 pr-3">
                                                        <span class="font-medium">{{ $factor['type'] ?? '—' }}</span>
                                                        <span class="block text-xs text-mist-400">{{ $factor['signal'] ?? '' }}</span>
                                                    </td>
                                                    <td class="py-2 pr-3">+{{ $factor['weight'] ?? 0 }}</td>
                                                    <td class="py-2 pr-3 text-xs text-mist-300">{{ $factor['explanation'] ?? '—' }}</td>
                                                    <td class="py-2 pr-3 text-xs text-mist-300">
                                                        {{ implode(', ', $factor['evidence_types'] ?? []) ?: '—' }}
                                                        @if (! empty($factor['evidence_ids']))
                                                            <span class="block text-mist-400">#{{ implode(', #', $factor['evidence_ids']) }}</span>
                                                        @endif
                                                    </td>
                                                    <td class="py-2 pr-3 text-xs text-mist-300">{{ implode(', ', $factor['sources'] ?? []) ?: '—' }}</td>
                                                    <td class="py-2 text-xs text-mist-300">{{ $factor['limitation'] ?? '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif

                            @if (! empty($correlation['not_evaluated']))
                                <p class="mt-3 text-xs text-mist-400">
                                    {{ __('Não avaliados nesta versão') }}: {{ implode(', ', $correlation['not_evaluated']) }}.
                                </p>
                            @endif
                        @else
                            <p class="mt-2 text-sm text-mist-400">{{ __('Correlação não disponível.') }}</p>
                        @endif
                    </section>

                    {{-- Falhas e limitações: nada é escondido para parecer completo --}}
                    <section class="mt-8">
                        <h2 class="text-lg font-semibold text-mist-100">{{ __('Falhas e limitações') }}</h2>

                        @forelse ($failedSources as $source)
                            <p class="mt-2 text-sm text-mist-300">
                                <strong>{{ $source['name'] }}</strong>
                                ({{ $source['status_label'] ?? $source['status'] }}):
                                {{ $source['error_message_public'] ?? $source['error_code'] ?? '—' }}
                            </p>
                        @empty
                            <p class="mt-2 text-sm text-mist-400">{{ __('Nenhuma fonte falhou neste run.') }}</p>
                        @endforelse

                        @if (($summary['sources_pending'] ?? 0) > 0)
                            <p class="mt-2 text-sm text-amber-200">
                                {{ __('Existem fontes ainda não concluídas neste run; os resultados são parciais.') }}
                            </p>
                        @endif

                        @if (collect($sources)->contains(fn ($source) => ($source['slug'] ?? null) === 'holehe'))
                            <p class="mt-4 rounded-md bg-amber-950/30 p-3 text-sm text-amber-100">
                                {{ __('Sobre o módulo Holehe: a indicação de que um e-mail aparece como cadastrado em um serviço é um sinal técnico público (verificação de cadastro, sem recuperação de senha). Isso não prova que a pessoa investigada controla aquela conta.') }}
                            </p>
                        @endif

                        @if (! empty($limitations))
                            <ul class="mt-4 list-inside list-disc text-sm text-mist-300">
                                @foreach ($limitations as $limitation)
                                    <li>{{ $limitation }}</li>
                                @endforeach
                            </ul>
                        @endif

                        <p class="mt-4 text-sm text-mist-300">
                            {{ __('Este relatório apresenta sinais públicos compatíveis e não constitui confirmação de identidade. Uma correspondência possível não é identidade confirmada.') }}
                        </p>
                    </section>

                    <p class="mt-8 border-t border-line pt-4 text-xs text-mist-500">
                        {{ __('Relatório gerado em') }} {{ $run->report?->generated_at?->format('d/m/Y H:i:s') ?? now()->format('d/m/Y H:i:s') }}
                        · {{ __('Run') }} #{{ $run->id }}
                        @if ($manifestHash)
                            · {{ __('hash do manifesto') }}: {{ $manifestHash }}
                        @endif
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-dynamic-component>





<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Investigação') }} #{{ $investigation->id }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
            @include('admin.partials.nav')

            <div class="rounded-lg bg-white p-6 shadow-sm">
                <p class="text-sm text-gray-500">
                    {{ __('Usuário') }}: {{ $investigation->user?->email ?? __('Sem conta') }}
                    @if ($investigation->isGuest())
                        · {{ __('Origem') }}: {{ __('Avulsa (sem conta)') }}
                        · {{ __('Preço avulso') }}: {{ __('R$') }} {{ number_format(((int) $investigation->guest_price_cents) / 100, 2, ',', '.') }}
                        @if ($investigation->guestPayment)
                            · {{ __('Pagamento') }}: {{ __((string) $investigation->guestPayment->status) }}
                            @if ($investigation->guestPayment->creditPackage)
                                ({{ $investigation->guestPayment->creditPackage->name }})
                            @endif
                        @endif
                    @endif
                    · {{ __((string) $investigation->status) }}
                </p>

                <h3 class="mt-4 text-lg font-semibold">{{ __('Execuções') }}</h3>
                @foreach ($investigation->runs as $run)
                    <div class="mt-4 border-t border-gray-100 pt-4">
                        <p class="font-medium">{{ __('Run') }} #{{ $run->run_number }} — {{ __((string) $run->status) }}</p>
                        @foreach ($run->sourceRuns as $sourceRun)
                            <div class="mt-2 text-sm">
                                <div class="flex items-center justify-between">
                                    <span>{{ $sourceRun->sourceDefinition?->name ?? $sourceRun->source ?? $sourceRun->source_definition_id }}</span>
                                    <span class="text-gray-500">
                                        {{ __((string) $sourceRun->status) }}
                                        @if ($sourceRun->error_code) ({{ $sourceRun->error_code }}) @endif
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500">
                                    {{ __('resultados') }}: {{ $sourceRun->result_count }} ·
                                    {{ __('tentativas') }}: {{ $sourceRun->attempts }} ·
                                    {{ __('duração') }}: {{ $sourceRun->duration_ms !== null ? $sourceRun->duration_ms.' ms' : '—' }}
                                    @if ($sourceRun->tool_version)
                                        · {{ __('versão') }}: {{ $sourceRun->tool_version }}
                                    @endif
                                </p>
                                @if ($sourceRun->error_message_public)
                                    <p class="text-xs text-gray-500">{{ $sourceRun->error_message_public }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>

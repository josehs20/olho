<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-mist-100">
            {{ __('Backup do banco') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
            @include('admin.partials.nav')

            @if (session('status'))
                <div class="mb-4 rounded-lg border border-signal-500/40 bg-signal-600/10 px-4 py-3 text-sm text-signal-300">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 rounded-lg border border-rose-500/40 bg-rose-600/10 px-4 py-3 text-sm text-rose-300">
                    {{ session('error') }}
                </div>
            @endif

            <div class="surface p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-lg font-semibold text-mist-100">{{ __('Dump completo (MySQL/MariaDB)') }}</h3>
                    <x-badge :tone="$exists ? 'signal' : 'warning'">
                        {{ $exists ? __('● Arquivo disponível') : __('○ Sem dump gerado') }}
                    </x-badge>
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-2 sm:block sm:col-span-2">
                        <dt class="text-mist-400">{{ __('Caminho interno') }}</dt>
                        <dd class="font-mono text-xs break-all text-mist-100">{{ $path }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 sm:block sm:col-span-2">
                        <dt class="text-mist-400">{{ __('Caminho no repositório') }}</dt>
                        <dd class="font-mono text-xs text-mist-100">{{ $relativePath }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 sm:block">
                        <dt class="text-mist-400">{{ __('Tamanho') }}</dt>
                        <dd class="text-mist-100">
                            @if ($size === null)
                                {{ __('—') }}
                            @elseif ($size < 1024)
                                {{ $size }} B
                            @elseif ($size < 1048576)
                                {{ number_format($size / 1024, 1, ',', '.') }} KB
                            @else
                                {{ number_format($size / 1048576, 1, ',', '.') }} MB
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2 sm:block">
                        <dt class="text-mist-400">{{ __('Gerado em') }}</dt>
                        <dd class="text-mist-100">{{ $modifiedAt?->format('d/m/Y H:i:s') ?? __('—') }}</dd>
                    </div>
                </dl>

                <p class="mt-4 text-xs text-mist-500">
                    {{ __('O Dockerfile restaura este arquivo no boot do container (Railway): após gerar, baixe o init.sql e versione-o no repositório para que ele entre na próxima imagem. Sessões, cache e filas são recriados vazios (apenas a estrutura); os demais dados são preservados.') }}
                </p>

                <div class="mt-6 flex flex-wrap items-center gap-3">
                    <form method="POST" action="{{ route('admin.backup.dump') }}">
                        @csrf
                        <input type="hidden" name="download" value="1">
                        <button type="submit" class="btn btn-primary">
                            {{ __('Gerar e baixar') }}
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.backup.dump') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline">
                            {{ __('Gerar dump') }}
                        </button>
                    </form>

                    @if ($exists)
                        <a href="{{ route('admin.backup.download') }}" class="btn btn-outline">
                            {{ __('Baixar init.sql') }}
                        </a>
                    @endif
                </div>
            </div>

            <div class="mt-6 rounded-lg border border-warn-soft/40 bg-warn-soft/10 px-4 py-3 text-sm text-warn-soft">
                {{ __('O dump contém dados sensíveis (contas, pagamentos, configurações e registros de investigação). Trate o arquivo como segredo: não o publique nem o versione em repositório público.') }}
            </div>
        </div>
    </div>
</x-app-layout>

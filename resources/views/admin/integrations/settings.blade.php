<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-mist-100">
            {{ __('Integrações') }}
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

            {{-- Diretriz de segurança obrigatória (§2.1). --}}
            <div class="mb-6 rounded-lg border border-warn-soft/40 bg-warn-soft/10 px-4 py-3 text-sm text-warn-soft">
                {{ __('Utilize exclusivamente o sessionid de uma conta fantasma/serviço (burner account). Nunca insira tokens de contas pessoais.') }}
            </div>

            {{-- Status da sessão e ação de teste --}}
            <div class="surface mb-6 p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-lg font-semibold text-mist-100">Instagram (instagrapi)</h3>
                    <x-badge :tone="$statusVariant">{{ $statusLabel }}</x-badge>
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-2 sm:block">
                        <dt class="text-mist-400">{{ __('Sessionid em uso') }}</dt>
                        <dd class="font-mono text-xs text-mist-100">{{ $sessionPreview ?? __('— nenhum sessionid salvo') }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 sm:block">
                        <dt class="text-mist-400">{{ __('ds_user_id') }}</dt>
                        <dd class="font-mono text-xs text-mist-100">{{ $dsUserId ?? __('— opcional') }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 sm:block">
                        <dt class="text-mist-400">{{ __('Executor') }}</dt>
                        <dd class="text-mist-100">{{ $adapter === 'mock' ? __('Mock (sem rede)') : __('Processo Python (real)') }}</dd>
                    </div>
                    <div class="flex justify-between gap-2 sm:block">
                        <dt class="text-mist-400">{{ __('Último teste') }}</dt>
                        <dd class="text-mist-100">{{ $lastCheckAt?->format('d/m/Y H:i') ?? __('Nunca testado') }}</dd>
                    </div>
                </dl>

                @if ($lastMessage)
                    <p class="mt-4 rounded-lg border border-ink-700/60 bg-ink-850/50 px-3 py-2 text-xs text-mist-400">
                        <span class="font-semibold">{{ __('Última observação:') }}</span>
                        <span class="break-words">{{ $lastMessage }}</span>
                    </p>
                @endif

                @if (! $hasSession)
                    <p class="mt-4 rounded-lg border border-rose-500/30 bg-rose-600/10 px-3 py-2 text-xs text-rose-200">
                        {{ __('Sem sessionid salvo: o módulo Instagram não consegue autenticar e nenhuma consulta é feita (nenhum crédito é consumido).') }}
                    </p>
                @endif

                <form method="POST" action="{{ route('admin.settings.integrations.test') }}" class="mt-5">
                    @csrf
                    <x-btn type="submit" variant="outline" size="sm">{{ __('Testar conexão') }}</x-btn>
                    <span class="ms-3 text-xs text-mist-500">
                        {{ __('Consulta o perfil @instagram com o cookie salvo. Não gera cobrança nem consome créditos.') }}
                    </span>
                </form>
                <form method="POST" action="{{ route('admin.settings.integrations.update') }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="surface p-6">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-mist-300">{{ __('Executor') }}</h3>
                        <div class="mt-4 max-w-xs">
                            <label for="instagram_driver" class="field-label">{{ __('Driver') }}</label>
                            <select id="instagram_driver" name="instagram_driver" class="field">
                                <option value="process" @selected(old('instagram_driver', $adapter === 'process') === true || old('instagram_driver') === 'process')>{{ __('Processo Python (real)') }}</option>
                                <option value="mock" @selected(old('instagram_driver') === 'mock')>{{ __('Mock (sem rede)') }}</option>
                            </select>
                            <p class="field-hint">
                                {{ __('O modo mock existe para testes determinísticos; com ele nenhuma consulta ao Instagram é feita.') }}
                            </p>
                        </div>
                    </div>

                    <div class="surface p-6">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-mist-300">Instagram</h3>

                        <div class="mt-4">
                            <label for="session_id" class="field-label">{{ __('Sessão (sessionid)') }}</label>
                            <input id="session_id" name="session_id" type="password" autocomplete="off" class="field"
                                   placeholder="{{ $hasSession ? '••••••••••••••••••••••••' : __('Informe o sessionid da conta de serviço') }}">
                            <p class="field-hint">
                                {{ __('Nunca exibimos o sessionid salvo. Deixe em branco para manter o atual.') }}
                            </p>
                            <p class="field-hint">
                                {{ __('Cole apenas o valor do cookie (sem "sessionid=", aspas ou quebra de linha). DevTools → Application → Cookies → instagram.com.') }}
                            </p>
                            @error('session_id') <p class="mt-1 text-sm text-rose-300">{{ $message }}</p> @enderror

                            @if ($hasSession)
                                <label class="mt-2 flex items-center gap-2 text-xs text-mist-400">
                                    <input type="checkbox" name="clear_session" value="1">
                                    {{ __('Remover a sessão salva') }}
                                </label>
                            @endif
                        </div>

                        <div class="mt-4 max-w-xs">
                            <label for="ds_user_id" class="field-label">{{ __('ds_user_id (opcional)') }}</label>
                            <input id="ds_user_id" name="ds_user_id" type="text" autocomplete="off" class="field font-mono text-xs"
                                   value="{{ old('ds_user_id') }}" placeholder="{{ $dsUserId ?? '' }}">
                            <p class="field-hint">
                                {{ __('ID do usuário dono do cookie. Serve para validação extra; não é obrigatório.') }}
                            </p>
                            @error('ds_user_id') <p class="mt-1 text-sm text-rose-300">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3">
                        <x-btn type="submit" variant="primary">{{ __('Salvar configurações') }}</x-btn>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>

<nav class="mb-6 flex flex-wrap gap-2 text-sm">
 <a href="{{ route('admin.dashboard') }}" class="rounded-md bg-ink-800/70 px-3 py-1.5 text-mist-300 hover:bg-ink-700/70">{{ __('Dashboard') }}</a>
 <a href="{{ route('admin.users.index') }}" class="rounded-md bg-ink-800/70 px-3 py-1.5 text-mist-300 hover:bg-ink-700/70">{{ __('Usuários') }}</a>
 <a href="{{ route('admin.packages.index') }}" class="rounded-md bg-ink-800/70 px-3 py-1.5 text-mist-300 hover:bg-ink-700/70">{{ __('Pacotes') }}</a>
 <a href="{{ route('admin.payments.index') }}" class="rounded-md bg-ink-800/70 px-3 py-1.5 text-mist-300 hover:bg-ink-700/70">{{ __('Pagamentos') }}</a>
 <a href="{{ route('admin.settings.payments.edit') }}" class="rounded-md bg-ink-800/70 px-3 py-1.5 text-mist-300 hover:bg-ink-700/70">{{ __('Configurações') }}</a>
 <a href="{{ route('admin.settings.integrations.edit') }}" class="rounded-md bg-ink-800/70 px-3 py-1.5 text-mist-300 hover:bg-ink-700/70">{{ __('Integrações') }}</a>
 <a href="{{ route('admin.investigations.index') }}" class="rounded-md bg-ink-800/70 px-3 py-1.5 text-mist-300 hover:bg-ink-700/70">{{ __('Investigações') }}</a>
 <a href="{{ route('admin.tests.index') }}" class="rounded-md bg-ink-800/70 px-3 py-1.5 text-mist-300 hover:bg-ink-700/70">{{ __('Testes') }}</a>
 <a href="{{ route('admin.sources.index') }}" class="rounded-md bg-ink-800/70 px-3 py-1.5 text-mist-300 hover:bg-ink-700/70">{{ __('Fontes') }}</a>
 <a href="{{ route('admin.audit.index') }}" class="rounded-md bg-ink-800/70 px-3 py-1.5 text-mist-300 hover:bg-ink-700/70">{{ __('Auditoria') }}</a>
 <a href="{{ route('admin.backup.index') }}" class="rounded-md bg-ink-800/70 px-3 py-1.5 text-mist-300 hover:bg-ink-700/70">{{ __('Backup') }}</a>
</nav>

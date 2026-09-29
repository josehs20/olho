<nav class="mb-6 flex flex-wrap gap-2 text-sm">
    <a href="{{ route('admin.dashboard') }}" class="rounded-md bg-gray-100 px-3 py-1.5 text-gray-700 hover:bg-gray-200">{{ __('Dashboard') }}</a>
    <a href="{{ route('admin.users.index') }}" class="rounded-md bg-gray-100 px-3 py-1.5 text-gray-700 hover:bg-gray-200">{{ __('Usuários') }}</a>
    <a href="{{ route('admin.packages.index') }}" class="rounded-md bg-gray-100 px-3 py-1.5 text-gray-700 hover:bg-gray-200">{{ __('Pacotes') }}</a>
    <a href="{{ route('admin.payments.index') }}" class="rounded-md bg-gray-100 px-3 py-1.5 text-gray-700 hover:bg-gray-200">{{ __('Pagamentos') }}</a>
    <a href="{{ route('admin.investigations.index') }}" class="rounded-md bg-gray-100 px-3 py-1.5 text-gray-700 hover:bg-gray-200">{{ __('Investigações') }}</a>
    <a href="{{ route('admin.sources.index') }}" class="rounded-md bg-gray-100 px-3 py-1.5 text-gray-700 hover:bg-gray-200">{{ __('Fontes') }}</a>
    <a href="{{ route('admin.audit.index') }}" class="rounded-md bg-gray-100 px-3 py-1.5 text-gray-700 hover:bg-gray-200">{{ __('Auditoria') }}</a>
</nav>

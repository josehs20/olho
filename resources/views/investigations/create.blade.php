<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            {{ __('Nova investigação') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('investigations.store') }}" class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                @csrf

                <div class="space-y-6 p-6">
                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-700">{{ __('Título (opcional)') }}</label>
                        <input id="title" name="title" type="text" value="{{ old('title') }}"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700">{{ __('Nome') }}</label>
                            <input id="name" name="name" type="text" value="{{ old('name') }}"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="username" class="block text-sm font-medium text-gray-700">{{ __('Username') }}</label>
                            <input id="username" name="username" type="text" value="{{ old('username') }}"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            @error('username')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700">{{ __('E-mail') }}</label>
                            <input id="email" name="email" type="email" value="{{ old('email') }}"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="phone" class="block text-sm font-medium text-gray-700">{{ __('Telefone') }}</label>
                            <input id="phone" name="phone" type="text" value="{{ old('phone') }}"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="city" class="block text-sm font-medium text-gray-700">{{ __('Cidade') }}</label>
                            <input id="city" name="city" type="text" value="{{ old('city') }}"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="state" class="block text-sm font-medium text-gray-700">{{ __('UF') }}</label>
                            <input id="state" name="state" type="text" maxlength="2" value="{{ old('state') }}"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>
                    </div>

                    <div>
                        <p class="text-sm font-medium text-gray-700">{{ __('Módulos') }}</p>
                        @if ($modules->isEmpty())
                            <p class="mt-1 text-sm text-gray-500">{{ __('Nenhum módulo disponível.') }}</p>
                        @else
                            <div class="mt-2 space-y-2">
                                @foreach ($modules as $module)
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" name="modules[]" value="{{ $module->slug }}"
                                            @checked(in_array($module->slug, old('modules', [])))
                                            class="rounded border-gray-300 text-indigo-600">
                                        <span class="text-sm text-gray-700">
                                            {{ $module->name }}
                                            <span class="text-gray-400">({{ $module->cost_credits }} {{ __('créditos') }})</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                        @error('modules')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit"
                        class="inline-flex w-full items-center justify-center rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-700">
                        {{ __('Criar investigação') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>

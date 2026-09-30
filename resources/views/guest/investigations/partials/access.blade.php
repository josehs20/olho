<x-card padding="p-5">
    <p class="eyebrow">{{ __('Acesso') }}</p>
    <p class="mt-2 text-xs leading-relaxed text-mist-400">{{ __('Este endereço contém seu token de acesso. Não compartilhe: qualquer pessoa com o link vê este resultado.') }}</p>
    <p class="mt-3 break-all font-mono text-[0.625rem] text-mist-500">{{ $accessUrl }}</p>
</x-card>

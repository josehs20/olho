@props(['title', 'description' => null])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-dashed border-line bg-ink-900/40 px-6 py-10 text-center']) }}>
    @isset($icon)
        <div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full border border-line text-mist-400">{{ $icon }}</div>
    @endisset

    <p class="font-medium text-mist-100">{{ $title }}</p>

    @if ($description)
        <p class="mx-auto mt-1 max-w-md text-sm text-mist-400">{{ $description }}</p>
    @endif

    @isset($action)
        <div class="mt-4 flex justify-center">{{ $action }}</div>
    @endisset
</div>

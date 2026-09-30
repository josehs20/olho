@props(['eyebrow' => null, 'title', 'description' => null, 'align' => 'left'])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-2 '.($align === 'center' ? 'items-center text-center' : '')]) }}>
    @if ($eyebrow)
        <p class="eyebrow">{{ $eyebrow }}</p>
    @endif

    <h2 class="text-2xl font-semibold tracking-tight text-mist-50 sm:text-3xl">{{ $title }}</h2>

    @if ($description)
        <p class="max-w-2xl text-sm leading-relaxed text-mist-400 sm:text-base">{{ $description }}</p>
    @endif

    @isset($actions)
        <div class="mt-2 flex flex-wrap gap-3">{{ $actions }}</div>
    @endisset
</div>

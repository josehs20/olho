@props(['lines' => 1, 'height' => 'h-4'])

<div {{ $attributes->merge(['class' => 'space-y-2']) }} aria-hidden="true">
    @for ($line = 0; $line < $lines; $line++)
        <div class="skeleton {{ $height }} {{ $line === $lines - 1 && $lines > 1 ? 'w-2/3' : 'w-full' }}"></div>
    @endfor
</div>

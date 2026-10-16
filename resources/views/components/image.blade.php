@php($img = $image)
@if ($img['sources'] !== [])
<picture>
@foreach ($img['sources'] as $source)
    <source type="{{ $source['type'] }}" srcset="{{ $source['srcset'] }}" @if ($img['sizes']) sizes="{{ $img['sizes'] }}" @endif>
@endforeach
    <img {{ $attributes }} src="{{ $img['src'] }}" alt="{{ $img['alt'] }}" @if ($img['width']) width="{{ $img['width'] }}" @endif @if ($img['height']) height="{{ $img['height'] }}" @endif loading="{{ $eager ? 'eager' : 'lazy' }}" decoding="async">
</picture>
@else
<img {{ $attributes }} src="{{ $img['src'] }}" @if ($img['srcset']) srcset="{{ $img['srcset'] }}" sizes="{{ $img['sizes'] }}" @endif alt="{{ $img['alt'] }}" @if ($img['width']) width="{{ $img['width'] }}" @endif @if ($img['height']) height="{{ $img['height'] }}" @endif loading="{{ $eager ? 'eager' : 'lazy' }}" decoding="async">
@endif

@php($media = $resolve((string) ($block['mediaId'] ?? '')))
@if ($media)
<figure class="fsc-block fsc-block--image{{ ($look ?? '') === '' ? '' : ' ' . $look }}">
    <x-content::image :media="$media" :base-url="$baseUrl" :alt="($block['alt'] ?? '') !== '' ? $block['alt'] : ($media['alt'] ?? '')" />
    @if (($block['caption'] ?? '') !== '' || $preview)<figcaption{!! $edit('caption') !!}>{{ $block['caption'] ?? '' }}</figcaption>@endif
</figure>
@endif

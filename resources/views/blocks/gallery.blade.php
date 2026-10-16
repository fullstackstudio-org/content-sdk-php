<div class="fsc-block fsc-block--gallery" style="--fsc-columns: {{ max(1, (int) ($block['columns'] ?? 1)) }}">
@foreach ($block['items'] ?? [] as $item)
    @php($media = $resolve((string) ($item['mediaId'] ?? '')))
    @if ($media)
    <figure class="fsc-gallery__item">
        <x-content::image :media="$media" :base-url="$baseUrl" :alt="($item['alt'] ?? '') !== '' ? $item['alt'] : ($media['alt'] ?? '')" sizes="(min-width: 640px) 33vw, 100vw" />
        @if (($item['caption'] ?? '') !== '')<figcaption>{{ $item['caption'] }}</figcaption>@endif
    </figure>
    @endif
@endforeach
</div>

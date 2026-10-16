<div {{ $attributes->class(['fsc-blocks']) }}>
@foreach ($items as $item)
    @include($item['view'], ['block' => $item['block'], 'index' => $item['index'], 'baseUrl' => $baseUrl, 'resolve' => $mediaFor])
@endforeach
</div>
